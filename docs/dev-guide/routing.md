# 라우팅과 미들웨어

## 라우팅 시스템

`src/Core/App/Router.php`

FastRoute 기반으로, 명시적 라우트 → 자동 매핑 순서로 URL을 Controller에 연결합니다.

관련 문서:
- [요청 흐름](request-lifecycle.md)
- [이벤트 시스템](event-system.md)

### 매칭 우선순위

1. **Core 라우트** — `registerCoreRoutes()`에 정의
2. **Plugin 라우트** — `plugins/{Name}/routes.php`
3. **Package 라우트** — `packages/{Name}/routes.php`
4. **자동 매핑 (autoResolve)** — URL 구조로 Controller 추론

### Core 라우트 (주요)

| URL | Controller | 메서드 |
|-----|-----------|--------|
| `/` | `Front\IndexController` | `index` |
| `/login` | `Front\AuthController` | `loginForm` |
| `/login` (POST) | `Front\AuthController` | `login` |
| `/logout` | `Front\AuthController` | `logout` |
| `/search` | `Front\SearchController` | `index` |
| `/member/register` | `Front\MemberController` | `registerForm` |
| `/mypage/*` | `Front\MypageController` | (AuthMiddleware) |
| `/admin` | `Admin\DashboardController` | `index` (AdminMiddleware) |
| `/admin/login` | `Admin\AuthController` | `loginForm` |
| `/api/v1/csrf/token` | `Api\CsrfController` | `token` |
| `/serve/plugin/{name}/{path}` | `Api\ServeController` | `plugin` |
| `/serve/package/{name}/{path}` | `Api\ServeController` | `package` |

### Plugin / Package 라우트

`routes.php` 파일을 반환하면 자동으로 URL 접두사가 적용됩니다.

```php
// plugins/Banner/routes.php

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\Banner\Controller\BannerController;

return function (PrefixedRouteCollector $r): void {
    // /admin/banner/list
    $r->addRoute('GET', '/admin/list', [
        'controller' => BannerController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // /admin/banner/{id}/edit
    $r->addRoute('GET', '/admin/{id:\d+}/edit', [
        'controller' => BannerController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    // /admin/banner/store (POST)
    $r->addRoute('POST', '/admin/store', [
        'controller' => BannerController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);
};
```

#### URL 접두사 규칙

디렉토리명이 kebab-case로 변환되어 접두사가 됩니다.

| 디렉토리 | 접두사 | 라우트 정의 `/admin/list` → |
|----------|--------|---------------------------|
| `Banner` | `/banner` | `/admin/banner/list` |
| `MemberPoint` | `/member-point` | `/admin/member-point/history` |
| `Board` | `/board` | `/admin/board/list` |
| `Shop` | `/shop` | `/admin/shop/products` |

CamelCase 이름은 kebab-case로 변환됩니다.

- `MemberPoint` → `/member-point`
- `SnsLogin` → `/sns-login`
- `VisitorStats` → `/visitor-stats`

실제 관리자 진입 경로는 각 모듈의 `routes.php` 정의에 따라 달라집니다. 예를 들어:

- `Banner` 목록: `/admin/banner/list`
- `MemberPoint` 내역: `/admin/member-point/history`
- `Shop` 상품 목록: `/admin/shop/products`
- `Shop` 설정: `/admin/shop/config`

#### Raw 라우트 (접두사 없이)

```php
// 접두사를 적용하지 않는 라우트
$r->addRawRoute('GET', '/community', [
    'controller' => CommunityController::class,
    'method'     => 'index',
]);
```

Board 패키지의 `/community` 같이 접두사 없이 등록해야 할 때 사용합니다.

#### 라우트 파라미터

```php
// 숫자만 매칭
$r->addRoute('GET', '/{id:\d+}/edit', [...]);

// 선택적 세그먼트
$r->addRoute('GET', '/{board_id}/view/{post_no}[/{slug}]', [...]);

// 여러 HTTP 메서드
$r->addRoute(['GET', 'POST'], '/admin/action', [...]);
```

### 자동 매핑 (autoResolve)

명시적 라우트에 매칭되지 않으면 URL 구조로 Controller를 추론합니다.

| URL | Controller | 메서드 | 파라미터 |
|-----|-----------|--------|----------|
| `/board/list` | `Front\BoardController` | `list` | `[]` |
| `/board/list/123` | `Front\BoardController` | `list` | `['123']` |
| `/admin/member/edit` | `Admin\MemberController` | `edit` | `[]` |
| `/admin/member/edit/42` | `Admin\MemberController` | `edit` | `['42']` |
| `/admin/board-field/view/1/5` | `Admin\BoardFieldController` | `view` | `['1', '5']` |

#### 변환 규칙

```
URL:  /admin/member-field/edit/42
         ↓
세그먼트: ['admin', 'member-field', 'edit', '42']
         ↓
영역: Admin (admin 세그먼트 제거)
Controller: member-field → MemberFieldController (kebab → PascalCase)
메서드: edit → edit (kebab → camelCase)
파라미터: ['42'] (나머지 세그먼트)
         ↓
결과: Admin\MemberFieldController@edit(['42'])
```

- Admin 영역은 자동으로 `AdminMiddleware`가 적용됩니다
- Front 영역은 미들웨어 없음

### 라우트 캐싱

- **개발 모드** (`APP_DEBUG=true`): 캐싱 없음 (매 요청마다 라우트 재구성)
- **운영 모드** (`APP_DEBUG=false`): 도메인별 캐시 파일 생성

```
storage/cache/routes/example.com.cache.php
storage/cache/routes/shop.example.com.cache.php
```

캐시 TTL은 1시간입니다. 수동 삭제:

```php
Router::clearAllRouteCache();                    // 전체
Router::clearRouteCache('example.com');           // 특정 도메인
```

## 라우팅 전후 이벤트와의 관계

라우팅 자체는 이벤트로 정의되지 않지만, 요청 흐름 앞뒤에 코어 이벤트가 존재한다.

순서:
1. `SiteContextReadyEvent`
2. `RequestInterceptEvent`
3. Router 매칭
4. Dispatcher 실행
5. `RendererResolveEvent` 등 후속 렌더링 이벤트

의미:
- 라우트에 도달하기 전 요청 차단이나 리다이렉트가 필요하면 `RequestInterceptEvent`
- 라우트 결과의 렌더링 방식을 바꾸고 싶으면 `RendererResolveEvent`
- 라우터 내부에 훅을 추가하기보다, 기존 전후 이벤트를 우선 사용

즉 Mublo는 라우터 내부 곳곳에 전역 훅을 심는 구조가 아니라, **요청 흐름의 의미 있는 경계**에서 이벤트를 발행하는 구조다.

## 미들웨어

### MiddlewareInterface

```php
// src/Core/Middleware/MiddlewareInterface.php

interface MiddlewareInterface
{
    public function handle(
        Request $request,
        Context $context,
        callable $next
    ): AbstractResponse;
}
```

### 실행 흐름

```
Request
  → SessionMiddleware (pre)
    → CsrfMiddleware (pre)
      → Controller Action
    ← CsrfMiddleware (post)
  ← SessionMiddleware (post)
Response
```

### 전역 미들웨어

모든 요청에 적용됩니다. `Application::run()`에서 등록됩니다.

| 미들웨어 | 역할 |
|----------|------|
| `SessionMiddleware` | 세션 시작, 도메인별 분리, 슬라이딩 갱신 |
| `CsrfMiddleware` | POST 요청 CSRF 토큰 검증 |

### 라우트 미들웨어

특정 라우트에만 적용됩니다. 라우트 정의에 `middleware` 배열로 지정합니다.

| 미들웨어 | 역할 |
|----------|------|
| `AdminMiddleware` | 로그인 확인 + 관리자 여부 + 메뉴 권한 검사 |
| `AuthMiddleware` | 로그인 확인만 |

### SessionMiddleware

```php
// src/Core/Middleware/SessionMiddleware.php
```

- 도메인 ID로 세션 이름을 분리 (멀티 도메인 격리)
- 매 요청마다 세션 쿠키를 갱신 (슬라이딩 세션)
- 세션 쓰기 잠금을 일찍 해제 (동시 요청 지원)

### CsrfMiddleware

```php
// src/Core/Middleware/CsrfMiddleware.php
```

**검증 대상:** POST, PUT, PATCH, DELETE 요청

**건너뛰는 경우:**
- GET, HEAD, OPTIONS 요청
- Bearer 토큰 인증 (API 요청)
- 등록된 제외 경로 (`addExcludePath()`)

**토큰 확인 순서:**
1. `X-CSRF-Token` 헤더
2. JSON body의 `_token` 필드
3. POST body의 `_token` 필드

**Plugin에서 경로 제외:**
```php
// Provider.boot()에서
CsrfMiddleware::addExcludePath('/pg-callback/');
```

### AdminMiddleware

```php
// src/Core/Middleware/AdminMiddleware.php
```

**검사 순서:**
1. 로그인 경로 (`/admin/login`, `/admin/logout`) → 통과
2. 로그인 여부 → 미로그인이면 `/admin/login`으로 리다이렉트
3. 관리자 여부 → 비관리자면 리다이렉트
4. Super Admin → 모든 권한 통과
5. 메뉴 권한 검사 → 거부된 메뉴면 403

AJAX 요청이면 리다이렉트 대신 JSON 에러(401/403)를 반환합니다.

### 미들웨어 작성

```php
namespace Mublo\Plugin\MyPlugin;

use Mublo\Core\Middleware\MiddlewareInterface;
use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\AbstractResponse;

class MyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        // 전처리 (Controller 실행 전)

        $response = $next($request, $context); // 다음 미들웨어 또는 Controller

        // 후처리 (Controller 실행 후)

        return $response;
    }
}
```

## Dispatcher — Controller 파라미터 주입

`src/Core/App/Dispatcher.php`

Controller 메서드의 파라미터는 타입 힌트와 이름을 기반으로 자동 주입됩니다.

### 주입 규칙 (우선순위)

| 파라미터 | 주입 값 |
|----------|---------|
| `Request $request` | 현재 Request 객체 |
| `Context $context` | 현재 Context 객체 |
| `array $params` | 라우트 파라미터 전체 (이름이 `params`일 때) |
| `string $board_id` 등 | 라우트 파라미터 개별 매칭 |
| 기본값 있는 파라미터 | 기본값 사용 |
| nullable 파라미터 | null 주입 |

### 유효한 Controller 메서드 시그니처

```php
public function index(Context $context): ViewResponse
public function show(array $params, Context $context): JsonResponse
public function view(string $board_id, int $post_no, Context $context): ViewResponse
public function list(Request $request, Context $context): ViewResponse
public function create(int $page = 1): ViewResponse
```

### Controller 인스턴스 생성

Controller도 DI로 생성됩니다. 생성자에 Service를 타입 힌트로 선언하면 자동 주입됩니다.

```php
class BoardController
{
    public function __construct(
        private BoardService $boardService,
        private BoardPermissionService $permissionService
    ) {}
}
```

권장:
- 컨트롤러에서 확장 포인트가 필요하면 기존 코어 이벤트를 먼저 검토한다
- 라우팅 규칙 자체를 바꾸기보다 `RequestInterceptEvent`, `RendererResolveEvent` 같은 안정 이벤트 재사용을 우선한다

---

[< 이전: 핵심 개념](core-concepts.md) | [다음: 데이터베이스 >](database.md)
