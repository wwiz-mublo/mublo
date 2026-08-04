# 요청 흐름

하나의 HTTP 요청이 들어와서 응답이 나가기까지의 전체 흐름입니다.

## 전체 흐름

```
public/index.php
  → bootstrap.php (상수, 오토로더)
    → Application::boot() (환경변수, ServiceProvider, EventDispatcher)
      → Application::run()
        1. Request 생성
        2. Context 생성 (도메인, 영역, 스킨)
        3. 도메인 유효성 검증
        4. 확장 로딩 (Plugin/Package)
        5. 전역 미들웨어 (Session, CSRF)
        6. SiteContextReadyEvent / RequestInterceptEvent
        7. Router → 라우트 매칭
        8. Dispatcher → Controller 실행
        9. Response 처리 (렌더링/출력)
```

관련 문서:
- [이벤트 시스템](event-system.md)
- [라우팅과 미들웨어](routing.md)

## 1단계: 부팅

### public/index.php

웹 서버의 진입점입니다. `bootstrap.php`를 로드하고 `Application`을 생성하여 실행합니다.

### bootstrap.php

프레임워크 경로 상수를 정의하고 Composer 오토로더를 로드합니다.

```php
define('MUBLO_ROOT_PATH', __DIR__);
define('MUBLO_CONFIG_PATH', __DIR__ . '/config');
define('MUBLO_STORAGE_PATH', __DIR__ . '/storage');
define('MUBLO_PUBLIC_PATH', __DIR__ . '/public');
define('MUBLO_PUBLIC_STORAGE_PATH', __DIR__ . '/public/storage');
define('MUBLO_PLUGIN_PATH', __DIR__ . '/plugins');
define('MUBLO_PACKAGE_PATH', __DIR__ . '/packages');
```

### Application::boot()

```
1. .env 파일 로드 (Env::load())
2. ErrorHandler 초기화 (Logger 포함)
3. ServiceProvider::register() — Core 서비스를 DI 컨테이너에 등록
4. EventDispatcher 초기화
5. Core 이벤트 구독자 등록
```

`ServiceProvider::register()`에서 등록되는 Core 서비스: Router, Dispatcher, Database, Session, Cache, Mail, Logger, Renderer, Middleware 등.

## 2단계: 요청 처리

### Request 생성

전역 변수(`$_SERVER`, `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`)에서 Request 객체를 생성합니다. JSON 요청(`Content-Type: application/json`)이면 `php://input`을 파싱합니다.

```php
$this->request = $this->createRequest();
```

> 전역 변수 접근은 이 시점에서만 일어납니다. 이후 모든 코드는 Request 객체를 통해 접근합니다.

### Context 생성

Request를 분석하여 현재 요청의 상태를 결정합니다.

```
ContextBuilder가 판단하는 것:
- 도메인: HTTP_HOST → DomainRepository에서 조회
- 영역: URL이 /admin으로 시작하면 Admin, /api면 API, 나머지는 Front
- 스킨: 도메인 설정에서 스킨 선택 로드
```

Context가 생성되면 DI 컨테이너에 등록되고, 도메인 ID에 따라 캐시와 로그 경로가 분리됩니다.

## 3단계: 도메인 검증

도메인이 `domain_configs` 테이블에 등록되어 있는지, 접근 가능한 상태인지 확인합니다. 미등록 도메인이면 에러 페이지를 표시합니다.

## 4단계: 확장 로딩

도메인의 `extension_config`에서 활성화된 Plugin/Package만 로딩합니다.

```
ExtensionManager::loadExtensions()
  → 각 Provider의 register() 호출 (DI 서비스 등록)
  → 각 Provider의 boot() 호출 (이벤트 구독자, 블록, Contract 바인딩)
  → Context 속성 잠금 (lockAttributes)
```

이 시점 이후에는 모든 Plugin/Package의 서비스를 DI 컨테이너에서 꺼내 쓸 수 있습니다.

## 5단계: 전역 미들웨어

모든 요청에 공통 적용되는 미들웨어가 실행됩니다.

```php
$globalPipeline->through([
    SessionMiddleware::class,   // 세션 시작/복원
    CsrfMiddleware::class,      // CSRF 토큰 검증 (POST 요청)
]);
```

미들웨어 파이프라인 안에서 이벤트가 발행됩니다:

- **SiteContextReadyEvent** — 세션 시작 후 발행. Plugin/Package가 사이트 로고/이미지를 재정의할 수 있는 확장점
- **RequestInterceptEvent** — Plugin/Package가 요청을 가로채고 Response를 반환할 수 있는 확장점 (사이트 접근 제어, 로그인 강제 등)

이 두 이벤트는 요청 흐름의 핵심 경계다. Mublo는 라우터 내부에 무분별한 훅을 두기보다, 이런 의미 있는 경계 지점에 명시적 이벤트를 둔다.

## 6단계: 라우팅

Router가 URL을 분석하여 어떤 Controller의 어떤 메서드를 호출할지 결정합니다.

```
매칭 순서:
1. Core 라우트 (registerCoreRoutes에 정의)
2. Plugin/Package 라우트 (routes.php)
3. 자동 매핑 (autoResolve: URL → Controller@method)
```

> 자세한 내용: [라우팅과 미들웨어](routing.md)

## 7단계: Controller 실행

Dispatcher가 Router의 결과를 받아 Controller를 DI 컨테이너에서 꺼내고, 메서드를 호출합니다.

```php
$dispatcher = new Dispatcher($this->container);
$response = $dispatcher->dispatch($route, $context);
```

Controller 메서드는 반드시 Response 객체를 반환합니다.

## 8단계: 응답 처리

Response 타입에 따라 처리가 분기됩니다.

| Response 타입 | 처리 |
|--------------|------|
| `ViewResponse` | Renderer가 HTML 조립 → 출력 |
| `JsonResponse` | JSON 직렬화 → 출력 |
| `RedirectResponse` | Location 헤더 → 리다이렉트 |
| `FileResponse` | 파일 스트리밍 → 다운로드 |

### ViewResponse의 렌더링

ViewResponse의 경우, Context의 영역(Admin/Front)에 따라 적절한 Renderer가 선택됩니다.

```
Admin 영역 → AdminViewRenderer (관리자 레이아웃)
Front 영역 → FrontViewRenderer (프론트 레이아웃)
```

Plugin/Package는 `RendererResolveEvent`를 구독하여 자체 Renderer를 제공할 수 있습니다.

추가로 프론트 렌더링 과정에서는 다음 이벤트들이 이어진다.
- `ViewContextCreatedEvent`
- `FrontFootRenderEvent`
- `PageViewedEvent`
- `PageTypeResolveEvent`

즉 요청 흐름 문맥에서 보면, 이벤트 시스템은 부가 기능이 아니라 **코어 실행 흐름과 확장 계층을 연결하는 표준 메커니즘**이다.

## 현재 철학

Mublo는 현재 요청 흐름에 대해 다음 원칙을 가진다.

1. 요청 처리의 큰 경계에서만 이벤트를 발행한다
2. 전역 문자열 훅 체계는 두지 않는다
3. 새 이벤트 추가보다 기존 안정 이벤트 재사용을 우선한다

그래서 확장을 만들 때도 보통은:
- 요청 선차단 → `RequestInterceptEvent`
- 로그인 UI 확장 → `LoginFormRenderingEvent`
- 프론트 하단 주입 → `FrontFootRenderEvent`
- 페이지 조회 후 추적 → `PageViewedEvent`
를 먼저 검토하면 된다

## 에러 처리

실행 중 예외가 발생하면 ErrorHandler가 처리합니다.

- **404 (Not Found)** — 라우트 매칭 실패
- **403 (Forbidden)** — 권한 없음
- **500 (Server Error)** — 기타 예외
- **개발 모드** (`APP_DEBUG=true`): 상세 에러 + 스택 트레이스
- **운영 모드**: 사용자 친화적 에러 페이지

---

[< 이전: 아키텍처 개요](architecture.md) | [다음: 핵심 개념 >](core-concepts.md)
