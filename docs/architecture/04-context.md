# 04. Context

## 개요

Context(`src/Core/Context/Context.php`)는 하나의 HTTP 요청에 대한 해석 결과를 담는 요청 스코프 상태 컨테이너다. 클래스 주석의 원칙이 설계의 전부를 요약한다: **Context는 판단하지 않는다. 결과만 보관한다.** 판단(요청 영역 판별, 도메인 해석, 스킨 결정)은 `src/Core/Context/ContextBuilder.php`의 책임이고, Renderer와 Controller는 Context를 신뢰하고 읽기만 한다.

## 책임과 비책임

**Context가 보관하는 것** (`src/Core/Context/Context.php` 주석 기준)

- 요청 단위 상태 (원본 `Request` 포함)
- Front / Admin / Api 영역 판별 결과
- 도메인 정보 (`Domain` 엔티티)
- 렌더링에 필요한 스킨 선택 결과

**Context가 하지 않는 것 (금지 사항)**

- DB 접근, 인증 처리, 비즈니스 로직

인증·사용자 상태는 Context의 금지 사항이다. Context에는 사용자 getter가 없으며, 로그인 사용자 판단은 별도 서비스(`src/Service/Auth/AuthService.php`)의 몫이다.

## 실제 구조: getter 목록

`src/Core/Context/Context.php`의 실제 공개 getter는 다음과 같다.

| 분류 | 메서드 |
|---|---|
| Request | `getRequest()` |
| 영역 플래그 | `isAdmin()`, `isApi()`, `isFront()` (isFront = !admin && !api) |
| 도메인 | `getDomain()`, `getDomainInfo()`, `hasDomainInfo()`, `isDomainAccessible()`, `getDomainId()`, `getDomainGroup()`, `isSuperDomain()` |
| 스킨 | `getAdminSkin()`, `getFrameSkin()`, `getFrameBasePath()`, `getFrontSkin($group)`, `getBlockSkin($type)` |
| 메뉴 | `getCurrentMenuCode()`, `getCurrentMenuLayout()` |
| 사이트 표시 | `getSiteImageUrls()`, `getSiteImageUrl($key)`, `getSiteLogoText()`, `getSiteOverride($key)`, `getSiteOverrides()` |
| 확장 속성 | `getAttribute($key)`, `hasAttribute($key)`, `getAttributes()` |

`isSuperDomain()`은 도메인 그룹(`{superId}/{...}`)의 첫 ID가 자기 자신인지로 SUPER 도메인을 판정한다 — Router의 `super_only` 판정과 같은 규칙을 한 곳에 모은 것이다([05. Router](05-router.md)). `getCurrentMenuLayout()`은 현재 메뉴의 레이아웃 override를 반환하며 `null`이면 도메인 기본을 상속한다. `getTemplateSkin($type)`은 `getBlockSkin()`의 deprecated 별칭이므로 새 코드에서 쓰지 않는다.

setter는 원칙적으로 ContextBuilder 전용이다(`setAdmin()`, `setDomainInfo()`, `setFrameSkin()` 등). 예외적으로 확장이 쓸 수 있는 쓰기 표면은 두 가지다.

- **확장 속성** — `setAttribute(string $key, mixed $value)`. Package가 boot() 단계에서 비즈니스 신호(예: `'shop.is_checkout'`)를 설정하고 Controller·View가 읽는다. `Application::run()`이 확장 로딩 직후 `lockAttributes()`를 호출하므로, boot 단계 이후의 `setAttribute()`는 `LogicException`을 던진다. 읽기는 잠금과 무관하다.
- **사이트 표시 override** — `setSiteImageUrl()`, `setSiteLogoText()`, `setSiteOverride()`. `SiteContextReadyEvent` 구독자가 로고·이미지·고객센터 표시값 등을 요청 범위에서 덮어쓸 때 쓰며, 속성 잠금의 영향을 받지 않는다.

## 생성: 요청 스코프

Context는 요청마다 `Application::run()`에서 새로 만들어진다 (`src/Core/App/Application.php`).

```php
// src/Core/App/Application.php — createContext()
protected function createContext(Request $request): Context
{
    $builder = $this->container->get(ContextBuilder::class);
    return $builder->build($request);
}
```

생성 직후 `$this->container->set(Context::class, $this->context)`로 컨테이너에 등록되어, 다른 서비스가 현재 요청의 Context를 주입받을 수 있다. 등록 메서드가 `set()`(인스턴스 직접 등록)인 것이 요청 스코프의 표현이다 — 팩토리가 아니라 이번 요청의 해석 결과 그 자체를 담는다 ([03. Container](03-container.md)).

`ContextBuilder::build()` (`src/Core/Context/ContextBuilder.php`)의 해석 순서는 다음과 같다.

1. **요청 영역 판별** — 경로가 `/admin`(및 하위)이면 Admin, `/api`(및 하위)이면 Api로 표시한다.
2. **도메인 해석** — `Request::getHost()`의 도메인명을 `DomainResolver`(`src/Service/Domain/DomainResolver.php`)로 캐시 → DB 순서로 조회해 `Domain` 엔티티를 채운다. 사이트 이미지 상대경로는 이 단계에서 요청의 scheme+host를 붙여 Full URL로 변환한다.
3. **스킨 결정** — 도메인의 theme_config를 읽어 프레임·Front 콘텐츠·블록 스킨을 채운다. Api 요청은 스킨 없이, Admin 요청은 adminSkin만 채우고 종료한다.
4. **현재 메뉴 코드 해석** — 요청 URL을 도메인의 메뉴 URL 맵과 매칭한다(path+query 일치 → path 일치 → 최장 prefix 순).

## 도메인 해석과 멀티 도메인

Mublo는 한 설치본이 여러 도메인을 서비스하는 멀티 도메인 구조이며, 그 갈림길이 Context의 `getDomainId()`다. `Application::run()`에서 도메인 ID가 확정되면 (`src/Core/App/Application.php`):

- 캐시·에러 로그·에디터 경로가 도메인별로 분리 설정된다.
- `loadEnabledExtensions()`가 **이 domainId 기준으로** 활성화된 독립 Plugin·Package 목록을 조회해 로딩한다. 같은 코드베이스라도 도메인마다 활성 확장 구성이 다르다 ([14. Extension](14-extension.md)).
- 도메인 유효성 검증(미등록·차단·비활성)도 Context의 `getDomainInfo()`를 읽어 수행된다.

즉 "어느 사이트의 요청인가"라는 질문의 답은 항상 Context에 있고, 도메인별 데이터 분리가 필요한 코드는 `$context->getDomainId()`를 쿼리 조건으로 쓴다.

## 소비 예 1: Controller 파라미터 주입

Dispatcher(`src/Core/App/Dispatcher.php`)는 Controller 액션의 파라미터 타입을 보고 `Context` 타입이면 현재 Context를 주입한다. 실전 예는 회원 컨트롤러다.

```php
// src/Controller/Front/MemberController.php — 발췌
public function registerForm(Request $request, Context $context): ViewResponse|RedirectResponse
{
    // ...
    $domainId = $context->getDomainId();
    // 도메인별 회원 설정 조회에 사용
}
```

## 소비 예 2: Provider boot()의 두 번째 인자

확장 Provider 계약(`src/Core/Extension/ExtensionProviderInterface.php`)에서 Context는 boot 단계에만 등장한다.

```php
// src/Core/Extension/ExtensionProviderInterface.php
public function register(DependencyContainer $container): void;
public function boot(DependencyContainer $container, Context $context): void;
```

시그니처의 비대칭이 곧 규약이다. register()는 컨테이너에 정의만 등록하므로 요청 상태가 필요 없고, boot()는 "어느 도메인의 어떤 요청인가"를 알아야 하는 단계이므로 Context를 받는다. Board Package의 실제 사용 예 (`packages/Board/BoardProvider.php`):

```php
// packages/Board/BoardProvider.php — 발췌
public function boot(DependencyContainer $container, Context $context): void
{
    // ... 블록 타입·이벤트 구독자 등록 ...
    $domainIdResolver = fn() => $container->has(Context::class)
        ? $container->get(Context::class)->getDomainId()
        : null;
    $registry->register('board.recent_notices',
        new RecentNoticesWidget($container->get(BoardArticleRepository::class), $domainIdResolver), 10);
}

public function install(DependencyContainer $container, Context $context): void
{
    // 활성화 시점의 도메인에만 기본 게시판 시딩
    DomainEventSubscriber::seedBoards($container->get(Database::class), $context->getDomainId());
}
```

install()도 같은 시그니처로 Context를 받아, "지금 활성화가 일어난 도메인"을 특정한다.

## 경계

Context는 확장이 읽어도 되는 안정 표면에 속하지만(정확한 목록은 `docs/compatibility-policy.md`), 쓰기는 위에서 명시한 표면(확장 속성, SiteContextReadyEvent의 표시 override)에 한정된다. ContextBuilder 전용 setter(`setAdmin()`, `setDomainInfo()` 등)를 확장이 호출해 해석 결과를 바꾸는 것은 금지 패턴이다 ([32. Anti Pattern](32-anti-pattern.md)).

## 관련 문서

- [02. Core](02-core.md) — run() 흐름에서 Context가 생성·잠금되는 위치
- [03. Container](03-container.md) — `set(Context::class, ...)` 요청 스코프 등록
- [11. Package](11-package.md) — Provider boot()에서의 Context 활용
- [14. Extension](14-extension.md) — domainId 기준 활성 확장 로딩
- 관련 가이드: `docs/dev-guide/request-lifecycle.md`(요청 수명주기), `docs/dev-guide/core-concepts.md`(핵심 개념), `docs/compatibility-policy.md`(안정 API 목록)
