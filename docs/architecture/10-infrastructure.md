# 10. 인프라 서비스

## 개요

`src/Infrastructure/`는 확장(Package·Plugin)이 **주입받아 쓰는** 코어 인프라 계층이다. 데이터베이스, 캐시, 파일, 메일, 로그처럼 어느 확장이든 필요로 하는 기반 기능을 코어가 한 번 구현해 두고, 확장은 Provider의 `register()`에서 컨테이너를 통해 주입받는다. 직접 `new` 하거나 자체 구현을 만드는 것이 아니라 "주입해 쓰는 안정 API"라는 위치 — 이것이 `docs/compatibility-policy.md` 안정 API 표의 "인프라 (주입해 쓰는 것)" 행이 말하는 계약이다.

디렉토리는 13개다: `AI`, `Cache`, `Code`, `Cookie`, `Crypto`, `Database`, `Image`, `Log`, `Mail`, `Redis`, `Security`, `Session`, `Storage`. 이 중 `AI`(`src/Infrastructure/AI/AiHttpClient.php` — AI 제공자 HTTP 호출 전용 cURL 클라이언트)는 AI 시스템의 내부 부품이므로 여기서는 존재만 언급하고 [27. AI 시스템](27-ai.md)에서 다룬다.

인프라 계층 전체(`Mublo\Infrastructure\`)는 컨테이너의 Auto Wiring 허용 네임스페이스에 포함되지만([03. Container](03-container.md)), 코어는 대부분의 인프라 서비스를 `src/Core/Provider/ServiceProvider.php`에서 명시적 싱글톤으로 등록한다(Logger 연결, 슬로우 쿼리 임계값 등 조립이 필요하기 때문이다). 확장 개발자는 등록 방식을 신경 쓸 필요 없이 `$c->get(Database::class)`처럼 꺼내 쓰면 된다.

## 데이터 계층

### Database

`src/Infrastructure/Database/Database.php`는 PDO 래퍼다. 안전한 파라미터 바인딩 쿼리 실행, 트랜잭션, 슬로우 쿼리 로깅(임계값은 `.env`의 `DB_SLOW_QUERY_THRESHOLD`, 기본 1.0초)을 제공한다. 대표 메서드는 `select()`, `selectOne()`, `insert()`(lastInsertId 반환), `execute()`(영향 행 수 반환), `transaction(callable)`이다. 확장의 Repository는 이 클래스를 생성자로 주입받는 것이 표준이며, `Mublo\Repository\BaseRepository`를 상속하면 그대로 넘겨주면 된다.

`table()` 메서드가 반환하는 `QueryBuilder`(`src/Infrastructure/Database/QueryBuilder.php`)로 유창한 쿼리 구성이 가능하지만, 호환성 정책은 "Repository의 내부 쿼리 구성(`QueryBuilder` 포함)"을 내부 API로 분류한다. Repository 안에서 사용하는 것은 자유이나, QueryBuilder 자체를 확장의 공개 표면에 노출하지 않는다.

`DatabaseManager`(`src/Infrastructure/Database/DatabaseManager.php`)는 `config/database.php` 기반 연결을 관리하는 싱글톤인데, 정책상 내부 API다. 확장은 `DatabaseManager::getInstance()->connect()` 대신 컨테이너에서 `Database::class`를 주입받는다. 쿼리 실패는 `DatabaseException`(`src/Infrastructure/Database/DatabaseException.php`)으로 던져지며 이것도 안정 API다.

### Cache

`src/Infrastructure/Cache/CacheInterface.php`가 캐시 계약이다: `get()`, `set()`, `has()`, `delete()`, `flush()`, 그리고 캐시 미스 시 콜백으로 생성하는 `remember(string $key, int $ttl, callable $callback)`. `setDomainId()`로 도메인별 키 공간이 분리되는 멀티테넌트 구조다([09. 멀티 도메인](09-multi-domain.md)).

구현체는 두 가지다(`src/Infrastructure/Cache/`).

- `FileCache` — 파일 기반, 기본 드라이버
- `RedisCache` — `config/security.php`의 `cache_driver`가 `redis`이고 연결이 살아 있을 때

드라이버 선택과 Redis 불가 시 파일 캐시로의 자동 fallback은 `CacheFactory`(`src/Infrastructure/Cache/CacheFactory.php`)가 담당한다. 확장은 팩토리를 직접 부르지 말고 컨테이너에서 `CacheInterface::class`를 주입받는다 — 어느 드라이버가 선택됐는지 몰라도 되는 것이 이 계약의 목적이다. `DomainCache`(`src/Infrastructure/Cache/DomainCache.php`)는 도메인 정보 전용 캐시 어댑터로 코어 부팅이 쓰는 내부 부품이다.

`Redis` 디렉토리의 `RedisManager`(`src/Infrastructure/Redis/RedisManager.php`)는 Redis 싱글톤 연결 관리자로, RedisCache와 Redis 세션 핸들러가 공유한다. 확장이 직접 쓸 일은 없다 — Redis가 필요하면 CacheInterface 뒤에서 쓰는 것이다.

### 표준 사용 패턴

Board Package의 Provider가 Repository에 Database를 주입하는 실제 코드다.

```php
// packages/Board/BoardProvider.php — register()
$container->singleton(BoardArticleRepository::class, fn(DependencyContainer $c) =>
    new BoardArticleRepository($c->get(Database::class))
);
```

```php
// packages/Board/Repository/BoardArticleRepository.php
class BoardArticleRepository extends BaseRepository
{
    protected string $table = 'board_articles';
    protected string $entityClass = BoardArticle::class;

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }
}
```

스키마 규약(테이블 생성, `schema_migrations` 추적)은 `docs/dev-guide/database.md`를 따른다.

## 파일·미디어

### Storage — 공개 업로드

`FileUploader`(`src/Infrastructure/Storage/FileUploader.php`)는 웹에서 직접 접근 가능한 파일(게시판 첨부 이미지 등)을 `public/storage/D{domainId}/{subdirectory}/{연}/{월}/` 구조로 저장한다. `upload(UploadedFile $file, int $domainId, array $options)`가 확장자·크기·내용 검증을 거쳐 `UploadResult`를 반환한다. 래스터 이미지 확장자는 `getimagesize()`로 실제 파싱 여부까지 검사해 확장자만 이미지로 위조한 업로드를 차단한다.

`UploadedFile`(`src/Infrastructure/Storage/UploadedFile.php`)은 `$_FILES`를 감싸는 Value Object로, `UploadedFile::fromGlobal('file')` 또는 다중 파일용 `fromGlobalMultiple()`로 생성한다.

### UploadPolicy — 업로드 타입의 단일 진실

`UploadPolicy`(`src/Infrastructure/Storage/UploadPolicy.php`)는 코어 전체의 업로드 allowlist다. `확장자 → 허용 finfo MIME 목록` 맵으로, 목록에 없는 모든 타입은 자동 차단된다. svg·html처럼 인라인 서빙 시 스크립트가 실행될 수 있는 포맷은 의도적으로 목록에 없으므로 관리자를 포함한 어떤 업로드 경로에서도 코어 수준에서 차단된다. `matches($extension, $detectedMime)`는 확장자와 finfo로 감지한 실제 MIME의 일치까지 검증하며, 판별 불가 시 fail-closed로 거부한다. 게시판의 `file_extension_allowed` 같은 기능단 설정은 이 목록 안에서 "좁히기"만 할 수 있고 넓힐 수 없다.

### Storage — 보안 파일

`SecureFileService`(`src/Infrastructure/Storage/SecureFileService.php`)는 민감한 파일(회원 필드 첨부, 주문 서류 등)을 웹 접근 불가 영역 `storage/files/`에 저장하고 HMAC 토큰 기반 다운로드 URL을 만든다. 사용 흐름은 네 단계다.

```php
$result = $secureFile->uploadTemp($file, $domainId, ['max_size' => 5]); // AJAX 임시 업로드
$stored = $secureFile->moveFinal($tempPath, $domainId, 'member-fields', '123'); // 폼 제출 시 최종 이동
$metaJson = $stored->toMetaJson();          // SecureStoredFile → DB 저장용 JSON
$url = $secureFile->generateDownloadUrl($stored->relativePath); // 토큰 URL
```

다운로드는 `GET /download/{token}`으로 `src/Controller/Api/DownloadController.php`가 처리한다. 코어가 모르는 category의 파일이면 `SecureFileAccessEvent`(`src/Core/Event/Storage/SecureFileAccessEvent.php`)를 발행해 권한 판정을 확장에 위임한다 — 파일의 소유 도메인을 아는 것은 그 파일을 저장한 Package/Plugin뿐이기 때문이다. Subscriber가 `grant()`를 호출하면 허용(전파 중단)되고, **아무 subscriber도 허용하지 않으면 관리자만 다운로드할 수 있다**(안전 기본값). 다운로드 성공 시에는 `SecureFileDownloadedEvent`(`src/Core/Event/Storage/SecureFileDownloadedEvent.php`)가 발행돼 다운로드 수 집계 등에 쓸 수 있다. 두 이벤트 모두 호환성 정책의 대표 안정 이벤트다.

`StorageManager`·`StorageInterface`·`LocalStorage`(`src/Infrastructure/Storage/`)는 디스크 추상화 계층으로, 현재 구현체는 로컬 파일시스템 하나다.

### Image

`ImageProcessor`(`src/Infrastructure/Image/ImageProcessor.php`)는 GD 기반 이미지 처리기다. `thumbnail()`, `resize()`, 크롭, 워터마크, 품질 압축을 제공하며 jpg·png·gif·webp를 지원한다. 첨부 이미지 썸네일이 필요한 확장이 `FileUploader`와 함께 주입받는 것이 전형적 조합이다 — Board Package의 Provider가 실제로 이 둘을 함께 주입한다(`packages/Board/BoardProvider.php`).

#### 이미지 포맷 정책 (#39에서 확정)

이미지를 굽는 모든 자리는 같은 답을 따른다 — 자리마다 즉석 판단하지 않는다.

- **GD 는 필수다.** 없으면 업로드·썸네일이 전부 죽으므로 설치 요구사항이다(`EnvironmentChecker` 필수 검사).
- **WebP 는 선택이다(용량 최적화).** WebP 는 별도 PHP 확장이 아니라 GD 의 컴파일 타임 옵션이라 `extension_loaded('gd')` 로는 보이지 않는다 — 능력 확인은 `ImageProcessor::supportsWebp()` 로 한다. 출력 포맷을 고르는 자리는 이 검사로 분기하고, **없으면 PNG 로 폴백한다**(폴백해도 GD 재인코딩의 폴리글랏 방어는 동일). 환경 검사는 이를 **권장** 등급으로 안내하며 설치를 막지 않는다 — 옵션인 것을 필수로 만들면 실패가 설치 차단으로 옮겨갈 뿐이다.
- **확장자는 실제 포맷을 말해야 한다.** PNG 로 구우면서 `.webp` 로 이름 짓지 않는다. 저장 경로 컬럼이 실제 포맷을 들고 다닌다.
- **폴백은 조용하면 안 되고, 역량 부재와 데이터 손상을 같은 실패로 뭉개지 않는다.** 서버 설정 문제(관리자가 고칠 수 있음)와 파일 손상(킷/업로드 문제)은 고치는 사람이 다르므로 로그에서 구분한다(`BlockKitScreenshot` 참조 구현).
- **블록 킷 썸네일의 단일 진실은 킷 JSON 의 `screenshot`(data URI)이다.** 정적 이미지 사본을 손으로 관리하지 않는다 — 설치 마법사는 킷 JSON 에서 추출하고, 대시보드 위젯·블록 킷 화면은 시더가 구운 산출물(`block_kits.screenshot_path`)을 쓰며, 굽기에 실패했던 킷은 보관함 목록 조회 시 자가 복구된다.

## 통신

### Mail

`Mailer`(`src/Infrastructure/Mail/Mailer.php`)는 `config/mail.php` 설정에 따라 PHP `mail()` 또는 SMTP(fsockopen, TLS/SSL)로 발송한다. `send(MailMessage $message)`가 기본이고, 단건 편의 메서드 `sendTo()`, 템플릿 발송 `sendTemplate()`, 대량 발송 `sendBulk()`가 있다. 메시지 구성은 `MailMessage`(`src/Infrastructure/Mail/MailMessage.php`)가 담당한다. 알림 이메일을 보내는 확장(예: `plugins/EmailNotify`)이 주입받는다.

### Log

`Logger`(`src/Infrastructure/Log/Logger.php`)는 파일 기반, PSR-3 호환 로거다. `storage/logs/D{domainId}/{channel}/{날짜}.log` 구조로 도메인별·채널별·일별로 분리되며, 도메인 미설정 시 `_system/`에 쓴다. `channel('payment')->info(...)`처럼 채널을 전환해 확장 전용 로그 파일을 만들 수 있다. `debug`/`info`/`warning`/`error`/`critical` 레벨과 최소 레벨 필터링을 지원한다. 결제·외부 API 연동처럼 사후 추적이 필요한 확장이라면 반드시 주입받아야 할 서비스다.

## 보안 기반

### Crypto

암호화는 두 계층이다.

- `EncryptionService`(`src/Core/Crypto/EncryptionService.php`) — 확장이 쓰는 공개 암호화 헬퍼. AES-256-GCM 인증 암호화, 키는 `config/security.php`의 `encryption.key`. PG 시크릿·외부 API 토큰처럼 평문이 DB에 남으면 안 되는 값에 `encrypt()`/`decrypt()`를 쓴다. `Mublo\Core\Crypto\*`로 안정 API다.
- `CryptoManager`(`src/Infrastructure/Crypto/CryptoManager.php`) — 코어 내부용(DB 비밀번호 암호화, 쿠키, CSRF 토큰 생성). 호출자가 키를 직접 넘기는 저수준 API이며 안정 API 목록에 없으므로 확장은 `EncryptionService`를 쓴다.

### Security

`RateLimiter`(`src/Infrastructure/Security/RateLimiter.php`)는 `CacheInterface` 기반 고정 윈도우 레이트 리미터다. `attempt(string $key, int $maxAttempts, int $windowSeconds)`가 시도 1회를 소비하고 허용 여부를 반환한다. 공개(비인증) 엔드포인트의 남용 방지가 용도이며, 캐시 장애 시 fail-open(요청 허용 + 로깅)이다 — 방어층이지 인증 경계가 아니다.

`CsrfManager`(`src/Infrastructure/Security/CsrfManager.php`)는 세션 기반 CSRF 토큰의 생성·검증을 담당하고, 실제 강제는 `CsrfMiddleware`(`src/Core/Middleware/CsrfMiddleware.php`)가 한다. GET/HEAD/OPTIONS 외 모든 요청에서 토큰을 검증하며, 실패 시 AJAX면 419 JSON, 아니면 만료 안내 뷰를 반환한다. PG 콜백처럼 외부 서버가 호출하는 경로는 Plugin/Package가 `boot()`에서 `addExcludePath()`로 예외 등록한다.

두 계층이 결합된 실례가 에디터 이미지 업로드 API다. `POST /api/v1/editor/upload`(`src/Core/App/Router.php`에 등록, 처리는 `src/Controller/Api/EditorUploadController.php`)는 CSRF 미들웨어를 경유하므로 `X-CSRF-Token` 헤더가 필요하고, 이미지 확장자만 허용(UploadPolicy 안에서 좁히기, 최대 5MB)하며, 비인증 접근이 가능하므로 `RateLimiter`로 IP당 10분에 60회로 제한한다.

### Session·Cookie

`SessionManager`(`src/Infrastructure/Session/SessionManager.php`)는 공개 계약 `Mublo\Core\Session\SessionInterface`의 구현체다. `config/security.php`의 `session_driver`에 따라 파일 또는 Redis(`src/Infrastructure/Session/RedisSessionHandler.php`) 드라이버로 동작하고, 도메인별 세션 분리·플래시 메시지·유휴 타임아웃 슬라이딩을 제공한다. 확장은 구현체가 아니라 `SessionInterface`를 타입 힌트로 주입받는다 — 정책상 `SessionManager` 자체는 내부 API다.

`CookieManager`(`src/Infrastructure/Cookie/CookieManager.php`)는 `Mublo\Core\Cookie\CookieInterface` 구현체로, `config/security.php`의 쿠키 정책(prefix, HttpOnly, Secure, SameSite)을 일괄 적용해 쿠키를 설정·조회·삭제한다. 직접 `setcookie()`를 부르면 이 정책을 우회하게 되므로 쿠키가 필요하면 이 계층을 쓴다.

### Code

`CodeGenerator`(`src/Infrastructure/Code/CodeGenerator.php`)는 `unique_codes` 테이블 중앙 관리형 유니크 코드 생성기다. `generate('menu')`(랜덤), `orderNumber()`(날짜 접두 주문번호), `couponCode()`, `inviteCode()`, `sequential('invoice', 'INV-')`(순번)를 제공하며, `Context`에서 도메인 ID를 얻어 도메인별로 자동 분리된다. 주문번호·쿠폰 코드가 필요한 확장이 자체 구현 대신 주입받는다.

## 경계 — 안정 API와 내부

`docs/compatibility-policy.md`의 안정 API 표(CI의 `tools/check-extension-api.php`가 강제)에 오른 인프라 심볼은 다음과 같다.

| 안정 API | 내부 API (버전 간 변경 가능) |
|---|---|
| `Infrastructure\Database\Database`, `DatabaseException` | `QueryBuilder`(내부 쿼리 구성), `DatabaseManager` 싱글톤 |
| `Cache\CacheInterface` | `CacheFactory`, `FileCache`/`RedisCache` 구체 클래스, 캐시 키 내부 형식 |
| `Storage\*` (SecureFileService, UploadPolicy, FileUploader, UploadedFile 등 전부) | — |
| `Image\ImageProcessor` | — |
| `Mail\*` (Mailer, MailMessage) | — |
| `Log\Logger` | — |
| `Security\RateLimiter` (fail-open 특성이 계약의 일부 — 인증 경계 아님) | — |
| `Code\CodeGenerator` | — |
| `Mublo\Core\Crypto\*` (EncryptionService), `Mublo\Core\Session\SessionInterface`, `Mublo\Core\Cookie\CookieInterface`, `Mublo\Core\Middleware\*` | `Infrastructure\Crypto\CryptoManager`, `SessionManager`·`CookieManager` 구체 클래스, `Security\CsrfManager`, `Redis\*`, `AI\*` |

오른쪽 열도 사용 자체가 금지된 것은 아니지만 업그레이드 호환성이 보장되지 않는다. 원칙은 하나다 — **인터페이스·안정 클래스를 컨테이너에서 주입받고, 구체 구현과 정적 싱글턴에 직접 의존하지 않는다.**

## 관련 문서

- [03. Container](03-container.md) — Auto Wiring 허용 네임스페이스에 `Mublo\Infrastructure\`가 포함되는 근거와 주입 규칙
- [08. Event](08-event.md) — `SecureFileAccessEvent` 구독 방법
- [15. Public API](15-public-api.md) — 안정 API와 내부 구현의 경계 전체
- `docs/dev-guide/database.md` — 테이블 규약·마이그레이션
- `docs/compatibility-policy.md` — 안정 API 표의 원본
