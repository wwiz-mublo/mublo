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

**클래스를 import 하지 않아도 소유는 갈린다.** 뷰나 설정 파일을 경로 문자열로
읽어 훑는 테스트도 마찬가지다. `MUBLO_ROOT_PATH . '/packages/Board/views/...'`
를 읽는 테스트는 Board 를 떼면 깨지므로 Board 소유다. 확장을 가리지 않는
와일드카드 스캔(`glob('/packages/*/manifest.json')`)이라면 코어에 남는다.

`TestOwnershipBoundaryTest` 가 두 경로를 모두 검사한다.

| 검사 | 잡는 것 | 예외 목록 |
|---|---|---|
| `testRootTestsDoNotReferenceExtensionClasses` | `use Mublo\Packages\{Pkg}\…` | `CORE_OWNED_EXCEPTIONS` |
| `testRootTestsDoNotReadExtensionFilesByPath` | `'packages/{Pkg}/…'`, `MUBLO_PACKAGE_PATH . '/{Pkg}…'` | `FIXTURE_PATH_EXCEPTIONS` |

### 새 확장에 테스트 추가하기

1. `{확장}/tests/bootstrap.php` 에 `require_once __DIR__ . '/../../../tests/Bootstrap.php';`
2. `composer.json` 의 `autoload-dev.psr-4` 에 `"Tests\\{확장}\\": "{경로}/tests/"` 추가
3. 테스트 네임스페이스는 `Tests\{확장}\Unit\{하위경로}` (실 DB 를 쓰면 `…\Integration\…`)

플러그인은 `plugins/*/tests` 를 와일드카드로 수집하므로 `phpunit.xml.dist` 를 건드릴
필요가 없다. 패키지는 기본 패키지 Board·Shop 만 기본 실행에 들어간다. 여기 없는
패키지는 경로를 지정해 돌린다.

```bash
php vendor/bin/phpunit packages/{이름}/tests
```

## 테스트 실행

```bash
# 전체 테스트
composer test

# 게이트 전체 + 테스트
composer check

# 특정 테스트만
vendor/bin/phpunit --filter=DispatcherTest

# 특정 스위트만
vendor/bin/phpunit --testsuite=Unit
```

`composer check` 가 도는 게이트는 다음과 같다. 모두 CI 에서도 같은 순서로 돈다.

| 게이트 | 잡는 것 |
|---|---|
| `check-security` | 운영 의존성의 알려진 취약점 |
| `check-di` | Service/Controller 의 직접 인스턴스 생성 |
| `check-extension-api` | 확장이 안정 API 밖 코어 심볼에 의존 |
| `check-strict-types` | 런타임 파일의 `declare(strict_types=1)` 누락 |
| `analyse` | 전 범위 PHPStan level 0 — 없는 클래스·인터페이스·`$this` 메서드 |
| `analyse-strict` | 정리된 범위 PHPStan level 3 — 아무 객체에나 없는 메서드 호출 |

`analyse` 와 `analyse-strict` 는 목적이 다르다. 앞은 뷰와 전 패키지를 얕게 보고,
뒤는 코어·Board·Shop·플러그인만 깊게 본다(`phpstan-strict.neon.dist`). level 0 은
`$this` 호출만 보므로 다른 객체에 없는 메서드를 부르는 종류를 놓친다 — 그 종류의
런타임 오류가 실제로 여럿 있었다. 범위를 넓히려면 strict 설정의 `paths` 에
디렉토리를 더하고 0건이 될 때까지 고친다.

## 통합 테스트 (실 DB)

`tests/Integration/` 과 `{확장}/tests/Integration/` 은 실제 MySQL/MariaDB 를 태운다.
**환경변수가 없으면 스킵되므로**, 설정하지 않아도 나머지 테스트는 정상 실행된다.
확장의 통합 테스트는 그 확장이 소유한다 — 루트 `tests/` 에 두면
`TestOwnershipBoundaryTest` 가 잡는다.

왜 필요한가: mock 은 쿼리를 실행하지 않는다. 컬럼명·JOIN·문자열 함수의 오류는
SQL 이 실제로 돌아야만 드러나고, 정적분석으로도 잡히지 않는다. 실제로 카테고리
경로 갱신이 `strlen`(바이트) 과 `SUBSTRING`(문자) 의 단위 차이 때문에 한글
카테고리명에서 하위 경로를 통째로 잘라 먹고 있었다 — 통합 테스트를 붙이고서야
드러났다.

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
