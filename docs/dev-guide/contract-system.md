# Contract 시스템

## Contract란?

Contract는 한쪽이 기능·데이터를 제공하고 다른 쪽이 이를 소비하는 **pull 패턴**입니다.

| 패턴 | 용도 | 예시 |
|------|------|------|
| **Event (push)** | "무언가 일어났다" 알림 | 회원가입됨, 주문상태변경됨 |
| **Contract (pull)** | "데이터를 줘" 요청 | FAQ 목록 조회, 결제 처리 |

인터페이스는 소비자도 구현자도 아닌 **중립 위치** `src/Contract/`에 배치합니다. 구현자는 두 종류입니다.

```
확장 구현 계약:  Package(소비자) → ContractRegistry → Plugin(구현체) 예: FaqQueryInterface
코어 구현 계약:  확장(소비자)    → DI 인터페이스    → Core(구현체)   예: AuthContextInterface
                                      ↑
                             src/Contract/ (인터페이스)
```

**코어 구현 계약**은 확장이 코어 내부(Service/Repository)에 직접 의존하지 않게 하는
안정 통로입니다 — 확장은 `Mublo\Contract\*`만 import하면 확장 API 게이트
(`tools/check-extension-api.php`)를 통과합니다. 코어가 항상 제공하는 단일 구현은
`ServiceProvider`에서 DI 인터페이스로 직접 바인딩합니다.

- 항상 하나의 코어 구현이 존재하고 생성자 주입이 자연스러운 계약은 DI로 바인딩합니다.
  예: `AuthContextInterface`, `MemberAuthenticatorInterface`, `MemberQueryInterface`,
  `MemberNotificationPublisherInterface`, `BlockKitGatewayInterface`, `BlockPageQueryInterface`,
  `CompanyInfoInterface`
- 구현체가 선택적이거나 여러 제공자 중 선택해야 하거나 확장이 런타임에 공급하는 계약은
  `ContractRegistry`를 사용합니다.
  예: 결제·알림 게이트웨이, 본인인증

이벤트와의 관계:
- 이벤트 시스템은 [이벤트 시스템](event-system.md) 문서를 먼저 본다
- Contract는 “데이터/기능을 **조회하거나 호출**해야 할 때” 사용한다
- Event는 “어떤 일이 **발생했음**을 알리고 반응하게 할 때” 사용한다

빠른 판단 기준:

| 상황 | 권장 방식 |
|------|----------|
| 메뉴 추가, 로그인 폼 확장, 회원가입 후처리 | Event |
| 검색 결과 공급, 마이페이지 항목 공급 | Event |
| FAQ 목록 조회, 결제 게이트웨이 선택, 알림 게이트웨이 호출 | Contract |
| 특정 구현체를 명시적으로 선택해야 함 | Contract |

### 인증·회원 Contract

확장에서 `AuthService`나 `MemberRepository`를 직접 주입하지 않습니다.

```php
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Member\MemberQueryInterface;

final class ExampleService
{
    public function __construct(
        private AuthContextInterface $auth,
        private MemberQueryInterface $members,
    ) {}

    public function currentNickname(): ?string
    {
        $memberId = $this->auth->id();
        if ($memberId === null) {
            return null;
        }

        return $this->members->findProfile($memberId)?->nickname;
    }
}
```

`AuthContextInterface::currentUser()`는 내부 세션 배열 대신 읽기 전용 `AuthenticatedUser`를
반환합니다. 로그인 여부·회원 ID·권한만 필요하면 각각 `check()`/`guest()`, `id()`,
`isAdmin()`/`isSuper()` 같은 좁은 접근자를 우선 사용합니다. 세션 키를 직접 가정하지 않습니다.

`MemberQueryInterface`는 읽기 전용 `MemberProfile`을 반환합니다. 회원 생성·수정이 필요하다고 해서
`MemberRepository`의 CRUD를 그대로 Contract로 승격하지 말고, 도메인 정책이 드러나는 좁은 명령
Contract를 별도로 설계합니다.

> **주의:** Event와 Contract를 동시에 만들기 전에, 한 가지 패턴만으로 충분한지 먼저 판단하세요.
> - “호출해서 값을 받는 구조”인데 Event로 억지 구현하지 않는다
> - “발생 사실에 대한 후처리”인데 Contract로 결합을 강하게 만들지 않는다

## ContractRegistry

`src/Core/Registry/ContractRegistry.php`

두 가지 모드를 지원합니다.

### 1:1 바인딩 — 단일 구현체

```php
// Plugin Provider.boot()에서 등록
$registry->bind(FaqQueryInterface::class, new FaqService($repo));

// 또는 지연 생성 (Closure)
$registry->bind(FaqQueryInterface::class, fn() => new FaqService($repo));
```

```php
// Package Controller에서 소비
$faqService = $registry->resolve(FaqQueryInterface::class);
$categories = $faqService->getCategories($domainId);
```

```php
// 존재 여부 확인
$registry->has(FaqQueryInterface::class); // bool
```

- `bind()` — 구현체 등록 (이미 등록되어 있으면 `DuplicateRegistryException`)
- `resolve()` — 구현체 조회 (없으면 `RegistryNotFoundException`)
- `has()` — 등록 여부 확인

### 1:N 등록 — 여러 구현체

결제 게이트웨이처럼 여러 구현체가 필요한 경우 키로 구분합니다.

```php
// PayApp Plugin
$registry->register(
    PaymentGatewayInterface::class,
    'payapp',                               // 키
    fn() => new PayAppGateway($config),     // Closure (지연 생성)
    ['label' => '페이앱']                   // 메타데이터
);

// TestPay Plugin
$registry->register(
    PaymentGatewayInterface::class,
    'testpay',
    fn() => new TestPayGateway(),
    ['label' => '테스트 결제']
);
```

```php
// 특정 키로 조회
$gateway = $registry->get(PaymentGatewayInterface::class, 'payapp');

// 전체 키 목록
$keys = $registry->keys(PaymentGatewayInterface::class);
// → ['payapp', 'testpay']

// 키 존재 여부
$registry->hasKey(PaymentGatewayInterface::class, 'payapp'); // true

// 메타데이터 조회 (인스턴스 생성 없이)
$meta = $registry->getMeta(PaymentGatewayInterface::class, 'payapp');
// → ['label' => '페이앱']

// 전체 메타데이터
$allMeta = $registry->allMeta(PaymentGatewayInterface::class);
// → ['payapp' => ['label' => '페이앱'], 'testpay' => ['label' => '테스트 결제']]
```

메타데이터는 관리자 목록 페이지에서 구현체를 인스턴스화하지 않고 이름/아이콘 등을 표시할 때 유용합니다.

## Contract 작성

### 인터페이스 위치

```
src/Contract/{도메인}/
  └── {Name}Interface.php
```

### 인터페이스 예시

```php
// src/Contract/Faq/FaqQueryInterface.php

namespace Mublo\Contract\Faq;

interface FaqQueryInterface
{
    public function getCategories(int $domainId): array;
    public function getByCategorySlugs(int $domainId, array $slugs): array;
    public function getGroupedAll(int $domainId): array;
    public function getGroupedPaginated(int $domainId, int $page, int $perPage): array;
}
```

## Plugin에서 Contract 구현

```php
// plugins/Faq/Service/FaqService.php

namespace Mublo\Plugin\Faq\Service;

use Mublo\Contract\Faq\FaqQueryInterface;

class FaqService implements FaqQueryInterface
{
    public function __construct(private FaqRepository $faqRepository) {}

    public function getCategories(int $domainId): array
    {
        return $this->faqRepository->findCategoriesWithCount($domainId);
    }

    public function getByCategorySlugs(int $domainId, array $slugs): array
    {
        // ... 구현 ...
    }

    public function getGroupedAll(int $domainId): array
    {
        // ... 구현 ...
    }

    public function getGroupedPaginated(int $domainId, int $page, int $perPage): array
    {
        // ... 구현 ...
    }

    // Contract 외 자체 CRUD 메서드도 같은 클래스에 가능
    public function createItem(int $domainId, array $data): Result { ... }
}
```

### Provider에서 바인딩

```php
// plugins/Faq/FaqProvider.php

public function boot(DependencyContainer $container, Context $context): void
{
    $registry = $container->get(ContractRegistry::class);
    $registry->bind(
        FaqQueryInterface::class,
        $container->get(FaqService::class)
    );
}
```

추천 패턴:
- UI 주입이나 후처리는 Event
- 다른 Package가 재사용할 조회 API는 Contract
- 두 패턴이 모두 필요하면, 보통 “조회는 Contract / 후처리는 Event”로 나눈다

## Package에서 Contract 소비

```php
// 예시: 어떤 Package/Plugin의 컨트롤러에서 FAQ Contract를 소비하는 패턴
// (FaqQueryInterface 구현체는 Faq 플러그인이 등록한다)

namespace Mublo\Packages\YourPackage\Controller\Front;

use Mublo\Contract\Faq\FaqQueryInterface;
use Mublo\Core\Registry\ContractRegistry;

class FaqController
{
    public function __construct(private ContractRegistry $contractRegistry) {}

    public function list(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $page = max(1, (int) ($request->query('page') ?? 1));

        try {
            /** @var FaqQueryInterface $faqService */
            $faqService = $this->contractRegistry->resolve(FaqQueryInterface::class);
            $data = $faqService->getGroupedPaginated($domainId, $page, 10);
        } catch (\Throwable) {
            // FAQ 플러그인 미설치 시 graceful degradation
            $data = ['groups' => [], 'totalItems' => 0, 'perPage' => 10, 'currentPage' => 1, 'totalPages' => 0];
        }

        return JsonResponse::success($data);
    }
}
```

## 계약 반환 타입 — DTO vs 배열

계약이 값을 돌려줄 때 **읽기 전용 DTO**로 줄지, **셰이프 주석이 붙은 배열**로 줄지는
데이터의 성격으로 정한다. "전부 DTO"도 "전부 배열"도 아니다 — 형태가 안정된
도메인 read-model은 DTO, 자유형·외부표면 데이터는 배열이라는 2단 원칙을 따른다.

### DTO로 반환 — 안정된 도메인 read-model

형태가 고정돼 있고 소비자가 **in-repo PHP 클래스(컨트롤러·서비스)**인 조회 결과.

- 예: `MemberQueryInterface → MemberProfile`, `AuthContextInterface → AuthenticatedUser`,
  `BlockPageQueryInterface → BlockPage`, `ManualQueryInterface → ManualBook / ManualPageNode / ManualPageDetail`
- `final readonly class` + 생성자 프로퍼티 승격. 위치는 계약과 같은 `src/Contract/{도메인}/`.
- 매핑(배열 row → DTO)은 **구현 서비스 한 곳**에서만 한다. int 캐스팅·null 정규화가
  생성자에 모여 형태 드리프트가 사라진다.
- 이득: IDE 자동완성·리네임, 불변성, 자기문서화. (게이트 강제는 아래 주의 참고)

### 배열로 반환 — 자유형이거나 외부 표면으로 흐르는 데이터

배열이 **정직한 타입**인 경우. 억지로 DTO를 씌우면 거짓 정밀도가 된다.

- **자유형·벤더 이질 데이터**: `getClientConfig()`, `getCompanyConfig()`,
  `prepare(array $orderData)`(PG사마다 상이), `verify(array $callbackData)` — JSON-ish 설정 뭉치.
- **스킨·JSON API로 흐르는 데이터**: `CategoryProviderInterface::getTree()`(모든 프론트 스킨에
  `ViewContext`로 노출), `FaqQueryInterface`(GET /faq/api/list 로 직렬화 + 블록/프론트 스킨 소비).
  여기서는 **snake_case 키 자체가 외부 계약**이라, DTO의 `json_encode`가 키를 camelCase로 바꾸면
  API·스킨이 깨진다. 스킨은 느슨하게 유지하는 편이 확장 생태계에 맞다([블록 시스템](block-system.md)).
- 단, 배열이라도 **`@return list<array{...}>` 셰이프 주석을 반드시** 붙여 형태를 고정한다.

### 판단 기준

| 데이터 | 반환 |
|--------|------|
| 형태 고정 + 소비자가 in-repo PHP 클래스 | **DTO** |
| 자유형 설정/스키마/벤더 뭉치 | **배열** (+ 셰이프 주석) |
| JSON API·여러 스킨 커스터마이즈 표면으로 흐름 | **배열** (+ 셰이프 주석) |

> **주의 — DTO가 게이트를 공짜로 강제하진 않는다.** PHPStan 게이트는 현재 `level 0`이라
> 배열 키 오타도 DTO 프로퍼티 오타도 잡지 못한다. DTO 프로퍼티 오타(`property.notFound`)는
> `level 2`부터 잡힌다. DTO의 즉시 이득은 자동완성·불변성·단일 매핑 지점이고, 정적 강제까지
> 원하면 계약·소비처 스코프에 한해 레벨을 올리는 별도 조치가 필요하다.

## 기존 Contract 목록

### PaymentGatewayInterface

`src/Contract/Payment/PaymentGatewayInterface.php`

PG사 결제 연동. 1:N 등록 (PayApp, TestPay 등).

```php
interface PaymentGatewayInterface
{
    public function prepare(array $orderData): array;
    public function verify(string $transactionId): array;
    public function cancel(string $transactionId, int $amount, string $reason = ''): array;
    public function getClientConfig(): array;
    public function getCheckoutScript(): ?string;
}
```

### NotificationGatewayInterface

`src/Contract/Notification/NotificationGatewayInterface.php`

알림 발송 (알림톡, SMS, 이메일). 1:N 등록.

```php
interface NotificationGatewayInterface
{
    public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): array;
    public function getSupportedChannels(): array;
    public function getChannelTree(int $domainId): array;
}
```

### CategoryProviderInterface

`src/Contract/Category/CategoryProviderInterface.php`

카테고리 트리 제공. 1:N 등록 (Shop 등 각 패키지가 제공).

```php
interface CategoryProviderInterface
{
    public function getTree(int $domainId, ?int $depth = null): array;
}
```

반환 형식: `[['icon', 'code', 'label', 'link', 'children' => [...]]]`

### ManualQueryInterface

`src/Contract/Manual/ManualQueryInterface.php`

매뉴얼 조회. 1:1 바인딩 (Manual 플러그인). **DTO 반환**(`ManualBook`,
`ManualPageNode`, `ManualPageDetail`) — 형태가 고정된 도메인 read-model이고
소비자가 in-repo 컨트롤러·스킨이라 배열 대신 타입으로 계약을 고정한 예시다.

```php
interface ManualQueryInterface
{
    /** @return list<ManualBook> */
    public function getActiveBooks(int $domainId): array;
    public function getBookBySlug(int $domainId, string $slug): ?ManualBook;
    /** @return list<ManualPageNode> */
    public function getPageTree(int $bookId): array;
    public function getPageBySlug(int $bookId, string $slug): ?ManualPageDetail;
}
```

### FaqQueryInterface

`src/Contract/Faq/FaqQueryInterface.php`

FAQ 데이터 조회. 1:1 바인딩 (FAQ 플러그인). **배열 반환**(+ `array{}` 셰이프 주석) —
출력이 JSON API(`GET /faq/api/list`)로 직렬화되고 블록·프론트 스킨으로 흐르므로
snake_case 키가 외부 계약이다. DTO를 씌우지 않는 쪽이 원칙에 맞는 예시
(위 "계약 반환 타입" 참고).

### BlockKitGatewayInterface (코어 구현)

`src/Contract/Block/BlockKitGatewayInterface.php`

블록 킷 게이트웨이 — 킷을 배포하는 확장의 안정 통로. DI 단일 구현 (코어 `BlockKitGateway`).

```php
interface BlockKitGatewayInterface
{
    // 번들 킷을 보관함에 등록 (멱등 · 스크린샷 굽기/백필)
    public function registerBundled(int $domainId, string $json): Result;
    // page 타깃 킷 적용 — 신규면 생성, 기존이면 append/replace
    public function applyPage(int $domainId, array $kit, string $mode = self::MODE_REPLACE): Result;
}
```

### BlockPageQueryInterface (코어 구현)

`src/Contract/Block/BlockPageQueryInterface.php`

활성 블록 페이지 코드 조회. DI 단일 구현 (코어 `BlockPageService`). 반환 타입 `BlockPage`는 안정 API(`Entity\Block\*`)다.

```php
interface BlockPageQueryInterface
{
    public function findActiveByCode(int $domainId, string $pageCode): ?BlockPage;
}
```

### CompanyInfoInterface (코어 구현)

`src/Contract/Site/CompanyInfoInterface.php`

사이트설정 > 회사 정보 읽기 전용 조회 (연락처 블록·푸터 위젯 등). DI 단일 구현 (코어 `DomainSettingsService`).

```php
interface CompanyInfoInterface
{
    public function getCompanyConfig(int $domainId): array;
}
```

### IdentityVerificationInterface

`src/Contract/Identity/IdentityVerificationInterface.php`

본인인증 (NICE, KMC 등). 1:1 바인딩.

```php
interface IdentityVerificationInterface
{
    public function prepare(array $params): array;
    public function verify(array $callbackData): array;
    public function getClientConfig(): array;
    public function getClientScript(): ?string;
}
```

### CachePurgerInterface

`src/Contract/Cache/CachePurgerInterface.php`

CDN 캐시 퍼지 (Cloudflare 등). 1:1 바인딩.

```php
interface CachePurgerInterface
{
    public function purgeForDomain(int $domainId, ?int $pageId = null): void;
}
```

### DataResettableInterface

`src/Contract/DataResettableInterface.php`

데이터 초기화. Provider가 구현해 노출하되, 실제 삭제 업무는 생성자 DI를 받는 확장 전용 Resetter에 위임한다(별도 Registry 불필요). 활성화되고 정상 부팅된 Provider만 현재 도메인의 초기화 화면에 노출된다.

```php
interface DataResettableInterface
{
    public function getResetCategories(): array;
    public function reset(string $category, int $domainId): DataResetResult;
}
```

DB 연결은 실행 인자로 전달하지 않는다. Resetter가 자기 Repository 또는 `Database`를 생성자로 주입받아 확장 소유 데이터만 처리한다. 코어 소유 데이터는 해당 코어 Contract에 위임한다. 카테고리 `key`는 확장 내부 키이고 `DataResetService`가 `source:name:key` 고유 ID를 만들어 충돌 없이 라우팅한다. 더 넓은 카테고리가 좁은 카테고리를 포함하면 좁은 항목의 `includeInFullReset`을 `false`로 지정해 전체 초기화의 중복 실행을 막는다.

파일 삭제가 필요하면 `DataResetFilesystemInterface`도 구현한다. `reset()`은 트랜잭션 가능한 DB 변경만 수행하고 `resetFiles()`는 커밋 후 호출된다. 파일 정리 실패는 DB를 되돌리지 않으며 관리자 로그와 결과 경고에 남는다.

## 언제 Contract를 쓰고 언제 Event를 쓰는가

| 상황 | 선택 | 이유 |
|------|------|------|
| "FAQ 목록 줘" | **Contract** | 데이터 pull (호출 시점에 필요) |
| "결제 처리해줘" | **Contract** | 동기 호출 + 결과 반환 |
| "회원이 가입했어" | **Event** | 알림 push (누가 듣는지 모름) |
| "주문 상태가 바뀌었어" | **Event** | 부수효과 (로그, 알림, 포인트) |
| "이 회원 포인트 차감해줘" | **Core Service** | BalanceManager가 Core에 있음 |

---

[< 이전: 이벤트 시스템](event-system.md) | [다음: 블록 시스템 개발 >](block-system.md)
