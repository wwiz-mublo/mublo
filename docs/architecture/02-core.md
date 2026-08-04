# 02. Core

## 개요

Core는 Mublo Framework의 실행 골격이다. HTTP 요청이 들어와 응답이 출력되기까지의 전 과정 — Request 생성, Context 해석, 도메인 검증, 확장 로딩, Middleware, 라우팅, 디스패치, 렌더링 위임 — 을 `src/Core/App/Application.php`가 지휘한다. 이 장은 실제 부팅·실행 순서를 코드 기준으로 서술한다.

현재 프레임워크 버전은 `src/Core/App/Application.php`의 `Application::VERSION` 상수가 단일 진실원천(SSOT)이다.

```php
// src/Core/App/Application.php
public const VERSION = '1.0.0';

public function version(): string
{
    return static::VERSION;
}
```

git 태그가 없는 배포 환경(zip + FTP)에서도 런타임과 확장이 코어 버전을 알 수 있도록 코드에 명시하며, 인스턴스 메서드 `version()`으로도 접근할 수 있다.

## 책임과 비책임

`Application`의 클래스 주석은 책임 범위를 명시한다 (`src/Core/App/Application.php`).

**Core(Application)가 책임지는 것**

- Request 생성 — 전역 변수(`$_SERVER`, `$_GET`, `$_POST`) 접근은 `createRequest()` 안에서만 한다.
- Context 생성 — `ContextBuilder`에 위임 ([04. Context](04-context.md)).
- Router / Dispatcher 실행 흐름 제어.
- Response 타입에 따른 최종 출력 위임 — HTML 조합은 Renderer의 몫이다.

**Core가 책임지지 않는 것 (금지 목록)**

- 비즈니스 로직, DB 직접 접근, Controller 판단 로직, 인증·권한 판단.

애플리케이션의 실제 기능(게시판, 회원 확장 기능 등)은 Package의 몫이다 ([11. Package](11-package.md)). Core는 확장을 발견·등록·부팅하는 절차만 제공한다 ([14. Extension](14-extension.md)).

## 진입점과 부트스트랩

모든 웹 요청은 `public/index.php`로 들어온다. 흐름은 다음과 같다.

1. `bootstrap.php` 실행 — 경로 상수(`MUBLO_ROOT_PATH`, `MUBLO_PACKAGE_PATH` 등) 정의, Composer autoload, Dotenv로 `.env`/`.env.local`/`.env.{환경}` 변형 파일을 `$_ENV`에 로드, PHP 런타임 설정(`APP_DEBUG`에 따른 에러 표시), 마지막에 `new Application()`을 반환한다. bootstrap은 라우팅·세션·DB 접근·확장 실행을 하지 않는다.
2. 설치 여부 확인 — `storage/installed.lock`과 `config/database.php`의 존재로 판단하고, 미설치면 `/install`로 리다이렉트한다 (`public/index.php`의 `isInstalled()`).
3. `$app->boot()` 후 `$app->run()` 호출.

## boot(): 부트 단계

`Application::boot()` (`src/Core/App/Application.php`)는 요청 처리 전 준비를 한다. 실제 순서는 다음과 같다.

1. **환경 변수 로드** — `loadEnv()`가 `.env` 파일을 `Env::load()`로 읽는다. bootstrap의 Dotenv는 `$_ENV`를 채우고, `Env::load()`는 `putenv()`와 `Env` 내부 캐시를 채우는 별개 역할이다.
2. **에러 핸들러 초기화** — `initErrorHandler()`가 `Logger`와 `ErrorHandler`(`src/Core/Error/ErrorHandler.php`)를 생성·등록하고 컨테이너에 싱글톤으로 넣는다. `APP_DEBUG` 값으로 디버그 모드를 결정한다.
3. **Core 서비스 등록** — `src/Core/Provider/ServiceProvider.php`의 `register()`가 Router, Dispatcher, Rendering, Database 등 코어 구성요소를 컨테이너에 등록한다 ([03. Container](03-container.md)).
4. **EventDispatcher 확인** — 등록되지 않았을 경우를 대비해 `EventDispatcher`를 싱글톤으로 보강 등록한다.
5. **Core 이벤트 구독자 등록** — `ServiceProvider::bootSubscribers()`. 확장의 구독자 등록은 이 단계가 아니라 `run()`의 확장 로딩 단계에서 이루어진다.

## run(): 실행 흐름

`Application::run()` (`src/Core/App/Application.php`)의 실제 단계 순서다. 코드의 주석 번호를 그대로 따른다.

1. **Request 생성** — `createRequest()`. JSON 본문 파싱 실패나 위험 경로(널바이트, 인코딩된 경로 구분자 `%2F`·`%5C`)는 라우팅 전에 400 `JsonResponse`로 조기 차단한다.
2. **Context 생성** — `createContext()`가 컨테이너에서 `ContextBuilder`를 받아 `build($request)`를 호출한다. 생성된 Context는 `$container->set(Context::class, $context)`로 컨테이너에 등록되고, 도메인 ID가 있으면 캐시·에러 핸들러·에디터 경로를 도메인별로 분리 설정한다.
3. **도메인 유효성 검증** — `validateDomain()`. 코어는 도메인의 `status`만 본다. 미등록(`not_found`)·차단(`blocked`)·비활성(`inactive`)이면 `ErrorRenderer`로 에러 페이지를 출력하고 종료한다. 계약 만료 같은 상용·임대 정책은 코어가 아니라 해당 Package가 자체 데이터로 판단해 `status`를 바꾸는 방식으로 표현한다(`Domain::isAccessible()` 주석). `/install`, 공개 API(`/csrf/`, `/api/v1/csrf/`, `/serve/`), 도메인 도달 확인 프로브(`DomainVerificationService::PROBE_PATH`)는 검증을 건너뛴다.
4. **(3.5) 활성 확장 로딩** — `loadEnabledExtensions()`. `ExtensionService`(`src/Service/Extension/ExtensionService.php`)로 해당 도메인에서 활성화된 독립 Plugin·Package 목록을 조회하고, `ExtensionManager`(`src/Core/Extension/ExtensionManager.php`)가 각 Provider의 register/boot를 수행한다. 확장 로딩이 실패해도 디버그 모드가 아니면 경고 로그만 남기고 코어는 계속 동작한다.
5. **(3.6) 확장 후속 구독자 등록** — `ServiceProvider::bootPostExtensionSubscribers()`. Package 의존 서비스가 필요한 Core 구독자를 확장 로드 뒤에 등록한다. 직후 `$this->context->lockAttributes()`로 확장 속성을 잠근다 — 이후 `setAttribute()`는 예외를 던진다.
6. **전역 Middleware 실행** — `MiddlewarePipeline`에 `SecurityHeadersMiddleware`, `SessionMiddleware`, `CsrfMiddleware`(모두 `src/Core/Middleware/`)를 태워 실행한다. `SecurityHeadersMiddleware`가 가장 바깥이라 CSRF·인증이 조기 반환한 응답에도 보안 헤더가 붙는다. 파이프라인의 최종 핸들러 안에서:
   - **SiteContextReadyEvent 발행** — 세션 시작 이후이므로 확장이 세션 값을 참조해 로고·이미지 URL을 override할 수 있다.
   - **RequestInterceptEvent 발행** — 확장이 요청을 가로채 Response를 반환하면 라우팅 없이 그 Response로 종료한다.
   - **Router 실행** — `$router->dispatch($request, $context)` ([05. Router](05-router.md)).
   - **Dispatcher 실행** — 컨테이너에서 `Dispatcher`를 해석해 Controller를 호출하고 Response를 반환받는다.
7. **Response 처리** — `handleResponse()`. 처리 중 예외는 `ErrorHandler::handle()`이 받아 404/403/500을 분기한다.

`handleResponse()`는 Response 타입별로 출력을 위임한다 ([07. Response](07-response.md)). `ViewResponse`는 `RendererResolveEvent`를 먼저 발행해 확장이 커스텀 렌더러를 지정할 수 있게 하고, 없으면 `Context::isAdmin()`에 따라 `AdminViewRenderer`/`FrontViewRenderer`(`src/Core/Rendering/`)로 폴백한다. `JsonResponse`·`RedirectResponse`는 헤더와 본문을 직접 출력하고, `HtmlResponse`·`FileResponse`와 `send()` 메서드를 가진 커스텀 Response는 `send()`에 위임한다. 그 외 타입은 `RuntimeException`이다.

## Middleware 파이프라인

모든 Middleware는 `src/Core/Middleware/MiddlewareInterface.php`를 구현한다.

```php
// src/Core/Middleware/MiddlewareInterface.php
interface MiddlewareInterface
{
    public function handle(Request $request, Context $context, callable $next): AbstractResponse;
}
```

전역 파이프라인은 `Application::run()`에서 다음과 같이 조립된다.

```php
// src/Core/App/Application.php — run() 발췌
$globalPipeline = new MiddlewarePipeline($this->container);
$globalPipeline->through([
    // 가장 바깥에서 실행되어 CSRF/Auth가 조기 반환한 관리자 HTML에도 적용된다.
    SecurityHeadersMiddleware::class,
    SessionMiddleware::class,
    CsrfMiddleware::class,
]);

$response = $globalPipeline->run($this->request, $this->context,
    function ($request, $context) {
        // SiteContextReadyEvent → RequestInterceptEvent → Router → Dispatcher
        // ...
    }
);
```

`src/Core/Middleware/MiddlewarePipeline.php`는 체인 실행기다.

- `pipe()` — 클래스명 또는 인스턴스를 하나 추가한다.
- `through()` — 배열로 여러 개 추가한다.
- `run(Request, Context, callable $destination)` — `array_reduce`로 미들웨어를 역순 접어 클로저 체인을 만들고 실행한다. `$destination`이 체인의 최종 핸들러(Controller 실행부)다.

클래스명으로 등록된 Middleware는 컨테이너를 통해 해석된다. `Mublo\Core\Middleware\` 네임스페이스는 Auto Wiring 허용 대상이므로 생성자 의존성이 자동 주입된다 ([03. Container](03-container.md)). 전역 파이프라인 외에, Dispatcher가 라우트별 Middleware를 같은 파이프라인 구조로 실행한다 (`src/Core/App/Dispatcher.php`).

## 환경 설정 로딩

`src/Core/Env/Env.php`는 경량 `.env` 로더다.

- `Env::load(string $path, bool $overwrite = false)` — `KEY=VALUE` 라인을 파싱해 내부 캐시, `$_ENV`, `putenv()`에 등록한다. 주석(`#`)·따옴표·인라인 주석을 처리하며, 기본적으로 이미 존재하는 환경 변수는 덮어쓰지 않는다.
- `Env::get(string $key, mixed $default = null)` — 캐시 → `getenv()` → `$_ENV` 순으로 조회하고, `'true'`/`'false'`/`'null'`/`'empty'` 문자열을 실제 타입으로 변환해 반환한다. `Application`의 디버그 판정도 `Env::get('APP_DEBUG', false) === true` 방식이다.
- `Env::has()`, `Env::required(array $keys)`(누락 시 `RuntimeException`), `Env::all()`, `Env::isLoaded()`, 테스트용 `Env::clear()`를 제공한다.

## 경계

Core의 실행 골격 자체(Application의 내부 메서드, ServiceProvider의 등록 내용)는 확장이 의존해도 되는 공개 표면이 아니다. 확장이 Core와 만나는 공식 접점은 Provider 계약(`src/Core/Extension/ExtensionProviderInterface.php`), 공식 Event, 컨테이너·Context다. Core 안정 API의 정확한 목록은 `docs/compatibility-policy.md`가 진실이다 ([15. Public API](15-public-api.md)).

## 관련 문서

- [03. Container](03-container.md) — 부트 단계에서 서비스가 등록되는 곳
- [04. Context](04-context.md) — run() 2단계에서 생성되는 요청 상태
- [05. Router](05-router.md) — run() 5단계의 라우트 결정
- [08. Event](08-event.md) — SiteContextReadyEvent, RequestInterceptEvent 등 확장점
- [14. Extension](14-extension.md) — loadEnabledExtensions()의 상세 절차
- 관련 가이드: `docs/dev-guide/request-lifecycle.md`(요청 수명주기), `docs/dev-guide/architecture.md`(전체 구조), `docs/dev-guide/error-handling.md`(에러 처리), `docs/compatibility-policy.md`(안정 API 목록)
