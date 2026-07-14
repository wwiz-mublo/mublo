# 30. Plugin Guide

독립 Plugin과 종속 Plugin을 현재 런타임 규약에 맞춰 만드는 실전 가이드다. 독립 Plugin의 근거 구현은 `plugins/Faq/`, 종속 Plugin의 근거 구현은 `packages/Board/Plugins/BoardReport/`다. 개념과 로딩 규칙은 [12. Plugin](12-plugin.md)을 전제로 한다.

## 어떤 확장 형식을 선택할까

| 변경 목적 | 현재 확장 지점 | 실물 예 |
|---|---|---|
| HTML 구조·CSS 표현만 교체 | 프레임/콘텐츠/블록 스킨 | `views/Front/`, `packages/Board/views/Front/` |
| 블록 에디터의 콘텐츠 타입 추가 | `BlockRegistry::registerContentType()` | Faq의 `faq`, Banner의 `banner` |
| 특정 Package와 무관한 기능 | `plugins/{Name}/` 독립 Plugin | `plugins/Faq/` |
| 특정 Package의 공개 API·Event에 결합 | `packages/{Package}/Plugins/{Name}/` 종속 Plugin | `packages/Board/Plugins/BoardReport/` |
| 독립 업무 영역과 자체 Plugin 생태계 | Package | `packages/Board/` |

단순 화면 표현 변경에 PHP Plugin을 만들 필요는 없다. 반대로 데이터·라우트·권한·이벤트 처리가 필요하면 스킨보다 Plugin이 맞다.

## A. 독립 Plugin — Faq 기준

### A.1 실제 구조

```text
plugins/Faq/
├── FaqProvider.php
├── manifest.json
├── routes.php
├── AdminMenuSubscriber.php
├── Block/
├── Controller/
├── Service/
├── Repository/
├── database/
└── views/
```

`ExtensionService::scanManifests()`가 `plugins/*/manifest.json`을 발견하고, `ExtensionManager::loadPlugin()`은 디렉토리 `Faq`에서 `Mublo\Plugin\Faq\FaqProvider`를 찾는다. Provider가 없는 단순 Plugin도 목록과 라우트는 사용할 수 있지만 서비스 등록·이벤트 구독·설치 훅은 실행할 Provider가 없다.

### A.2 Manifest

`plugins/Faq/manifest.json`의 실제 핵심 필드는 다음과 같다.

```json
{
    "name": "Faq",
    "label": "FAQ",
    "version": "1.0.0",
    "vendor": "mublo",
    "type": "plugin",
    "requires": {
        "core": ">=1.0.0"
    }
}
```

운영자 플래그(`default`/`mandatory`/`super_only`)는 개발·배포 단계에서 설정하지
않는다 — 배포의 운영 정책이며 운영자가 소비한다 ([13. Manifest](13-manifest.md)).

이름은 디렉토리가 진실이고 `vendor`는 표시용 전역 ID 구성에 사용된다. 새 Plugin의 버전 범위는 실제 검증 결과에 맞춰 작성한다.

### A.3 Provider와 연결

Faq의 `register()`는 Repository·Service·Controller·블록 렌더러를 컨테이너에 등록한다. `boot()`는 다음 연결을 수행한다.

- `EventDispatcher::addSubscriber(new AdminMenuSubscriber())`
- `ContractRegistry::bind(FaqQueryInterface::class, FaqService)`
- `BlockRegistry::registerContentType(type: 'faq', ...)`

새 블록 콘텐츠 타입의 관리자 설정은 `adminScript`가 현재 권장 경로다. Faq가 넘기는 `configFormClass`는 기존 호환 구현이며 `ConfigFormInterface` 자체는 deprecated다([17. 블록 시스템](17-block-system.md)).

### A.4 라우트와 설치

`plugins/Faq/routes.php`는 `PrefixedRouteCollector`로 프론트 `/faq/...`, 관리자 `/admin/faq/...` 경로를 등록한다. 관리자 경로에는 실제 코드처럼 `AdminMiddleware::class`를 지정한다.

Faq는 `InstallableExtensionInterface`를 구현한다. 활성화 과정에서 런타임이 `plugins/Faq/database/migrations`를 먼저 실행한다. Faq의 `install()`도 같은 Migration을 호출하지만 `schema_migrations` 이력으로 재실행에 안전하고, 이어서 프론트 메뉴를 멱등으로 등록한다. `uninstall()`은 메뉴만 제거하고 FAQ 데이터는 보존한다.

배포 디렉토리를 `plugins/Faq`와 같은 구조로 `plugins/{Name}`에 배치한 뒤 관리자 확장 화면에서 현재 도메인에 활성화한다. 현재 저장소의 확장 관리 컨트롤러는 발견된 확장의 활성 상태와 lifecycle을 처리하며, 별도의 ZIP 설치 API는 제공하지 않는다.

## B. 종속 Plugin — BoardReport 기준

### B.1 발견과 활성 키

실제 배치 규약은 다음과 같다.

```text
위치:         packages/Board/Plugins/BoardReport/
활성 키:      Board/BoardReport
네임스페이스: Mublo\Packages\Board\Plugins\BoardReport
Provider:     ...\BoardReport\BoardReportProvider
```

부모 `BoardProvider`가 `PluginHostInterface`를 구현하므로 이 디렉토리가 발견된다. 부모가 비활성화되었거나 register에 실패하면 `ExtensionManager`가 자식의 register·boot를 건너뛴다.

### B.2 Manifest

`packages/Board/Plugins/BoardReport/manifest.json`은 실제로 다음 의존성을 선언한다.

```json
{
    "name": "BoardReport",
    "label": "게시글 신고",
    "version": "1.0.0",
    "vendor": "mublo",
    "type": "plugin",
    "parent": "Board",
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Board": ">=1.0.0 <2.0.0"
    }
}
```

`parent`는 실제 설치 위치와 일치해야 한다. `ExtensionService`가 `package:Board` 요구를 자동 보완할 수 있지만, 배포 Manifest에는 실제 검증한 부모 버전 범위를 명시한다.

### B.3 부모 접근은 공개 표면으로 제한

Board 종속 Plugin에 허용된 부모 네임스페이스는 다음 세 영역이다.

```text
Mublo\Packages\Board\Contract\Extension\*
Mublo\Packages\Board\Api\DTO\*
Mublo\Packages\Board\Event\*
```

실제 `BoardReportService`는 자기 `BoardReportRepository`와 부모의 `BoardExtensionApiInterface`만 주입받는다. 게시글 조회는 `$this->board->articles()->findAccessibleById()`를 사용하며 Board 내부 Repository·Entity·테이블에 직접 접근하지 않는다. 이 경계는 `php tools/check-extension-api.php`가 검사한다.

### B.4 공식 Event 구독

`BoardReportProvider::boot()`의 실제 등록 형태는 다음과 같다.

```php
$eventDispatcher = $container->get(EventDispatcher::class);
$eventDispatcher->addSubscriber(new AdminMenuSubscriber());
$eventDispatcher->addSubscriber(new ArticleSubscriber(
    $container->get(BoardReportService::class),
    $container->get(AuthService::class)
));
```

부모 Event의 발생 시점과 안정성은 `packages/Board/README.md`가 진실이다. Event payload에서 공개 DTO가 아닌 내부 Entity를 추가로 탐색하거나 부모 Service를 꺼내 쓰지 않는다.

### B.5 자기 데이터와 Migration

BoardReport 전용 테이블은 `packages/Board/Plugins/BoardReport/database/migrations/001_create_board_reports.sql`이 관리한다. `BoardReportProvider::install()`은 다음 키로 Migration을 실행한다.

```text
source = plugin
name   = Board/BoardReport
```

부모 Board 테이블을 ALTER하거나 직접 쓰지 않는다. 부모 데이터 변경은 `BoardExtensionApiInterface`의 Command 표면을 통과한다.

### B.6 테스트

현재 실물 단위 테스트는 `packages/Board/tests/Unit/Plugin/BoardReportServiceTest.php`다. 새 종속 Plugin은 정상 기능뿐 아니라 다음 경계를 고정한다.

- 부모 미활성·실패 시 자식이 로드되지 않음
- `requires["package:Board"]` 불일치 시 활성화 거부
- Migration 실패 시 installed 마킹 보류
- 부모 내부 API 참조가 `check-extension-api`에서 검출됨
- 모든 자기 데이터가 현재 도메인으로 격리됨

## 배포 전 체크리스트

- [ ] 디렉토리명, Provider FQCN, Manifest type이 일치한다.
- [ ] `requires`에는 실제 검증한 Core·부모 Package 범위만 적었다.
- [ ] 관리자 라우트에 권한 Middleware를 지정했다.
- [ ] Migration과 `install()`이 재실행에 안전하다.
- [ ] 비활성화 시 사용자 데이터를 삭제하지 않는다.
- [ ] 종속 Plugin은 부모 Contract·DTO·Event만 참조한다.
- [ ] `php tools/check-extension-api.php`와 관련 PHPUnit 테스트를 통과한다.
- [ ] 배포 규정 `docs/dev-guide/extension-requirements.md`를 확인했다.

## 관련 문서

- [12. Plugin](12-plugin.md) · [13. Manifest](13-manifest.md) · [15. Public API](15-public-api.md)
- 독립 Plugin 원본: `plugins/Faq/README.md`, `plugins/Faq/FaqProvider.php`
- 종속 Plugin 원본: `packages/Board/Plugins/BoardReport/README.md`
- 레퍼런스 해설: [33. Reference Packages](33-reference-packages.md)
