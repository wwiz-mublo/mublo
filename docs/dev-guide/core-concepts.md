# 핵심 개념

Mublo Framework의 핵심 구성 요소 4가지를 설명합니다.

## DI 컨테이너 (DependencyContainer)

`src/Core/Container/DependencyContainer.php`

PSR-11 호환 경량 DI 컨테이너입니다. 서비스를 등록하고, 필요한 곳에서 꺼내 씁니다.

### 서비스 등록

3가지 등록 방법이 있습니다.

```php
// 1. 인스턴스 직접 등록 (이미 생성된 객체)
$container->set(Context::class, $context);

// 2. 싱글톤 등록 (최초 호출 시 생성, 이후 재사용)
$container->singleton(MemberService::class, fn($c) =>
    new MemberService(
        $c->get(MemberRepository::class),
        $c->get(EventDispatcher::class)
    )
);

// 3. 팩토리 등록 (매 호출마다 새 인스턴스)
$container->factory(SomeRenderer::class, fn($c) =>
    new SomeRenderer($c->get(Database::class))
);
```

### 서비스 조회

```php
$service = $container->get(MemberService::class);
```

조회 우선순위:
1. 이미 생성된 인스턴스 (싱글톤 캐시 / `set()`)
2. 싱글톤 팩토리 → 생성 후 캐시
3. 일반 팩토리 → 매번 새 인스턴스
4. Auto-wiring → 허용된 네임스페이스에서 자동 생성

### Auto-wiring

명시적으로 등록하지 않아도, 아래 네임스페이스에 해당하는 클래스는 생성자의 타입 힌트를 분석하여 자동으로 생성됩니다.

- `Mublo\Service\*`
- `Mublo\Infrastructure\*`
- `Mublo\Repository\*`
- `Mublo\Core\Middleware\*`
- `Mublo\Core\Block\Renderer\*`

```php
// 명시적 등록 없이도 사용 가능
$container->get(MemberRepository::class); // auto-wiring
```

### has() vs canResolve()

```php
$container->has($id);         // 명시적 등록만 확인 (PSR-11)
$container->canResolve($id);  // 명시적 등록 + auto-wiring 가능 여부
```

## Context

`src/Core/Context/Context.php`

Context는 **현재 요청의 상태**를 캡슐화한 객체입니다. 매 요청마다 하나의 Context가 생성되어 요청 전체에서 공유됩니다.

### 주요 정보

```php
$context->getDomain();        // "example.com"
$context->getDomainId();      // 1
$context->getDomainInfo();    // Domain 엔티티 (전체 설정)

$context->isAdmin();          // 관리자 영역 여부
$context->isFront();          // 프론트 영역 여부
$context->isApi();            // API 요청 여부

$context->getRequest();       // Request 객체
```

### 멀티 도메인 격리

모든 비즈니스 로직에서 `$context->getDomainId()`를 사용하여 도메인 경계를 지킵니다.

```php
// Service에서
public function getMembers(int $domainId, int $page): array
{
    return $this->memberRepository->findByDomain($domainId, ...);
}

// Controller에서
$domainId = $context->getDomainId();
$result = $this->memberService->getMembers($domainId, $page);
```

### 스킨 정보

Context는 현재 도메인의 스킨 선택도 관리합니다.

```php
$context->getFrameSkin();     // 프론트 프레임 스킨 (기본: 'basic')
$context->getAdminSkin();     // 관리자 스킨
$context->getFrontSkins();    // 프론트 스킨 [group => skin]
$context->getBlockSkins();    // 블록 스킨 [type => skin]
```

### 동적 속성

Package가 Context에 커스텀 데이터를 추가할 수 있습니다. boot 단계에서만 설정 가능하고, 이후 잠금됩니다.

```php
// Provider.boot()에서
$context->setAttribute('shop_config', $shopConfig);

// 이후 어디서든
$config = $context->getAttribute('shop_config');
```

## Result 패턴

`src/Core/Result/Result.php`

모든 Service 메서드는 `Result` 객체를 반환합니다. 성공/실패를 명확하게 표현하고, Controller에서 일관되게 처리할 수 있습니다.

### 생성

```php
use Mublo\Core\Result\Result;

// 성공
return Result::success('회원이 등록되었습니다.', ['member_id' => $id]);

// 실패
return Result::failure('아이디가 이미 존재합니다.');
return Result::failure('검증 실패', ['errors' => $errors]);
```

### 조회

```php
$result->isSuccess();              // bool
$result->isFailure();              // bool
$result->getMessage();             // "회원이 등록되었습니다."
$result->getData();                // ['member_id' => 1]
$result->get('member_id');         // 1
$result->get('missing', 'default');// 'default'
$result->has('member_id');         // true
```

### 변환 (불변)

```php
$newResult = $result->withData(['extra' => 'value']);   // 데이터 병합
$newResult = $result->withMessage('새 메시지');           // 메시지 변경
$array = $result->toArray();                             // ['success' => true, 'message' => '...', ...]
```

### Controller에서의 사용 패턴

```php
public function store(array $params, Context $context): JsonResponse
{
    $result = $this->boardService->createBoard($data, $context->getDomainId());

    return $result->isSuccess()
        ? JsonResponse::success($result->getData(), $result->getMessage())
        : JsonResponse::error($result->getMessage());
}
```

> Result는 Service → Controller 내부 통신용입니다. HTTP 응답은 Response 클래스를 사용합니다.

## Event와 Contract

Mublo 확장 개발에서 자주 헷갈리는 두 축이다.

빠른 구분:

| 패턴 | 질문 | 대표 용도 |
|------|------|-----------|
| Event | "무언가 일어났는가?" | 회원가입 후처리, 메뉴 추가, UI 주입 |
| Contract | "무언가를 호출/조회해야 하는가?" | FAQ 조회, 결제 게이트웨이 선택, 알림 발송 |

예시:
- 로그인 폼에 버튼을 추가한다 → Event
- 다른 패키지가 FAQ 목록을 읽어 간다 → Contract
- 신규 도메인 생성 후 기본 데이터를 시딩한다 → Event

관련 문서:
- [이벤트 시스템](event-system.md)
- [Contract 시스템](contract-system.md)

권장:
- 새 확장 포인트를 만들기 전, 먼저 기존 안정 이벤트가 있는지 확인한다
- “결과를 반환받아야 하는 호출”인데 Event로 억지 구현하지 않는다
- “발생 후 반응”인데 Contract로 과하게 결합하지 않는다

## Response 타입

`src/Core/Response/`

Controller는 반드시 Response 객체를 반환합니다. 4가지 타입이 있습니다.

### ViewResponse — 화면 렌더링

Controller가 **"무엇을 보여줄지"**만 선언합니다. "어떻게 보여줄지"는 Renderer의 책임입니다.

```php
use Mublo\Core\Response\ViewResponse;

// 기본 사용
return ViewResponse::view('Board/List')
    ->withData(['articles' => $articles, 'pagination' => $pagination]);

// Plugin/Package의 절대 경로 뷰
return ViewResponse::view('/path/to/Plugin/views/Admin/Settings')
    ->withAbsolutePath(true)
    ->withData(['config' => $config]);
```

### JsonResponse — JSON API 응답

MubloRequest.js와 호환되는 형식으로 응답합니다.

```php
use Mublo\Core\Response\JsonResponse;

// 성공 응답
return JsonResponse::success($data, '처리되었습니다.');
// → {"result": "success", "message": "처리되었습니다.", "data": {...}}

// 실패 응답
return JsonResponse::error('오류가 발생했습니다.');
// → {"result": "error", "message": "오류가 발생했습니다."} (400)

// 상태 코드 지정
return JsonResponse::error('권한이 없습니다.', null, 403);
```

### RedirectResponse — 리다이렉트

```php
use Mublo\Core\Response\RedirectResponse;

return RedirectResponse::to('/admin/member');           // 302 (임시)
return RedirectResponse::permanent('/new-url');          // 301 (영구)
return RedirectResponse::back();                        // 이전 페이지
return RedirectResponse::back('/fallback');              // 이전 페이지 없으면 fallback
```

### FileResponse — 파일 다운로드

```php
use Mublo\Core\Response\FileResponse;

return new FileResponse($filePath, $fileName, $mimeType);
```

### Response 선택 가이드

| 상황 | Response |
|------|----------|
| HTML 페이지 렌더링 | `ViewResponse::view()` |
| AJAX/API 요청 응답 | `JsonResponse::success()` / `error()` |
| 폼 처리 후 이동 | `RedirectResponse::to()` |
| 파일 다운로드 | `FileResponse` |
| AJAX/비AJAX 겸용 | `$request->isAjax()` 분기 |

```php
// AJAX/비AJAX 겸용 패턴
public function login(Request $request, Context $context): JsonResponse|RedirectResponse
{
    $result = $this->authService->attempt(...);

    if ($request->isAjax()) {
        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    return $result->isSuccess()
        ? RedirectResponse::to('/dashboard')
        : RedirectResponse::back();
}
```

---

[< 이전: 요청 흐름](request-lifecycle.md) | [다음: 라우팅과 미들웨어 >](routing.md)
