# 05. Router

## 개요

Router는 HTTP 요청의 URL을 분석해 실행할 Controller와 Method를 결정한다. 구현은 `src/Core/App/Router.php`의 `Router` 클래스이며, 매칭 엔진으로 FastRoute를 사용한다(`FastRoute\simpleDispatcher` / `FastRoute\cachedDispatcher`).

Router가 반환하는 것은 실행 결과가 아니라 라우트 정보 배열이다. 실제 Controller 실행은 `src/Core/App/Dispatcher.php`의 `Dispatcher`가 담당한다.

```php
// src/Core/App/Router.php — dispatch()의 반환 형태
return [
    'controller' => $routeInfo[1]['controller'],
    'method'     => $routeInfo[1]['method'],
    'params'     => array_merge($routeInfo[1]['defaults'] ?? [], $routeInfo[2] ?? []),
    'middleware' => $routeInfo[1]['middleware'] ?? [],
];
```

## 책임과 비책임

`src/Core/App/Router.php` 클래스 주석에 명시된 경계다.

- 책임: URL → Controller/Method 매핑, 라우트 파라미터 추출, 미들웨어 정보 전달.
- 비책임(금지): Controller 실행(Dispatcher의 역할), 인증/권한 검사(Middleware의 역할), 비즈니스 로직, HTML 출력.

## 라우트 등록 방식

라우트 테이블은 `Router::createDispatcher()`(`src/Core/App/Router.php`)의 정의 콜백 안에서 세 단계로 구성된다. 순서는 곧 우선권이 아니라 **선점 순서**다 — 나중에 오는 확장은 이미 등록된 경로와 충돌하면 통째로 거부된다(아래 "라우트 충돌과 원자적 등록").

1. **Core 라우트** — `registerCoreRoutes()`. 메인 페이지, 회원/인증, 마이페이지, 관리자 대시보드, `/api/v1/csrf/*`, `/serve/*` 정적 파일 서빙 등 프레임워크 기본 라우트를 코드에 직접 등록한다. Core도 확장과 같은 선언 목록(`$registeredRoutes`)에 기록되므로 확장이 Core 경로를 침범하는지 검증할 수 있다.
2. **Plugin 라우트** — `loadPluginRoutes()`. `plugins/{이름}/routes.php`를 로드한다. 이어서 Package 종속 Plugin의 routes.php도 이 단계에서 로드한다.
3. **Package 라우트** — `loadPackageRoutes()`. `packages/{이름}/routes.php`를 로드한다.

### 확장 routes.php 규약

확장의 `routes.php`는 `PrefixedRouteCollector`(`src/Core/App/PrefixedRouteCollector.php`)를 받는 콜백을 반환해야 한다. 실제 예 — `packages/Board/routes.php`:

```php
return function (PrefixedRouteCollector $r): void {
    // prefix 없이 루트 경로 등록 (addRawRoute)
    $r->addRawRoute('GET', '/community', [
        'controller' => CommunityController::class,
        'method'     => 'index',
    ]);

    // 접두사 자동 적용: /{board_id} → /board/{board_id}
    $r->addRoute('GET', '/{board_id}/view/{post_no:\d+}[/{slug}]', [
        'controller' => BoardController::class,
        'method'     => 'view',
    ]);

    // Admin 라우트: /admin/config → /admin/board/config
    $r->addRoute('GET', '/admin/config', [
        'controller' => BoardConfigController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);
};
```

`PrefixedRouteCollector::applyPrefix()`의 접두사 규칙은 두 가지다. Front 라우트는 `/{prefix}/...`, `/admin`으로 시작하는 라우트는 `/admin/{prefix}/...`가 된다. 접두사는 `Router::buildRoutePrefix()`가 디렉토리명에서 생성한다(`AutoForm` → `auto-form`, PascalCase → kebab-case 소문자). `addRawRoute()`는 접두사를 우회해 원본 경로 그대로 등록하고, `addGroup()`은 FastRoute 그룹을 접두사 적용 후 위임한다.

### 종속 Plugin 라우트

Core는 Package 내부를 직접 스캔하지 않고 `NestedPlugin::discover()`(`src/Core/Extension/NestedPlugin.php`)로 호스트 Package(PluginHostInterface 구현)에게 종속 Plugin 목록을 묻는다(`loadPluginRoutes()` 후반부). 활성 키는 `{Package}/{이름}` 형태이며, URL은 부모 Package에 종속된다. Plugin 이름이 Package 이름으로 시작하면 그 부분은 URL에서 접는다.

- `Board/BoardReport` → `/board/report/...`, `/admin/board/report/...`

FastRoute에서 정적 경로가 변수 경로보다 우선하므로 `/board/report`는 `/board/{board_id}`와 공존한다.

### 라우트 충돌과 원자적 등록

확장의 routes.php는 실제 수집기에 바로 쓰지 않는다. `registerExtensionRoutesStrict()`(`src/Core/App/Router.php`)가 콜백을 `BufferedRouteCollector`로 먼저 실행해 후보 라우트를 모으고, 두 가지 검증을 통과할 때만 실제 수집기에 반영한다.

- `assertNoAdminAutoResolveShadow()` — `/admin/{세그먼트}` 후보가 Core 관리자 autoResolve 예약 이름(아래 allowlist에서 파생)을 선점하거나 첫 세그먼트를 변수(`{...}`)로 잡으면 `BadRouteException`. 예약 경계가 없으면 Core 관리자 화면이 조용히 가려진다.
- `assertRoutesCanBeRegistered()` — 기존 등록분 + 후보를 별도 FastRoute 수집기에 재생해 중복·충돌을 미리 터뜨린다.

검증에 실패하면 그 확장의 후보 전체를 버리고 `recordExtensionRouteFailure()`가 `ExtensionLoadDiagnostics`에 `routes` 단계 실패로 기록한다. 즉 등록은 **확장 단위로 원자적**이며, 충돌 확장 하나가 도메인 전체 라우팅을 중단시키거나 자기 라우트의 절반만 남기지 않는다. 확장 활성화 사전 검증(`validateExtensionConfiguration()`)에서는 같은 절차를 쓰되 실패를 건너뛰지 않고 `RuntimeException`으로 운영자에게 알린다.

## 확장 라우트 게이트 — 활성 확장만 노출

Router는 라우트 테이블을 구성할 때 도메인별로 활성화된 확장의 routes.php만 로드한다. 판단은 `getEnabledPluginNames()` / `getEnabledPackageNames()`(`src/Core/App/Router.php`)가 `ExtensionService::getEnabledPlugins()` / `getEnabledPackages()`(`src/Service/Extension/ExtensionService.php`)를 통해 수행한다. 동작은 fail-closed다.

- 도메인 ID가 없으면 빈 배열 반환 — 확장 라우트를 전혀 로드하지 않는다.
- 활성 목록 조회가 예외로 실패해도 빈 배열 반환 — 비활성 확장 라우트가 노출되는 쪽으로 열리지 않는다.
- 컨테이너 자체가 없을 때만(`null` 반환) 전체 로드한다(하위 호환 경로).

이름 비교는 `isExtensionEnabled()` / `normalizeExtensionName()`이 담당하며 `AutoForm`, `auto-form`, `auto_form`, `autoform`을 동일하게 취급한다. 또한 manifest의 `super_only`가 참인 Plugin은 `isSuperOnlyPluginOnSubSite()`에 의해 루트 도메인이 아닌 하위 도메인에서 라우트가 차단된다.

### 실패 확장 필터 — withoutFailedExtensions

활성 목록에 있어도 이번 요청에서 register/boot/dependency 단계가 실패한 확장의 라우트는 노출하지 않는다. `Router::withoutFailedExtensions()`(`src/Core/App/Router.php`)가 `ExtensionLoadDiagnostics`(`src/Core/Extension/ExtensionLoadDiagnostics.php`)에 기록된 실패 목록과 대조해 해당 이름을 활성 목록에서 걸러낸다.

```php
// src/Core/App/Router.php
return $this->withoutFailedExtensions(
    $extensionService->getEnabledPlugins($domainId),
    'plugin'
);
```

실패 기록은 `ExtensionManager::handleExtensionError()`와 `recordDependencySkip()`(`src/Core/Extension/ExtensionManager.php`)이 남긴다. 부모 Package가 실패하면 종속 Plugin은 `dependency` 단계 실패로 기록되므로, 종속 Plugin의 라우트도 부모의 활성·정상 상태를 따라간다. 영속 활성 설정은 건드리지 않으므로 다음 요청에서 확장이 정상 복구되면 라우트도 다시 로드된다.

## 디스패치 흐름

`Router::dispatch(Request $request, Context $context)`의 처리 순서다.

1. `Context::getDomain()`으로 현재 도메인을 확정한다(캐시 파일 경로 결정용).
2. `createDispatcher()`로 FastRoute Dispatcher를 만든다(프로덕션은 캐시, 개발은 실시간).
3. 경로 끝의 슬래시를 정규화한 뒤(`/products/` → `/products`, 루트 `/` 제외) FastRoute 매칭을 실행한다.
4. `FOUND` — 핸들러 배열에서 controller/method/middleware를 꺼내고, 라우트 파라미터는 핸들러의 `defaults`와 URL 추출값을 병합해 `params`로 반환한다.
5. `NOT_FOUND` — `/assets/`, `/serve/` 경로는 즉시 404. Admin 영역(`Context::isAdmin()`)이면 `autoResolve()`로 자동 매핑, Front는 명시 라우트에 없으면 404다(자동 노출 차단).
6. `METHOD_NOT_ALLOWED` — `405 Method Not Allowed` 예외.

### autoResolve — Admin 한정 자동 매핑

`autoResolve()`는 `/admin/member/edit/123` 같은 URL을 세그먼트 규칙으로 `Mublo\Controller\Admin\MemberController::edit`, `params = ['123']`에 매핑한다. Front에는 적용하지 않는다 — autoResolve는 라우트별 미들웨어를 부여하지 못하므로, Front까지 허용하면 명시 라우트의 AuthMiddleware가 비정규 경로로 우회될 수 있기 때문이다. Admin 매핑에는 `AdminMiddleware`가 자동 적용된다.

매핑 전 검증은 네 겹이다.

1. 클래스·메서드 존재, public·비static 여부를 Reflection으로 확인한다.
2. **대소문자 정확 일치** — PHP는 클래스·메서드 이름의 대소문자를 가리지 않아 `/admin/MEMBER/delete`도 해석돼 버린다. 그런 경로는 어떤 관리자 메뉴 URL에도 매칭되지 않아 메뉴 기반 권한 검사가 무력해지므로, 선언된 이름과 철자가 정확히 같을 때만 통과시킨다(명시 라우트의 대소문자 구분과 판정을 맞춘다).
3. **GET/HEAD allowlist** — 조회용 자동 노출은 `Router::ADMIN_AUTO_RESOLVE_READ_ACTIONS` 상수에 컨트롤러별로 열거된 액션만 허용한다. 목록에 없는 GET 액션은 404다.
4. **HTTP 메서드 제한** — `GET`·`HEAD`·`POST`·`PUT`·`PATCH`·`DELETE` 외(예: `OPTIONS`, `TRACE`)는 Controller로 전달하지 않는다. 상태 변경 메서드는 전역 CSRF 미들웨어를 통과한다.

이 allowlist는 라우트 등록 단계의 예약 이름(`assertNoAdminAutoResolveShadow()`)이기도 하다 — 새 Core 관리자 화면을 autoResolve로 노출하려면 이 상수에 액션을 추가해야 한다.

## 라우트 캐시

`APP_DEBUG=false`(프로덕션)이고 **등록된 도메인**일 때만(`Context::hasDomainInfo()`) `cachedDispatcher`를 사용해 라우트 테이블을 도메인별 파일로 캐시한다. `Host` 헤더는 조작 가능하고 `/serve`·`/csrf` 같은 경로는 도메인 검증을 건너뛰므로, 화이트리스트 없이 캐시하면 임의 Host 대량 전송으로 캐시 파일을 무한 생성해 디스크·inode를 고갈시킬 수 있다. 미등록 도메인은 캐시 없이 매 요청 라우트 테이블을 구성한다. 캐시 파일은 `storage/cache/routes/{domain}.{signature}.cache.php`이며, 시그니처는 `buildRouteCacheSignature()`가 코어 버전(`Application::VERSION`), 활성 Plugin/Package 목록, 각 routes.php의 수정 시간으로 계산한다 — 활성 상태나 라우트 파일이 바뀌면 시그니처가 달라져 자동 재생성된다. 생성 후 1시간이 지난 캐시 파일은 `invalidateStaleCacheFile()`이 삭제한다.

수동 무효화 API(모두 `src/Core/App/Router.php`):

- `$router->clearCache()` — 현재 도메인의 캐시 삭제.
- `Router::clearRouteCache('shop.example.com')` — 특정 도메인의 캐시 삭제(정적).
- `Router::clearAllRouteCache()` — 전체 삭제, 삭제 파일 수 반환(정적).

조회용으로 `isCacheEnabled()`, `cacheFileExists()`, `getCurrentDomain()`, `Router::getCachedDomains()`가 있다.

## 라우트 파라미터와 컨트롤러 호출 규약

FastRoute 플레이스홀더(`{board_id}`, `{post_no:\d+}`, 선택 세그먼트 `[/{slug}]`)로 추출된 값은 라우트 정보의 `params`에 담겨 `Dispatcher`(`src/Core/App/Dispatcher.php`)로 전달된다. `Dispatcher::invokeAction()`은 Controller 메서드의 파라미터를 Reflection으로 분석해 다음 순서로 주입한다.

1. `Request` 타입 파라미터 → 현재 Request 객체
2. `Context` 타입 파라미터 → 현재 Context 객체
3. 이름이 `params`인 파라미터 → 라우트 파라미터 배열 전체
4. 라우트 파라미터에 같은 이름이 있으면 그 값 (예: `public function view(string $board_id)`)
5. 기본값 → 기본값, Nullable → `null`, 그 외 → 예외

따라서 `public function view(Request $request, Context $context, array $params)`처럼 필요한 것만 순서 무관하게 선언하면 된다. Controller는 반드시 Response 객체를 반환해야 한다 — 반환 규약은 [07. Response](07-response.md) 참조.

## 관련 문서

- [02. Core](02-core.md) — Application 부팅 순서와 Router 호출 시점
- [04. Context](04-context.md) — 도메인 해석과 `isAdmin()` 판단
- [06. Request](06-request.md) — Router가 받는 Request 객체
- [07. Response](07-response.md) — Controller 반환 규약
- [14. Extension](14-extension.md) — 확장 로딩 실패와 ExtensionLoadDiagnostics
- 관련 가이드: `docs/dev-guide/routing.md`(라우팅 가이드), `docs/dev-guide/request-lifecycle.md`(요청 생명주기), `docs/dev-guide/architecture.md`(아키텍처)
