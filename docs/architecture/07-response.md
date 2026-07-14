# 07. Response

## 개요

Mublo의 Controller는 값을 echo하지 않는다. 화면·JSON·리다이렉트·파일 어떤 결과든 Response 객체로 "의도"를 반환하고, 실제 출력은 `Application::handleResponse()`(`src/Core/App/Application.php`)가 타입별로 처리한다.

모든 Response의 공통 부모는 `src/Core/Response/AbstractResponse.php`의 `AbstractResponse`이며, 하위 타입은 다섯 개다(모두 `src/Core/Response/` 아래).

| 클래스 | 용도 |
|---|---|
| `HtmlResponse` | 원시 HTML/텍스트 문자열 직접 출력 (Renderer 미경유) |
| `JsonResponse` | JSON API 응답 (MubloRequest.js 호환 형식) |
| `ViewResponse` | View 파일 렌더링 의도 선언 (Renderer가 조립) |
| `RedirectResponse` | 리다이렉트 |
| `FileResponse` | 정적 파일·다운로드 서빙 (304, Range 지원) |

## 컨트롤러 반환 규약

`Dispatcher::dispatch()`(`src/Core/App/Dispatcher.php`)는 Controller의 반환값이 `AbstractResponse` 인스턴스가 아니면 예외를 던진다.

```php
// src/Core/App/Dispatcher.php
if (!$response instanceof AbstractResponse) {
    throw new \RuntimeException(
        'Controller must return a Response object (AbstractResponse). Returned: ' .
        (is_object($response) ? get_class($response) : gettype($response))
    );
}
```

출력 단계에서 `Application::handleResponse()`는 타입을 순서대로 검사한다. `ViewResponse`는 Renderer에 위임하고, `JsonResponse`/`RedirectResponse`는 상태 코드와 헤더를 직접 전송하며, `HtmlResponse`/`FileResponse`는 각자의 `send()`를 호출한다. 다섯 타입에 해당하지 않아도 `send()` 메서드를 가진 객체면 그것을 호출한다 — Package가 커스텀 Response를 정의할 수 있는 확장점이다.

## AbstractResponse — 공통 표면

`src/Core/Response/AbstractResponse.php`는 상태 코드와 헤더만 다룬다.

- `getStatusCode(): int` / `getHeaders(): array`
- `withStatusCode(int $code): static` — 상태 코드 설정(자기 자신 반환, 체이닝용)
- `withHeader(string $name, string $value): static` — 헤더 추가

## HtmlResponse — 원시 문자열 출력

`src/Core/Response/HtmlResponse.php`. View 파일 없이 코드에서 만든 문자열을 그대로 내보낸다. Renderer를 거치지 않으므로 Header/Footer/Layout이 붙지 않는다. 생성자는 `__construct(string $html, int $statusCode = 200)`이고 기본 `Content-Type`은 `text/html; charset=UTF-8`이다.

```php
// Sitemap XML — src/Core/Response/HtmlResponse.php 주석의 사용 예
return (new HtmlResponse($xml))
    ->withHeader('Content-Type', 'application/xml; charset=UTF-8');

// PG 콜백 등 단순 텍스트
return new HtmlResponse('SUCCESS');
```

`ViewResponse::partial()`과의 차이: partial은 뷰 파일이 존재하고 Renderer를 경유하며 Layout만 생략한다. HtmlResponse는 뷰 파일 자체가 없다.

## JsonResponse — API 응답

`src/Core/Response/JsonResponse.php`. 생성자와 정적 팩토리는 다음과 같다(모두 실존 시그니처).

```php
public function __construct(mixed $data = null, bool $success = true, ?string $message = null, int $statusCode = 200)

JsonResponse::success(mixed $data = null, ?string $message = null, int $statusCode = 200): self
JsonResponse::error(string $message, mixed $data = null, int $statusCode = 400): self
JsonResponse::validationError(array $errors, string $message = 'Validation failed'): self  // 422
JsonResponse::unauthorized(string $message = 'Unauthorized'): self   // 401
JsonResponse::forbidden(string $message = 'Forbidden'): self         // 403
JsonResponse::notFound(string $message = 'Not found'): self          // 404
JsonResponse::serverError(string $message = 'Internal server error'): self  // 500
```

실패 응답 팩토리의 이름은 `error()`다 — `failure()`라는 메서드는 존재하지 않는다. 응답 본문은 `buildResponseData()`가 클라이언트 라이브러리 MubloRequest.js가 기대하는 형식으로 만든다: `result`(`'success'`|`'error'`), `success`(하위 호환), `message`, 그리고 `$data`가 `null`이 아닐 때만 `data` 키가 포함된다. `toJson()`은 인코딩 실패 시 최소한의 에러 JSON으로 폴백한다. 조회용으로 `getData()`, `isSuccess()`, `getMessage()`가 있다.

## ViewResponse — 화면 출력 의도

`src/Core/Response/ViewResponse.php`. 생성자가 `protected`라 `new`로 만들 수 없고 Named Constructor만 사용한다.

- `ViewResponse::view(string $viewPath): self` — 논리 경로(예: `'Auth/Login'`, `'Board/List'`). Core `views/` 기준.
- `ViewResponse::absoluteView(string $absolutePath): self` — 절대 경로(.php 확장자 제외). Plugin/Package가 자체 View 파일을 쓸 때 사용.

의도 표현용 플루언트 메서드:

- `withData(array $data): self` — View에 전달할 데이터. 덮어쓰지 않고 병합되므로 계층별로 점진 구성이 가능하다.
- `fullPage(): self` / `partial(): self` — 전체 페이지/부분 출력 "힌트". 클래스 주석이 명시하듯 이 값은 명령이 아니라 힌트이며, 실제 Header/Layout/Footer 포함 여부는 Renderer가 Context와 규칙에 따라 최종 결정한다.

```php
// src/Core/Response/ViewResponse.php 주석의 사용 예 (Plugin 자체 View)
return ViewResponse::absoluteView(
    MUBLO_PLUGIN_PATH . '/MemberPoint/views/Admin/History'
)->withData([...]);
```

Renderer 전용 getter는 `getViewPath()`, `getViewData()`, `isFullPageHint()`, `isAbsolutePath()`다.

### ViewResponse와 템플릿·스킨의 관계

ViewResponse 자체는 레이아웃·스킨 정보를 전혀 갖지 않는다 — "무엇을 보여줄지"만 선언하고 "어떻게"는 Renderer의 책임이다. `Application::handleResponse()`는 먼저 `RendererResolveEvent`(`src/Core/Event/Rendering/RendererResolveEvent.php`)를 디스패치해 Package/Plugin이 커스텀 렌더러를 지정할 기회를 주고, 지정이 없으면 `Context::isAdmin()`에 따라 `AdminViewRenderer` 또는 `FrontViewRenderer`(둘 다 `src/Core/Rendering/`)로 폴백한다. `FrontViewRenderer`는 프레임 스킨(`frameSkin`, 기본 `'basic'`)을 보유하고 `LayoutManager`(`src/Core/Rendering/LayoutManager.php`)를 도구로 사용해 Header/Layout/Content/Footer를 조립한다 — 스킨 결정은 전적으로 Renderer 계층에서 일어난다.

## RedirectResponse — 리다이렉트

`src/Core/Response/RedirectResponse.php`. 생성자는 `__construct(string $location, int $statusCode = 302)`이며 `Location` 헤더를 스스로 설정한다.

- `RedirectResponse::to(string $url, int $statusCode = 302): self`
- `RedirectResponse::permanent(string $url): self` — 301
- `RedirectResponse::back(string $fallback = '/'): self` — `HTTP_REFERER`가 http/https 스킴이고 같은 호스트일 때만 이전 페이지로, 아니면 fallback으로. 외부 URL 오픈 리다이렉트를 차단한다.
- `getLocation(): string`

## FileResponse — 파일 서빙

`src/Core/Response/FileResponse.php`. 정적 파일 응답, 304 Not Modified, 캐싱 헤더, Range 요청(대용량 스트리밍)을 지원한다.

```php
public function __construct(
    ?string $filePath = null,      // 304 응답 시 null
    int $statusCode = 200,
    array $headers = [],
    ?string $content = null,       // 직접 전송할 내용 (에러 메시지 등)
    int $rangeStart = 0,
    ?int $rangeLength = null       // null이면 전체
)
```

`send()`는 상태 코드·헤더 전송 후 304면 바디 없이 종료하고, `$content`가 있으면 그대로 출력하며, 파일이면 `sendFile()`이 8KB 청크 단위로 전송한다(Range 시작 위치 seek, `connection_aborted()` 감지 포함). Core에서는 `/serve/*` 라우트의 `ServeController`(`src/Controller/Api/ServeController.php`)가 대표 사용처다.

## 경계

Response 클래스 다섯 종과 `AbstractResponse`는 Controller가 직접 생성하는 공개 표면이다. 반면 `ViewResponse`의 Renderer 전용 getter나 `Application::handleResponse()`의 분기 순서는 내부 구현이다. Core 안정 API의 최종 목록은 `docs/compatibility-policy.md`가 진실이다.

## 관련 문서

- [05. Router](05-router.md) — 라우트 매칭과 Dispatcher의 Response 타입 검증
- [06. Request](06-request.md) — Response를 만들기 전 입력 접근
- [08. Event](08-event.md) — RendererResolveEvent 같은 렌더링 확장점
- [15. Public API](15-public-api.md) — 안정 API와 내부 구현의 경계
- 관련 가이드: `docs/dev-guide/request-lifecycle.md`(요청 생명주기), `docs/dev-guide/client-ajax.md`(클라이언트 AJAX), `docs/dev-guide/error-handling.md`(에러 처리), `docs/compatibility-policy.md`(호환성 정책)
