# 테스트


## 테스트 구조

**루트 `tests/` 는 코어 전용이다.** 확장(Package/Plugin)의 테스트는 그 확장이 소유한다.
확장을 떼어내면 테스트도 함께 떨어져 나가야 하기 때문이다.

```
tests/                     # 코어만
├── Bootstrap.php          # 경로 상수, 환경 로딩
├── TestCase.php           # 기본 테스트 클래스
├── Unit/                  # 단위 테스트
│   ├── Core/              # App, Container, Context, Event, Http, Registry, Response
│   ├── Entity/            # Balance 등 코어 Entity
│   ├── Infrastructure/    # QueryBuilder 보안, 캐시, 스토리지
│   ├── Repository/        # 코어 Repository
│   ├── Service/           # AdminMenu, Auth, Balance, Block, Member, Settings, AI
│   └── Rendering/         # 프레임·프론트 뷰 렌더링
├── Feature/               # 기능 테스트
│   └── Http/              # 라우팅, 요청/응답 흐름
└── Integration/           # 통합 테스트 (실 DB)

packages/{Pkg}/tests/      # 패키지 소유
├── bootstrap.php          # 루트 tests/Bootstrap.php 재사용
├── TestCase.php           # (선택) 패키지 전용 픽스처 헬퍼
├── Unit/
└── Feature/

plugins/{Plugin}/tests/    # 플러그인 소유
├── bootstrap.php          # 루트 tests/Bootstrap.php 재사용
└── Unit/
```

소유 판정 기준은 **테스트 대상 클래스의 네임스페이스** 하나다.

| 대상 | 소유 |
|---|---|
| `Mublo\Core\*`, `Mublo\Service\*`, `Mublo\Infrastructure\*` … | 루트 `tests/` |
| `Mublo\Packages\{Pkg}\*` | `packages/{Pkg}/tests/` |
| `Mublo\Plugin\{Plugin}\*` | `plugins/{Plugin}/tests/` |

확장을 소재로 쓰지만 검증 대상이 코어 로직인 테스트(예: `ExtensionCompatibilityTest`)는
코어에 남는다. "이 확장을 지우면 이 테스트도 지워져야 하나?" 로 판단한다.

### 새 확장에 테스트 추가하기

1. `{확장}/tests/bootstrap.php` 에 `require_once __DIR__ . '/../../../tests/Bootstrap.php';`
2. `composer.json` 의 `autoload-dev.psr-4` 에 `"Tests\\{확장}\\": "{경로}/tests/"` 추가
3. 테스트 네임스페이스는 `Tests\{확장}\Unit\{하위경로}`

`phpunit.xml.dist` 는 `packages/*/tests`, `plugins/*/tests` 를 와일드카드로 수집하므로
건드릴 필요가 없다.

## 테스트 실행

```bash
# 전체 테스트
composer test

# DI·확장 API·정적 분석·테스트 전체
composer check

# 특정 테스트만
vendor/bin/phpunit --filter=DispatcherTest

# 특정 스위트만
vendor/bin/phpunit --testsuite=Unit
```

## 통합 테스트 (실 DB)

`tests/Integration/` 은 실제 MySQL/MariaDB 를 태운다. **환경변수가 없으면 스킵되므로**, 설정하지 않아도 나머지 테스트는 정상 실행된다.

왜 필요한가: 리포지토리 테스트가 전부 `createMock(Database::class)` 라서 SQL 이 실행된 적이 없었다. 존재하지 않는 `QueryBuilder::selectRaw()` 를 부르는 코드가 오래 남아 쇼핑몰 카테고리 삭제를 깨뜨리고 있었고, 잘못된 컬럼명·JOIN 은 정적분석으로도 잡히지 않는다.

```bash
# Dev Container 안에서 (compose.yaml 의 mariadb 서비스)
DB_TEST_HOST=mariadb DB_TEST_USER=test DB_TEST_PASS=test \
  vendor/bin/phpunit --testsuite Integration

# 로컬 MySQL/MariaDB 를 쓸 때
DB_TEST_USER=root DB_TEST_PASS=비밀번호 vendor/bin/phpunit --testsuite Integration
```

| 변수 | 기본값 |
|---|---|
| `DB_TEST_HOST` | `127.0.0.1` |
| `DB_TEST_PORT` | `3306` |
| `DB_TEST_USER` | (없으면 스킵) |
| `DB_TEST_PASS` | `''` |
| `DB_TEST_NAME` | `mublo_integration_test` |

전용 데이터베이스를 만들어 쓰고, 각 테스트가 자기 테이블을 정의한 뒤 `tearDown()` 에서 지운다. 운영 DB 를 건드리지 않는다.

새 통합 테스트는 `Tests\Integration\DatabaseTestCase` 를 상속하고 `createTable()` / `seed()` / `fetchAll()` 을 쓴다.

일반 CI 는 `mariadb:11` 서비스 컨테이너를 띄워 항상 실행한다.

공식 DB 하한 호환성은 별도 스모크 검사로 검증한다. 이 검사는 테스트 DB의 기존
테이블을 모두 지우므로 `DB_TEST_NAME`에 반드시 `test`가 포함된 전용 DB만 사용한다.

```bash
DB_TEST_USER=root DB_TEST_PASS=비밀번호 DB_TEST_NAME=mublo_compatibility_test \
  composer check-db-compat
```

CI 는 PHP 8.2에서 MySQL 5.7·8.4와 MariaDB 10.3 컨테이너 각각에 Core 신규 설치,
공개 Package/Plugin 전체 마이그레이션, 016 부분 적용 복구를 실행한다. 연결 실패나
버전 미달은 PHPUnit skip으로 처리하지 않고 즉시 실패한다.

## PHPUnit 설정

`phpunit.xml`:

```xml
<testsuites>
    <!-- 코어 -->
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory>tests/Feature</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory>tests/Integration</directory>
    </testsuite>
    <!-- 확장 (와일드카드 수집 — 신규 확장 추가 시 수정 불필요) -->
    <testsuite name="Packages">
        <directory>packages/*/tests/Unit</directory>
        <directory>packages/*/tests/Feature</directory>
    </testsuite>
    <testsuite name="Plugins">
        <directory>plugins/*/tests/Unit</directory>
        <directory>plugins/*/tests/Feature</directory>
    </testsuite>
</testsuites>
```

환경: `APP_ENV=testing`, `APP_DEBUG=true`

## TestCase 기본 클래스

`tests/TestCase.php`

모든 테스트가 상속하는 기본 클래스입니다.

```php
namespace Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class TestCase extends PHPUnitTestCase
{
    // DI 컨테이너 접근
    protected function getContainer(): DependencyContainer { ... }

    // 임시 파일 경로
    protected function getTempPath(): string { ... }

    // tearDown 시 임시 파일 정리
    protected function cleanupTemp(): void { ... }
}
```

## 테스트 작성 패턴

### Unit 테스트 — Service, Entity

외부 의존성 없이 클래스의 로직만 테스트합니다.

```php
namespace Tests\Unit\Core\App;

use Tests\TestCase;
use Mublo\Core\App\Dispatcher;

class DispatcherTest extends TestCase
{
    public function testDispatchInjectsParamsAndContext(): void
    {
        $container = $this->getContainer();
        $dispatcher = new Dispatcher($container);

        // ... 테스트 로직 ...

        $this->assertInstanceOf(AbstractResponse::class, $response);
    }

    public function testDispatchRejectsNonPublicMethod(): void
    {
        $this->expectException(HttpNotFoundException::class);

        // private 메서드 호출 시도 → 예외
    }
}
```

### Integration 테스트 — Repository, DB

실제 데이터베이스 연결이 필요한 테스트입니다.

```php
namespace Tests\Integration;

use Tests\TestCase;

class DatabaseTest extends TestCase
{
    public function testDatabaseConnection(): void
    {
        $db = $this->getContainer()->get(Database::class);
        $this->assertNotNull($db);
    }
}
```

### Feature 테스트 — HTTP 흐름

전체 요청/응답 흐름을 테스트합니다.

```php
namespace Tests\Feature\Http;

use Tests\TestCase;

class RoutingTest extends TestCase
{
    public function testRouterClassExists(): void
    {
        $this->assertTrue(class_exists(Router::class));
    }
}
```

## TDD 워크플로우

```
1. 테스트 작성 (Red)   → 실패하는 테스트 먼저 작성
2. 코드 구현 (Green)   → 테스트를 통과하는 최소한의 코드
3. 리팩토링 (Refactor) → 코드 정리 (테스트는 계속 통과)
```

## DI 위반 검사

```bash
composer check-di
```

`tools/check-di-violations.php`가 Controller에서 Service를 건너뛰고 Repository를 직접 사용하는 등의 계층 위반을 검사합니다.

---

[< 이전: 플러그인 만들기](plugin-development.md) | [다음: 기여 가이드 >](contributing.md)
