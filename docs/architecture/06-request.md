# 06. Request

## 개요

Request는 HTTP 요청 정보를 캡슐화하는 객체다. 구현은 `src/Core/Http/Request.php`의 `Request` 클래스이며, PHP 전역 변수(`$_SERVER`, `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`) 접근을 이 클래스 하나로 제한하는 것이 존재 이유다.

Request는 Application 부팅 단계에서 생성된다(`src/Core/App/Application.php`). 생성자 시그니처는 다음과 같다.

```php
// src/Core/Http/Request.php
public function __construct(
    string $method,
    string $uri,
    array $query = [],
    array $body = [],
    array $server = [],
    array $files = [],
    array $cookies = []
)
```

`Content-Type`이 `application/json`인 요청은 Application이 `php://input`을 파싱해 `setJsonInput()`으로 주입하고, 파싱 실패 시 `setJsonParseError()`를 기록한다(`src/Core/App/Application.php`). Request 자신은 파싱하지 않는다.

## 책임과 비책임

`src/Core/Http/Request.php` 클래스 주석에 명시된 경계다.

- 책임: 전역 변수 접근 캡슐화, 요청 메서드·URI·쿼리 파라미터 보관, PayloadType 판별(JSON / FORM / QUERY).
- 비책임(금지): 인증 판단, 권한 판단, 비즈니스 로직, DB/Session 직접 접근.

## 입력 접근 메서드

모두 `src/Core/Http/Request.php`에 실존하는 메서드다.

### 쿼리스트링 ($_GET)

- `getQuery(): array` — 전체 쿼리 파라미터.
- `query(string $key, $default = null)` — 특정 키.
- `get(string $key, $default = null)` — `query()`의 별칭.

### 폼 바디 ($_POST)

- `getBody(): array` — 전체 바디.
- `input(string $key, $default = null)` — 특정 키.
- `post(string $key, $default = null)` — `input()`의 별칭.

### JSON 바디

- `json(?string $key = null, $default = null)` — 키 생략 시 전체 배열, 지정 시 해당 값.
- `getJsonInput(): ?array` — 파싱된 JSON 전체(없으면 `null`).
- `hasJsonParseError(): bool` / `getJsonParseError(): ?string` — 잘못된 JSON 바디 여부와 사유.

### 통합 접근

- `all(): array` — PayloadType에 따라 JSON 바디, 폼 바디, 쿼리 중 하나를 반환한다.
- `getData(string $key, $default = null)` — `all()` 결과에서 특정 키.

```php
// src/Core/Http/Request.php — all()의 실제 분기
return match ($payloadType) {
    self::PAYLOAD_JSON => $this->jsonInput ?? [],
    self::PAYLOAD_FORM => $this->body,
    default => $this->query,
};
```

### 기타

- `server(string $key, $default = null)` — `$_SERVER` 값.
- `cookie(string $key, $default = null)` — 쿠키 값.

## 파일 업로드 접근

`$_FILES` 구조를 그대로 보관하며, 세 개의 메서드를 제공한다(`src/Core/Http/Request.php`).

- `getFiles(): array` — 업로드 파일 전체(raw `$_FILES` 구조).
- `hasFile(string $key): bool` — 해당 키의 파일 존재 여부. `UPLOAD_ERR_NO_FILE`이면 없는 것으로 판정한다.
- `getRawFile(string $key): ?array` — 특정 키의 raw 파일 배열. 중첩 구조(`column_images[col][img][pc]` 등)를 직접 처리할 때 사용한다.

## 메서드·헤더·요청 성격 판별

- `getMethod(): string` — HTTP 메서드(생성 시 대문자로 정규화).
- `getUri(): string` / `getPath(): string` — 쿼리스트링을 제외한 요청 경로. `getPath()`는 빈 문자열을 `/`로 보정한다.
- `header(string $key, $default = null): ?string` — HTTP 헤더 조회. `X-CSRF-Token` → `HTTP_X_CSRF_TOKEN` 방식으로 `$_SERVER`에서 찾는다.
- `bearerToken(): ?string` — `Authorization: Bearer ...` 헤더에서 토큰 추출.
- `getContentType(): ?string` — Content-Type 헤더.
- `getPayloadType(): string` — `PAYLOAD_JSON` / `PAYLOAD_FORM` / `PAYLOAD_QUERY` 상수 중 하나.
- `isJson(): bool` — PayloadType이 JSON인지.
- `isAjax(): bool` — `X-Requested-With: XMLHttpRequest` 여부.
- `getHost(): ?string` — Host 헤더를 `normalizeHost()`로 검증·정규화해 반환. 제어문자·경로 구분자가 섞인 값은 `null`.
- `isHttps(): bool` / `getScheme(): string` / `getSchemeAndHost(): string` — HTTPS 판별과 스킴+호스트 조합. `X-Forwarded-Proto`는 신뢰 프록시에서 온 요청일 때만 인정한다.
- `getClientIp(): string` — 클라이언트 IP. `Request::setTrustedProxies()`로 등록된 신뢰 프록시에서 온 요청일 때만 `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP` 등 프록시 헤더를 사용하고, 그 외에는 `REMOTE_ADDR`만 반환한다.

이 밖에 라우팅 전 위험 경로(널바이트, 인코딩된 구분자 등)를 감지했을 때 사용하는 `setInvalid()` / `isInvalid()` / `getInvalidReason()`이 있으며, 표시된 요청은 Application이 400으로 응답한다.

## 컨트롤러에서 받는 방법

Controller 메서드 시그니처에 `Request` 타입 파라미터를 선언하면 `Dispatcher::invokeAction()`(`src/Core/App/Dispatcher.php`)이 현재 Request를 자동 주입한다. `Context::getRequest()`로도 접근할 수 있다.

## 예제 — 실제 컨트롤러 사용례

`src/Controller/Front/MemberController.php`의 아이디 중복 검사 액션 발췌다. JSON 바디와 폼 바디를 모두 수용하는 패턴을 보여준다.

```php
// src/Controller/Front/MemberController.php
public function checkUserId(Request $request, Context $context): JsonResponse
{
    $domainId = $context->getDomainId();
    $userId = $request->json('user_id', '') ?: $request->post('user_id', '');
    $useEmailAsUserId = $context->getDomainInfo()?->isUseEmailAsUserId() ?? false;

    if (empty($userId)) {
        $label = $useEmailAsUserId ? '이메일' : '아이디';
        return JsonResponse::error($label . '를 입력해주세요.', null, 422);
    }

    $result = $this->memberService->checkUserIdAvailability($domainId, $userId, $useEmailAsUserId);

    if ($result->isSuccess()) {
        return JsonResponse::success(null, $result->getMessage());
    }

    return JsonResponse::error($result->getMessage(), null, 422);
}
```

## 관련 문서

- [04. Context](04-context.md) — Request를 보관하는 요청 스코프 상태
- [05. Router](05-router.md) — Request의 경로·메서드로 라우트를 결정하는 과정
- [07. Response](07-response.md) — Controller가 Request를 처리한 뒤 반환하는 것
- 관련 가이드: `docs/dev-guide/request-lifecycle.md`(요청 생명주기), `docs/dev-guide/client-ajax.md`(클라이언트 AJAX)
