# 16. Contract 카탈로그

[15. Public API](15-public-api.md)가 "무엇이 안정 API인가"라는 경계 원칙을 다뤘다면, 이 장은 그 경계 위에 실제로 존재하는 Core 공개 계약(`Mublo\Contract\*`)의 전수 목록이다.

`src/Contract/`에는 인터페이스뿐 아니라 이벤트와 읽기 전용 값 객체도 함께 있으며, 이 장은 그 공개 경계를 다룬다. `docs/compatibility-policy.md`의 안정 API 표는 이 네임스페이스 전체(`Mublo\Contract\*`)와 레지스트리(`Mublo\Core\Registry\*`)를 안정 API로 선언한다.

## 개요 — 두 가지 역할

확장은 Contract 앞에서 두 역할 중 하나를 맡는다.

- **구현하는 계약 (확장이 공급자)** — Plugin/Package가 인터페이스를 구현해 `ContractRegistry`에 등록하고, 다른 확장이나 Core가 소비한다. `PaymentGatewayInterface`, `NotificationGatewayInterface`, `FaqQueryInterface` 등 대부분이 여기 속한다.
- **주입받는 계약 (확장이 소비자)** — Core가 항상 하나의 구현체를 제공하고, 확장은 인터페이스 타입으로 주입받아 쓴다. Auth·Member·AI·Block·Site의 코어 구현 계약은 DependencyContainer 싱글톤으로 바인딩된다(`src/Core/Provider/ServiceProvider.php`).

어느 쪽이든 인터페이스 자체는 소비자도 구현자도 아닌 중립 위치 `src/Contract/{도메인}/`에 둔다. Package(소비자)가 Plugin(구현체)을 직접 알지 않고 계약만 알게 하기 위해서다.

```
Package(소비자) → ContractRegistry → Plugin(선택/복수 구현체)
확장(소비자)    → DI 인터페이스    → Core(필수 단일 구현체)
                       ↑
              src/Contract/ (인터페이스)
```

`ContractRegistry`의 1:1 모드는 “구현체가 하나”라는 뜻이지 코어 단일 구현을 담는 기본 통로라는
뜻이 아니다. FAQ·본인인증·CDN 퍼지처럼 구현 확장이 선택적으로 설치되는 경우에 사용한다.

## ContractRegistry

`src/Core/Registry/ContractRegistry.php`. Core가 싱글톤으로 제공하는 범용 계약 레지스트리로, 두 가지 등록 모드를 지원한다.

| 모드 | 등록 | 조회 | 용도 |
|---|---|---|---|
| 1:1 바인딩 | `bind($contract, $impl)` | `resolve($contract)`, `has($contract)` | 단일 제공자 — FAQ, 본인인증, CDN 퍼지 |
| 1:N 등록 | `register($contract, $key, $impl, $meta)` | `get($contract, $key)`, `all()`, `keys()`, `hasKey()` | 복수 제공자 — PG사, 알림 게이트웨이 |

공통 규칙은 다음과 같다.

- **Lazy 생성** — `Closure`를 등록하면 `resolve()`/`get()` 시점에 인스턴스화하고 캐싱한다. 인스턴스를 직접 등록하면 등록 즉시 인터페이스 구현 여부를 검사하고, 미구현이면 `\InvalidArgumentException`을 던진다. Closure의 반환값도 resolve 시점에 같은 검사를 받는다.
- **`DuplicateRegistryException`** (`src/Core/Registry/DuplicateRegistryException.php`) — 이미 바인딩된 계약에 재바인딩하거나 1:N에서 키가 중복되면 던진다. "나중에 등록한 쪽이 조용히 덮어쓰는" 상황을 금지하는 장치다. 같은 계약을 두 Plugin이 바인딩하려 하면 두 번째 Plugin의 boot가 실패하고, [14. Extension Runtime](14-extension.md)의 격리 규칙에 따라 해당 확장만 비활성 처리된다.
- **`RegistryNotFoundException`** (`src/Core/Registry/RegistryNotFoundException.php`) — 미등록 계약을 `resolve()`/`get()`하면 던진다. 구현 Plugin이 설치되지 않았을 수 있으므로, 소비자는 `has()`/`hasKey()`로 확인하거나 try-catch로 graceful degradation을 구현해야 한다. 실제 소비 코드의 표준형은 다음과 같다.

```php
// packages/Shop/Service/PaymentService.php — 발췌
private function resolveGateway(string $pgKey): ?PaymentGatewayInterface
{
    try {
        $gateway = $this->registry->get(PaymentGatewayInterface::class, $pgKey);
        return $gateway instanceof PaymentGatewayInterface ? $gateway : null;
    } catch (RegistryNotFoundException $e) {
        return null;
    }
}
```

- **메타데이터** — 1:N 등록 시 `$meta`(label, icon 등)를 함께 넘기면 `getMeta()`/`allMeta()`로 **인스턴스를 생성하지 않고** 조회할 수 있다. 관리자 목록 페이지가 게이트웨이를 전부 인스턴스화하지 않고도 이름·아이콘을 표시할 수 있는 이유이며, lazy 등록의 이점을 유지한다.
- **메타 스키마 경고** — Notification·Payment·Identity 계약은 `REQUIRED_META_SCHEMAS` 상수로 필수 메타 키(`label`, Notification은 `channels`까지)를 검증한다. 위반해도 예외가 아니라 `E_USER_WARNING`을 남긴다 — 운영 중 즉시 장애를 만들지 않기 위한 선택이다.

ContractRegistry는 `Mublo\Contract\*` 전용이 아니다. Core 자신도 Report 렌더러(`src/Core/Report/Contract/ReportRendererInterface.php`)의 csv/xlsx/pdf 기본 구현을 `src/Core/Provider/ServiceProvider.php`에서 여기 등록한다. 상세는 23장(Report 엔진)에서 다룬다.

### CategoryProviderRegistry

`src/Core/Registry/CategoryProviderRegistry.php`. 카테고리 계약만을 위한 전용 레지스트리다.

- Package가 `register($key, $provider|Closure)`로 등록하면, 스킨에서 `$this->category('shop')`(`src/Core/Rendering/ViewContext.php`의 `category()`)로 트리를 조회한다.
- ContractRegistry와 달리 (키 + domainId + depth) 조합당 **요청 내 캐싱**을 한다. 같은 페이지에서 헤더와 사이드바가 같은 트리를 두 번 요청해도 Provider는 한 번만 호출된다.
- 미등록 키 조회는 예외 없이 빈 배열을 반환한다 — 스킨이 특정 Package 설치 여부에 따라 깨지지 않게 하기 위한 설계다. 키 중복 등록은 `\InvalidArgumentException`이다.

## 계약 전수 카탈로그

### 결제 — `Contract/Payment/`

| 계약 | 모드 | 구현 | 소비 |
|---|---|---|---|
| `PaymentGatewayInterface` | 1:N | 결제 플러그인 (PayApp·TossPay·IniPay·Kcp·NicePay·TestPay) | 주문을 가진 패키지 (Shop·Mshop) |
| `PaymentConsumerInterface` | 1:N | 주문을 가진 패키지 (`ShopPaymentConsumer`·`MshopPaymentConsumer`) | 결제 플러그인의 콜백 |

결제는 **양방향 계약**이다. 패키지가 PG 를 부르는 방향(`PaymentGatewayInterface`)과, PG 콜백이 결과를 주문에 반영하는 방향(`PaymentConsumerInterface`). 한쪽만 있으면 플러그인이 특정 패키지의 서비스를 직접 붙잡게 되고, 그 순간 "범용 PG 플러그인"이 아니라 그 패키지 전용이 된다.

PG사 연동 계약. 메서드는 결제 생애주기를 그대로 따른다.

| 메서드 | 용도 |
|---|---|
| `prepare(array $orderData): PaymentGatewayResponse` | 결제 준비 — PG에 주문 정보 전달, 클라이언트 토큰 반환 |
| `verify(string $transactionId): PaymentGatewayResponse` | 결제 검증 — PG 서버에서 실제 결제 확인 |
| `cancel(string $transactionId, int $amount, string $reason = ''): PaymentGatewayResponse` | 결제 취소 |
| `getClientConfig(): array` | 프론트 결제창 호출에 필요한 클라이언트 SDK 설정 |
| `getCheckoutScript(): ?string` | 체크아웃 페이지에 삽입할 핸들러 JS. `window.MubloPayHandlers['pg_key'] = function(data) {...}` 등록 규약, 불필요하면 null |

### PaymentConsumerInterface — 반대 방향

PG 결제창이 돌아오는 콜백은 플러그인이 받지만, 그 결과를 주문에 반영하는 일은 주문을 소유한 패키지만 할 수 있다.

| 메서드 | 용도 |
|---|---|
| `findPreparedPayment(int $domainId, string $orderNo): ?PreparedPayment` | 승인 전 금액 대조용 스냅샷. 미지원이면 null — 이때 PG 는 승인 후 검증에만 의존한다 |
| `verifyPayment(int $domainId, string $orderNo, string $pgKey, string $transactionId): Result` | 결제 검증 + 주문 반영 (검증 권위, 멱등) |
| `recordExternalRefund(int $domainId, string $orderNo, string $pgKey, string $transactionId, int $amount, string $reason, bool $fullCancel): Result` | PG 측에서 일어난 취소를 웹훅으로 통보받아 기록 |

소비 패키지는 자기 키로 등록한다.

```php
$registry->register(PaymentConsumerInterface::class, 'shop',
    fn() => $container->get(ShopPaymentConsumer::class), ['label' => '쇼핑몰']);
```

**어느 소비자에게 돌려줄지는 결제 준비 시점에 정해진다.** 패키지가 `prepare()` 의 orderData 에 자기 키를 `consumer` 로 실어 보내고, 플러그인은 그 값을 결제창 콜백 URL 에 `by` 로 태워 되돌려받아 계약을 조회한다. 플러그인은 키 문자열을 옮기기만 할 뿐 그것이 어느 패키지인지 알지 못한다. 되돌려받은 키가 레지스트리에 없으면 결과를 반영하지 않는다(fail-closed).

```
prepare(orderData['consumer'] = 'shop')
   → 콜백 URL: /kcp/callback/return?by=shop&ok=…&fail=…
   → 콜백: registry->get(PaymentConsumerInterface::class, 'shop')->verifyPayment(...)
```

새 패키지가 결제를 붙이려면 이 계약을 구현해 등록하고 `consumer` 키를 넘기면 된다 — **결제 플러그인은 한 줄도 고치지 않는다.**

### 브라우저 신호 규약

결제 플러그인은 소비처(패키지)를 모른다. 결제 진행 중 일어난 "사실"만 알리고, 표시·버튼 복원 같은 후속조치는 전적으로 소비처가 결정한다. 같은 PG를 Shop·Mshop 등이 공유하며 각자 다르게 처리할 수 있기 때문이다.

```js
document.dispatchEvent(new CustomEvent('mublo:pay', {
    detail: { gateway: 'pg_key', status: 'done' | 'cancel' | 'fail', message: '사유', code: 'PG코드', order_no: '주문번호' }
}));
```

| status | 의미 |
|---|---|
| `done` | 결제가 완료됐다(승인·검증까지 끝). **이동할지 그 자리에서 처리할지는 소비처가 정한다** — 플러그인은 이동시키지 않는다 |
| `cancel` | 사용자가 결제창을 닫음 — 오류가 아니다 |
| `fail` | 결제를 진행할 수 없다. `message` 에 사람이 읽을 사유를 담는다 |

`message`·`code`·`order_no` 는 선택이다. `code` 는 PG 원본 코드(소비처의 분기·진단용).

플러그인은 화면에 대한 결정을 직접 내리지 않는다 — 알림 표시(`MubloRequest.showAlert` 등)와 결제 버튼 조작(`MubloPayReset`)은 소비처의 몫이다. 소비처가 준 값(`status_url` 등)으로 코어 유틸리티를 호출하는 것은 무방하다.

결제창이 가맹점 페이지를 통째로 대체하는 경우(리다이렉트형)에는 신호를 받을 창이 없다. **이때만 이동이 필요하며**, 목적지 역시 소비처가 정한다 — `prepare` 로 받은 `success_url`·`fail_url` 을 콜백 URL 쿼리(`ok`·`fail`)에 실어 보내고 되돌려받아 그대로 이동한다. 실패 사유는 복귀 경로에 `pay_error` 쿼리로 싣고, 표시 여부는 그 페이지가 결정한다.

```
prepare  → 콜백 URL: /kcp/callback/return?ok=%2Fshop%2Forder%2FS1%2Fcomplete&fail=%2Fshop%2Fcheckout
성공     → /shop/order/S1/complete
실패     → /shop/checkout?pay_error=...
```

플러그인은 소비처의 URL 규칙을 알지 못한다. 되돌려받은 값은 결제창을 거쳐 오므로 같은 사이트의 절대경로(`/` 로 시작하되 `//` 아님)만 허용해 개방 리다이렉트를 막는다. **경로를 받지 못했다면 추측하지 않는다** — 결과만 알리는 화면으로 끝낸다. 남의 패키지 경로로 보내는 것보다 낫다.

등록 사례로는 `plugins/TestPay/TestPayProvider.php`가 좋은 참고다. '모든 결제 즉시 성공' 가상 게이트웨이이므로 `APP_ENV`가 production이면 boot에서 등록 자체를 건너뛴다 — 활성화 여부와 무관하게 게이트웨이가 존재하지 않으므로 결제 위조가 원천 차단된다.

```php
// plugins/TestPay/TestPayProvider.php — 발췌
if (env('APP_ENV', 'production') === 'production') {
    return; // 프로덕션에서는 가상 게이트웨이를 절대 등록하지 않는다
}
$registry->register(
    PaymentGatewayInterface::class,
    'testpay',
    fn() => new TestPayGateway(),
    ['label' => '테스트 결제', 'icon' => 'bi-credit-card', 'description' => '개발/테스트용 가상 결제']
);
```

### 푸시 — `Contract/Fcm/` 2종

| 계약 | 용도 |
|---|---|
| `FcmMessageServiceInterface` | 범용 FCM 디스패처. `dispatchToInstallation`(단일 활성 Installation), `dispatchToMember`(회원의 활성 Installation), `dispatchToTopic`(도메인 논리 토픽) 세 경로를 제공한다. 반환은 `Mublo\Core\Result\Result` |
| `TopicSubscriptionInterface` | FCM 토픽 구독 관리 — `subscribe`/`unsubscribe`(설치 단위), `subscribeMember`/`unsubscribeMember`(회원 단위 bulk), `subscribersOf`(통계·관리용 목록). 게시판 새글 알림 같은 브로드캐스트 시나리오 |

두 계약에서 Installation은 Web Browser, Android App, iOS App을 포함하는 활성 Push 수신 지점이다. 구현체는 Installation의 도메인 소유권·활성 상태를 검증하고 논리 토픽을 실제 FCM 토픽 이름으로 안전하게 변환해야 한다(각 인터페이스 docblock).

현재 저장소에는 이 두 인터페이스의 구현체나 소비자가 없고, `ContractRegistry` 등록 규약도 정의되어 있지 않다. 따라서 구현체의 배포 형태나 조회 방법을 현재 규약으로 가정해서는 안 된다.

### 잔액/포인트 — `Contract/Balance/`

| 계약 | 모드 | 구현 |
|---|---|---|
| `BalanceGatewayInterface` | 컨테이너 1:1 | `src/Service/Balance/BalanceManager.php` (코어 바인딩) |

확장이 잔액(포인트)을 다루는 **유일한 안정 통로**다 — `BalanceManager`·`BalanceLogRepository` 직접 import 는 비안정 API 로 검사에 걸린다. 표면 5메서드: `adjust`(멱등키 필수 사용 규약), `getBalance`, `history`(domainId 필수·배열 반환), `sumGrantedByReference`(전액 환수 집계), `findLogByIdempotencyKey`(이력 존재 확인). 소비자: Board·Shop·Mshop 패키지와 MemberPoint 플러그인 전부 이 계약 경유. 차단/통지 이벤트는 [21. 잔액·포인트](21-balance-point.md) 참조.

### 알림 — `Contract/Notification/`

| 계약 | 모드 | 구현 |
|---|---|---|
| `NotificationGatewayInterface` | 1:N ContractRegistry | `plugins/EmailNotify/EmailNotifyGateway.php`, `plugins/SendonSms/SendonSmsGateway.php`, `plugins/SendonTalk/SendonTalkGateway.php`, `src/Core/Notification/EmailNotificationGateway.php` |
| `MemberNotificationPublisherInterface` | 컨테이너 1:1 | `src/Service/Notification/MemberNotificationService.php` |

메서드는 세 개다.

- `send(channel, templateCode, recipient, fieldValues)` — 채널('alimtalk'/'sms'/'email' 등)·템플릿·치환 변수로 발송
- `getSupportedChannels()` — 지원 채널 목록
- `getChannelTree(domainId)` — 관리자 설정용 채널·템플릿 트리

소비 사례는 `packages/Shop/Action/NotificationActionHandler.php`(주문 이벤트에 따라 키로 게이트웨이를 선택해 발송)다. Core의 `EmailNotificationGateway`는 인터페이스를 구현하지만 **기본 채널로 자동 등록되지 않는다** — 전용 이메일 게이트웨이 Plugin(EmailNotify)이 명시적으로 `register()`할 때만 노출된다(`src/Core/Provider/ServiceProvider.php`의 ContractRegistry 팩토리 주석).

`MemberNotificationPublisherInterface::publish(MemberNotification): int`는 외부 채널이 아니라 사이트 내부 알림 센터에 저장하는 계약이다. `src/Core/Provider/ServiceProvider.php`가 `MemberNotificationService`를 이 인터페이스에 바인딩한다. `MemberNotification`은 제목·본문을 일반 문자열로 제한하고 이동 주소는 사이트 내부 상대 경로만 허용하는 readonly 값 객체다.

저장이 끝나면 `MemberNotificationPublishedEvent`가 발생한다. 이벤트 payload는 저장된 `notificationId`와 `MemberNotification`이며, Push·메일 확장이 선택적으로 외부 채널에 연결할 수 있도록 열어 둔 지점이다. 현재 저장소에는 이 이벤트의 외부 채널 구독자는 없다.

`CollectNotificationVariablesEvent`는 인터페이스가 아니라 이벤트다(`Mublo\Core\Event\AbstractEvent` 상속).

- **dispatch 측**: 알림 Plugin의 관리자 채널 설정 페이지 — `plugins/SendonTalk/Controller/Admin/SendonTalkController.php`, `plugins/SendonSms/Controller/Admin/SendonSmsController.php`, `plugins/EmailNotify/Controller/Admin/EmailNotifyController.php`
- **구독 측**: 각 Package가 `addVariables(sourceKey, sourceLabel, variables)`로 자기 치환 변수를 등록 — `packages/Shop/EventSubscriber/NotificationVariableSubscriber.php`

특정 알림 Plugin 소유가 아니라 어떤 알림 Plugin이든 재사용하도록 `src/Contract/Notification/` 중립 위치에 있다. 같은 위치의 `MemberNotificationPublishedEvent`도 내부 알림 저장 후 외부 전달 확장이 구독하는 중립 이벤트다. 두 사례 모두 계약의 위치를 개별 구현체가 아니라 협업 경계에 둔 구조다.

### 본인인증 — `Contract/Identity/IdentityVerificationInterface`

1:1 바인딩. 사용 흐름이 메서드 구성 그대로다.

1. `prepare(array $params)` — 인증창 호출에 필요한 토큰·URL 획득
2. 클라이언트 팝업 — `getClientConfig()`와 `getClientScript()`(반환 JS는 `window.MubloIdentityVerify(token, config)` 전역 함수를 등록, 불필요하면 null)
3. `verify(array $callbackData)` — 콜백 데이터에서 실명·생년월일·성별·CI/DI 추출, 검증 실패 시 `\RuntimeException`

NICE, KMC 등 업체별 Plugin이 구현하는 자리이며, **이 저장소에는 번들 구현체가 없다.**

### AI — `Contract/AI/` 5종

| 계약 | 한 줄 용도 |
|---|---|
| `AiGatewayInterface` | 도메인 AI 설정으로 구조화 생성 호출(`isAvailable`/`generate`). API 키 원문은 절대 반환하지 않음 |
| `AiAssetCatalogInterface` | 도메인별 재사용 AI 자산 조회(`list`/`find`/`readExtractedText`/`readContent`) |
| `AiRequest` | 구조화 요청 값 객체 — system/user 프롬프트, 응답 스키마, 자산 ID 목록 |
| `AiResponse` | 공급자 비종속 구조화 응답 값 객체 — data, provider, model |
| `AiAssetDescriptor` | 저장 경로를 노출하지 않는 자산 메타데이터(readonly) |

이 다섯은 "주입받는 계약"이다. 구현체는 Core의 `src/Service/AI/CoreAiGateway.php`·`src/Service/AI/CoreAiAssetCatalog.php`이고, `src/Core/Provider/ServiceProvider.php`가 컨테이너 싱글톤으로 바인딩하며, 확장은 소비만 한다. Core가 공급자 호출·사용량 제한·자산 해석을 대신 수행하고 구조화된 결과만 돌려주는 구조다. 상세(신뢰 경계, 이중 새니타이즈)는 [27. AI 시스템](27-ai.md)에 위임한다.

### 카테고리 — `Contract/Category/CategoryProviderInterface`

`getTree(int $domainId, ?int $depth = null)` 하나로 규격화된 트리(`icon`/`code`/`label`/`link`/`children` 재귀 구조)를 반환한다. Package가 구현·공급하고 스킨이 소비하는, 다른 계약과 방향이 역전된 계약이다.

- 실구현: `packages/Shop/ShopCategoryProvider.php`
- 등록: `packages/Shop/ShopProvider.php` — `CategoryProviderRegistry::register('shop', fn() => new ShopCategoryProvider(...))` (lazy)
- 소비: 스킨의 `$this->category('shop')` 및 `packages/Shop/Controller/Front/ProductController.php`

### FAQ — `Contract/Faq/FaqQueryInterface`

1:1 바인딩. Faq Plugin이 구현하고(`plugins/Faq/Service/FaqService.php`) Package가 소비한다. 조회 메서드 네 개: `getCategories`, `getByCategorySlugs`, `getGroupedAll`, `getGroupedPaginated`(페이지네이션 포함).

```php
// plugins/Faq/FaqProvider.php — boot()에서 바인딩
$contractRegistry->bind(
    FaqQueryInterface::class,
    $container->get(FaqService::class)
);
```

구현 클래스가 계약 외 자체 CRUD 메서드를 함께 가져도 무방하다 — 계약에 없는 메서드는 공개 API가 아닐 뿐이다.

### 캐시 — `Contract/Cache/CachePurgerInterface`

`purgeForDomain(int $domainId, ?int $pageId = null)` 하나. `$pageId`를 주면 특정 페이지만, null이면 도메인 전체 CDN 캐시를 퍼지한다. Cloudflare 등 CDN Plugin이 구현하는 자리이며, **이 저장소에는 번들 구현체가 없다.**

### 데이터 리셋 — `Contract/DataResettableInterface`

유일하게 도메인 하위 폴더 없이 `src/Contract/` 루트에 있다. 레지스트리 등록이 필요 없는 특수형으로, Plugin/Package의 **Provider가 구현**하면 관리자 시스템 관리 → 데이터 초기화 항목에 자동 노출된다. Provider는 실제 삭제를 확장 전용 `*DataResetter`에 위임하고 조립과 노출만 담당한다.

- `getResetCategories(): array` — 초기화 가능한 카테고리 목록(key/label/description/icon/includeInFullReset). `key`는 확장 내부 로컬 키이며, 관리자 API에는 `source:name:key` 고유 ID로 노출된다.
- `reset(string $category, int $domainId): DataResetResult` — 실행, `tables_cleared`/`files_deleted`/`details` 통계 반환. 필요한 DB·스토리지는 Resetter 생성자에 주입한다.
- `DataResetFilesystemInterface::resetFiles()` — 선택 계약. DB 커밋 뒤 파일을 삭제해야 하는 Resetter/Provider가 함께 구현한다. DB 롤백 뒤 파일만 사라지는 일을 막기 위해 `reset()`에서는 파일을 지우지 않는다.

번들 Provider 20곳(Package 4곳, 독립 Plugin 15곳, Board 종속 Plugin 1곳)과 Core의 `CoreDataResetter`가 구현한다. 각 번들 Provider의 실제 초기화 업무는 자기 `Service/*DataResetter.php`가 소유한다. `resetAll()`은 콘텐츠·거래·원장을 먼저 지우고 회원 개인정보를 마지막에 처리하며, 파일 정리는 DB 커밋 뒤 수행한다.

PayApp·TossPay는 도메인별 결제 설정만 소유하고 TestPay는 영속 데이터를 소유하지 않으므로 초기화 항목을 노출하지 않는다. 결제 설정은 전체 초기화에서도 보존한다.

### 추적 — `Contract/Tracking/TrackingKeys`

인터페이스가 아니라 상수 클래스다. 전환 추적 세션 키(`CAMPAIGN_KEY = 'visitor_campaign_key'`)를 Package와 방문통계 Plugin이 공유하기 위한 중립 계약.

- 저장 측: `plugins/VisitorStats/Service/VisitorCollector.php` — 캠페인 유입 시 세션에 키 기록
- 소비 측: `packages/Shop/Controller/Front/CartController.php` — 전환 기록 시 같은 키로 조회

문자열 리터럴을 양쪽에 복붙하는 대신 상수를 계약화한 사례다. 통계·트래킹 전체 그림은 26장에서 다룬다.

### 요약표

| 도메인 | 계약 | 모드 | 번들 구현 | 주 소비자 |
|---|---|---|---|---|
| 결제 | `PaymentGatewayInterface` | 1:N | PayApp, TestPay | Shop |
| 푸시 | `Fcm/` 2종 | 미정 | 없음 | 현재 저장소 내 소비자 없음 |
| 외부 알림 | `NotificationGatewayInterface` | 1:N | EmailNotify, SendonSms, SendonTalk | Shop |
| 회원 내부 알림 | `MemberNotificationPublisherInterface`, `MemberNotification` | 컨테이너 | Core `MemberNotificationService` | Board |
| 알림 이벤트 | `CollectNotificationVariablesEvent`, `MemberNotificationPublishedEvent` | Event | — | 알림 Plugin ↔ Package |
| 본인인증 | `IdentityVerificationInterface` | 1:1 | 없음 | — |
| AI | `AI/` 5종 | 컨테이너 | Core(`CoreAiGateway` 등) | Package/Plugin |
| 카테고리 | `CategoryProviderInterface` | 전용 레지스트리 | Shop | 스킨(ViewContext) |
| FAQ | `FaqQueryInterface` | 1:1 | Faq Plugin | Package |
| 캐시 | `CachePurgerInterface` | 1:1 | 없음 | — |
| 데이터 리셋 | `DataResettableInterface`, `DataResetFilesystemInterface` | Provider 노출 + Resetter 위임 | Provider 20곳 + Core | 관리자 데이터 초기화 |
| 추적 | `TrackingKeys` | 상수 | — | VisitorStats ↔ Shop |

## Package 수준 Contract와의 관계

이 장의 계약은 모두 **Core Contract**(`Mublo\Contract\*`) — 어떤 확장이든 쓸 수 있는 전역 계약이다. 이와 별개로 Package는 자기 종속 Plugin에게만 공개하는 **Package Contract**(`packages/{Name}/Contract/Extension/*`)를 가진다.

- 예: Board의 `BoardArticleReaderInterface`·`BoardArticleCommandInterface`·`BoardExtensionApiInterface`(`packages/Board/Contract/Extension/`)
- 차이는 공개 범위다. Core Contract는 모든 확장에게, Package Contract는 그 Package의 종속 Plugin에게만 안정 API다.
- `check-extension-api` 도구가 이 경계를 검사한다 — 종속 Plugin은 부모의 `Contract\Extension\*`, `Api\DTO\*`, 공식 `Event\*`만 import할 수 있다([15. Public API](15-public-api.md)).

Board 사례의 상세 해설은 [33. Reference Packages](33-reference-packages.md) 참고.

## 새 Contract 제안 기준

판단 기준의 원문은 `docs/dev-guide/contract-system.md`다. 요약하면:

- Contract는 **pull**("데이터를 줘, 처리해줘" — 동기 호출 + 결과 반환), Event는 **push**("무언가 일어났다" — 발생 사실 통지)다. 호출해서 값을 받는 구조를 Event로 억지 구현하지 말고, 발생 후처리를 Contract로 강결합하지 말 것.
- 특정 구현체를 명시적으로 선택해야 하면(PG 선택 등) Contract, 누가 듣는지 몰라야 하면 Event. 두 패턴이 모두 필요하면 보통 "조회는 Contract / 후처리는 Event"로 나눈다.
- 인터페이스는 `src/Contract/{도메인}/{Name}Interface.php` 중립 위치에 두고, 소비자가 실제로 필요로 하는 **작은 계약만** 공개한다 — 모든 내부 Service에 인터페이스를 만들지 않는다.
- Contract는 확장 간 동기 호출 지점이므로 Event보다 강한 호환성 기준이 적용된다. 하위 호환되는 메서드 추가는 Minor, 시그니처 변경·제거는 Major다(`docs/compatibility-policy.md`의 "Contract 안정성" 절).

## 관련 문서

- [15. Public API](15-public-api.md) — 안정 API 경계 원칙과 check-extension-api
- [14. Extension Runtime](14-extension.md) — Provider boot 실패 시 격리 규칙
- [27. AI 시스템](27-ai.md) — `Contract\AI` 5종의 실행 맥락과 신뢰 경계
- [33. Reference Packages](33-reference-packages.md) — Board의 Package 수준 Contract 실례
- `docs/dev-guide/contract-system.md` — Contract 작성·등록·소비 실전 가이드 (원문)
- `docs/compatibility-policy.md` — 안정 API 목록과 Contract 변경 원칙 (진실 원천)
