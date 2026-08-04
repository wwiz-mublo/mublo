# 24. 알림

Mublo의 알림은 사이트 내부 회원 알림과 외부 발송 채널로 나뉜다. Core는 회원 알림의 저장·읽음 상태·마이페이지 목록을 소유한다. 이메일·SMS·알림톡 같은 외부 채널은 Plugin이 공급하고, 발송 트리거는 Package의 비즈니스 이벤트가 제공한다. 이 장은 두 흐름의 실제 연결 지점을 다룬다.

이 장이 다루는 기능: EmailNotify Plugin(이메일 설정·템플릿 CRUD/테스트 발송·이미지 관리·발송 이력·설치 — `plugins/EmailNotify/routes.php`), SendonSms Plugin(SMS 설정·잔액·채널/발신번호·템플릿·수동 발송·이력·설치 — `plugins/SendonSms/routes.php`), SendonTalk Plugin(카카오 알림톡 설정·채널·템플릿 등록/검수 상태/동기화·이력·설치 — `plugins/SendonTalk/routes.php`), 내부 알림 계약과 이벤트(`MemberNotification`, `MemberNotificationPublisherInterface`, `MemberNotificationPublishedEvent`), `NotificationGatewayInterface`, `CollectNotificationVariablesEvent`, FCM 계약 2종(`FcmMessageServiceInterface`, `TopicSubscriptionInterface`).

## 개요 — 내부 알림과 외부 채널의 분업

알림의 저장과 외부 발송은 서로 다른 계약이다.

| 층 | 소유자 | 실제 구성물 |
|---|---|---|
| 회원 내부 알림 | **Core** | `MemberNotificationPublisherInterface` → `MemberNotificationService`, `member_notifications`, 마이페이지 알림함 |
| 채널 게이트웨이 | **Plugin** | `NotificationGatewayInterface` 1:N 구현 — 번들 EmailNotify·SendonSms·SendonTalk |
| 템플릿·치환 변수 | **Plugin + Package 협업** | 템플릿 CRUD·발송 이력은 알림 Plugin의 관리자 화면, 변수 목록은 `CollectNotificationVariablesEvent`로 Package가 광고 |
| 발송 트리거 | **Package** | 비즈니스 이벤트(예: Shop `OrderStatusChangedEvent`) → 관리자가 설정한 액션 실행 |

### 책임과 비책임

Core는 내부 회원 알림을 직접 저장하고, 외부 채널 중 **이메일 전송로 하나만** 직접 소유한다(아래 "이메일 채널의 역할 분담"). 그 밖에는 계약(`src/Contract/Notification/`)과 `ContractRegistry`(1:N 등록·메타 스키마 검증, [16장](16-contract-catalog.md))를 제공할 뿐이며, 비즈니스 사건에 반응해 자동 발송하지 않는다. 발송 큐, 재시도, 채널 통합 발송 이력 같은 코어 수준 파이프라인도 없다 — 발송 이력은 각 알림 Plugin이 자기 로그 테이블에 기록한다(`plugins/SendonTalk/Repository/SendonTalkLogRepository.php` 등).

## 발송 흐름 실례 — Shop 주문 상태 변경

번들에서 발송이 끝까지 흘러가는 유일한 완결 사례는 Shop의 주문 상태 액션이다.

```text
[운영자] 주문 상태별 액션 설정 저장 ─── order_state_actions (JSON, Shop 설정)
                                              │
[주문 상태 변경] OrderService ── dispatch ──▶ OrderStatusChangedEvent
                                              │
[Shop] ConfigurableActionSubscriber (priority -10, 실패해도 롤백 없음)
                                              │
[Shop] NotificationActionHandler ── allMeta의 channels로 게이트웨이 선택
                                              │
[Plugin] Gateway::send(channel, templateCode, recipient, fieldValues)
                                              │
[Plugin] Service — #{field} 치환 → 실발송(API/Mailer) → 자기 이력 테이블 기록
```

다섯 단계로 나누면 다음과 같다.

1. **운영자 설정** — 관리자 주문 상태 화면(`packages/Shop/Controller/Admin/OrderStateController.php`)이 `ActionTypeRegistry`(`packages/Shop/Service/ActionTypeRegistry.php`)에서 액션 타입 목록·스키마(`getRegisteredTypes()`/`getAllSchemas()`)를 읽어 동적 폼을 만들고, 상태별 액션 설정을 Shop 설정 키 `order_state_actions`(JSON)로 저장한다(`packages/Shop/Service/ShopConfigService.php`의 `saveStateActions()`/`getStateActions()`). 저장 전에는 스키마의 `required` 필드 검증(`validateAction()`)과 중복 등록 검증(`validateDuplicates()` — 알림 액션은 `allowDuplicate() = true`라 채널·수신자별 다중 등록이 가능)을 거친다. 저장되는 구조는 `NotificationActionHandler::getSchema()`가 정의한 필드 그대로다.

   ```json
   { "paid": [ { "type": "notification", "enabled": true,
                 "channel": "alimtalk", "template_code": "order_confirmed",
                 "recipient": "orderer" } ] }
   ```
2. **이벤트 발행** — 주문 상태가 바뀌면 `packages/Shop/Service/OrderService.php`가 상태 로그를 남긴 뒤 `OrderStatusChangedEvent`를 dispatch한다.
3. **액션 구독자** — `packages/Shop/EventSubscriber/ConfigurableActionSubscriber.php`가 priority `-10`(코드 레벨 구독자보다 후순위)으로 이 이벤트를 받아, 해당 상태에 설정된 액션들을 순회하며 핸들러를 실행한다. 핸들러가 예외를 던져도 **주문 상태 변경은 롤백되지 않고 로그만 남는다** — 알림 실패가 비즈니스 트랜잭션을 깨지 않는다는 원칙이다.
4. **게이트웨이 선택** — `packages/Shop/Action/NotificationActionHandler.php`가 설정의 `channel` 값으로 게이트웨이를 찾는다. 핵심은 조회 방식이다.

```php
// packages/Shop/Action/NotificationActionHandler.php — 발췌
private function findGatewayByChannel(string $channel): ?NotificationGatewayInterface
{
    // 게이트웨이는 register()(1:N)로 등록되므로 allMeta로 조회한다.
    // has()는 bind()(1:1) 전용이라 여기서 쓰면 항상 false가 된다.
    $allMeta = $this->registry->allMeta(NotificationGatewayInterface::class);

    foreach ($allMeta as $key => $meta) {
        $channels = $meta['channels'] ?? [];
        if (in_array($channel, $channels, true)) {
            return $this->registry->get(NotificationGatewayInterface::class, $key);
        }
    }

    return null;
}
```

   등록 메타의 `channels` 배열만 보고 판단하므로 **게이트웨이 인스턴스를 만들지 않고** 후보를 거른다 — [16장](16-contract-catalog.md)에서 설명한 lazy 등록 + 메타 조회의 실전 형태다. 소비자는 1:N 등록을 `has()`로 확인하면 안 된다는 주의(코드 주석 그대로)도 여기서 나온다. 설정 폼의 채널 드롭다운(`getAvailableChannels()`)도 같은 방식으로 만들며, **등록된 게이트웨이가 제공하는 채널만 노출하고 기본/폴백 채널은 없다.** 게이트웨이 미등록이면 발송을 조용히 건너뛰고 info 로그만 남긴다.
5. **발송** — 수신자를 주문 데이터에서 결정하고(`resolveRecipient()` — orderer/recipient/admin의 전화번호 필드), 치환 변수를 만들어(`prepareFieldValues()` — 주문번호·금액·상태 라벨에 `ShipmentService` 조회로 택배사·송장번호·추적 URL까지) `$gateway->send($channel, $templateCode, $recipientPhone, $fieldValues)`를 호출한다. 반환은 예외가 아니라 `['success' => bool, 'message' => string]` 배열이며, 성공/실패 모두 로그로 남긴다.

액션 타입 자체도 확장점이다 — `ActionTypeRegistry` 주석은 Plugin이 `boot()`에서 자기 `ActionHandlerInterface` 구현을 `register()`할 수 있다고 명시한다(`packages/Shop/Action/ActionHandlerInterface.php`). 알림은 그 내장 타입 중 하나일 뿐이다.

## 채널 게이트웨이 생태계

번들 알림 Plugin 3종이 `NotificationGatewayInterface`를 1:N으로 등록한다. 서드파티 게이트웨이 구현 시 시그니처 밖의 규약 5가지 — 레지스트리 등록 형태(meta channels/label), 채널 키 표준(`alimtalk`/`sms`/`email`), **변수 치환은 게이트웨이 책임**(호출자는 fieldValues 만 전달, 사업자 한글 키 혼재 가능), 미치환 변수의 발송 실패 처리 권장, `getChannelTree()` 의 code=발송 식별자·name=표시용 — 가 인터페이스 독블록(`src/Contract/Notification/NotificationGatewayInterface.php`)에 명문화돼 있다. 이 규약만 지키면 코어·패키지 수정 없이 FSM 액션의 채널/템플릿 드롭다운과 템플릿 변수 팔레트에 자동 편입된다.

| 공급자 | 계약 | 등록 키 | 채널 메타 | 실발송 경로 |
|---|---|---|---|---|
| Core (`src/Core/Notification/`) | `NotificationGatewayInterface` | `core_email` | `['email']` | 코어 `Mailer`(서버 메일/SMTP) |
| `plugins/EmailNotify/` | `EmailTemplateProviderInterface` | `email_notify` | — (템플릿 공급) | 발송은 `core_email` 게이트웨이에 위임 |
| `plugins/SendonSms/` | `NotificationGatewayInterface` | `sendon_sms` | `['sms']` | 센드온 API(`Service/SendonApiClient.php`) |
| `plugins/SendonTalk/` | `NotificationGatewayInterface` | `sendon_talk` | `['alimtalk']` | 센드온 API + 카카오 템플릿 검수 연동 |

게이트웨이 등록은 형태가 같다.

```php
// plugins/SendonTalk/SendonTalkProvider.php — boot() 발췌
$registry->register(
    NotificationGatewayInterface::class,
    'sendon_talk',
    fn() => new SendonTalkGateway(...),   // Closure — get() 시점 lazy 생성
    ['label' => '센드온 알림톡', 'icon' => 'bi-chat-dots',
     'description' => '카카오 알림톡 발송 (센드온)', 'channels' => ['alimtalk']]
);
```

메타의 `label`·`channels`는 장식이 아니라 필수다 — `ContractRegistry`의 `REQUIRED_META_SCHEMAS`가 이 계약에 두 키를 요구하며, 누락 시 `E_USER_WARNING`을 남긴다([16장](16-contract-catalog.md)). `channels`가 비면 `findGatewayByChannel()`이 그 게이트웨이를 영원히 선택하지 못한다.

번들 알림 Plugin의 내부 구조는 동형이다. 새 채널 Plugin을 만들 때 이 구조를 그대로 참조하면 된다(EmailNotify는 Gateway 자리에 `EmailTemplateProvider`가 들어가는 것만 다르다).

| 구성물 | 역할 | SendonTalk 기준 위치 |
|---|---|---|
| Provider | 컨테이너 등록 + `boot()`에서 게이트웨이 `register()` | `plugins/SendonTalk/SendonTalkProvider.php` |
| Gateway | 계약 어댑터 — Service에 위임하는 얇은 층 | `plugins/SendonTalk/SendonTalkGateway.php` |
| Service | 설정·API 키 암호화·변수 치환·실발송·이력 기록 | `plugins/SendonTalk/Service/SendonTalkService.php` |
| Repository 4종 | config / channel / template / log | `plugins/SendonTalk/Repository/` |
| 관리자 컨트롤러 + `routes.php` | `AdminMiddleware`로 보호되는 설정·채널/템플릿·이력·설치 라우트 | `plugins/SendonTalk/routes.php` |
| `AdminMenuSubscriber` | 관리자 메뉴 등록 | `plugins/SendonTalk/AdminMenuSubscriber.php` |

Gateway가 얇은 이유는 명확하다 — 계약 표면(`send()` 등 3메서드)과 채널 고유 로직(카카오 템플릿 검수 상태, SMS 발신번호, 이메일 이미지 관리)을 분리해, 계약이 바뀌지 않는 한 Service를 자유롭게 고치기 위해서다. 예로 `SendonTalkGateway::getChannelTree()`는 채널·템플릿 트리를 만들 때 카카오 검수 상태(`kakao_status`)를 템플릿 이름에 배지로 붙여 반환한다 — 검수 승인 전 템플릿은 발송이 거부되므로(`SendonTalkService::send()`의 승인 템플릿 확인) 관리 화면에서 상태가 보여야 한다.

### 채널 트리의 3계층 — 공급·조립·훅

`getChannelTree()` 를 읽어 화면을 그리는 소비자(AutoForm 액션 설정 UI, Mshop FSM 템플릿 드롭다운)는 게이트웨이를 직접 순회하지 않고 **조립 계층**을 경유한다. 계층마다 통로가 하나씩이다(확장점 단일 원칙 — 계층이 다르면 중복 통로가 아니다):

| 계층 | 통로 | 역할 |
|---|---|---|
| 공급 | `NotificationGatewayInterface.getChannelTree()` | 벤더당 유일 통로 — 자기 채널·템플릿 서브트리 |
| 조립 | `NotificationChannelTreeBuilderInterface` (구현: 코어 `NotificationChannelTreeBuilder`) | 이메일 기본 + 레지스트리 순회를 단일 구현으로 — 소비 화면 간 조립 복제·불일치 방지 |
| 훅 | `CollectNotificationChannelTreeEvent` (코어 계약, 빌더가 조립 직후 dispatch) | 게이트웨이로 표현되지 않는 특수 공급자의 보조 공급(`addProvider`)과 조립 결과 가공(`setProviders`) — 빌더를 쓰는 모든 화면에 균일 반영 |

채널 타입 키는 코어 표준(email/sms/alimtalk)을 유지하고, UI 별 표기 변환(예: AutoForm 의 alimtalk→kakao)은 각 소비자가 한다.

### 이메일 채널의 역할 분담 — 전송로는 코어, 템플릿은 공급자

이메일만 다른 채널과 구조가 다르다. **전송로(게이트웨이)는 코어가 항상 등록하고, 내용물(템플릿)은 별도 계약의 공급자가 댄다.**

```php
// src/Core/Provider/ServiceProvider.php — ContractRegistry 팩토리
$registry->register(
    NotificationGatewayInterface::class,
    'core_email',
    fn() => new EmailNotificationGateway(
        $c->get(Mailer::class),
        $c->get(ContractRegistry::class),
        /* domainId resolver */,
        /* 도메인 정책: site_config['email_channel_enabled'] */
    ),
    ['label' => '이메일', 'icon' => 'bi-envelope', 'channels' => ['email'],
     'description' => '코어 Mailer(서버 메일/SMTP) 기반 이메일 발송 — 템플릿은 EmailTemplateProvider 계약으로 공급']
);
```

과거에는 전용 이메일 Plugin이 게이트웨이까지 등록했다. 그러자 관리자 채널 트리에는 이메일이 항상 보이는데(UI) 플러그인 미설치 도메인에서는 게이트웨이 부재로 발송이 실패하는(실행) 어긋남이 생겨, 전송로를 코어로 승격했다. 발송 여부는 도메인 설정 `email_channel_enabled`가 결정한다.

그 결과 **EmailNotify Plugin은 이 계약의 구현체가 아니라 소비자**다 — `EmailTemplateProviderInterface`(`src/Contract/Notification/`)를 구현해 템플릿·이미지·발송 이력 관리를 제공한다. 이메일 외 채널(SMS·알림톡)은 여전히 Plugin이 게이트웨이째로 공급한다.

## 템플릿 변수 협업 — CollectNotificationVariablesEvent

템플릿을 관리하는 쪽(알림 Plugin)은 어떤 치환 변수가 존재하는지 모르고, 변수 값을 만드는 쪽(Package)은 템플릿 편집 화면을 모른다. `src/Contract/Notification/CollectNotificationVariablesEvent.php`가 이 간극을 잇는 양방향 이벤트다. 방향이 통상과 반대라는 점이 핵심이다.

- **dispatch 측 = 알림 Plugin의 관리자 화면.** 채널/템플릿 편집 페이지를 열 때 이벤트를 던져 변수 픽커를 채운다 — `plugins/SendonTalk/Controller/Admin/SendonTalkController.php`, `plugins/SendonSms/Controller/Admin/SendonSmsController.php`, `plugins/EmailNotify/Controller/Admin/EmailNotifyController.php`(EmailNotify는 자체 사이트 공통 변수 `EmailNotifyService::SITE_VARIABLE_LABELS`를 먼저 깔고 이벤트 결과를 합친다).
- **구독 측 = Package.** 자기 도메인의 변수를 `addVariables(sourceKey, sourceLabel, variables)`로 등록한다.

```php
// packages/Shop/EventSubscriber/NotificationVariableSubscriber.php — 발췌
public function onCollect(CollectNotificationVariablesEvent $event): void
{
    $event->addVariables('shop', '쇼핑몰', [
        'orderer_name' => '주문인명',
        'order_no'     => '주문번호',
        // ... NotificationActionHandler.prepareFieldValues()와 동일한 필드 목록
    ]);
}
```

이 이벤트는 **광고이지 계약 강제가 아니다.** 발송 시점의 실제 값은 Package의 `prepareFieldValues()`가 만들며, 광고 목록과 발송 필드가 어긋나면 템플릿에 미치환 토큰이 남는다. 그래서 Shop 구독자 주석은 두 목록의 일치를 명시하고, 게이트웨이 쪽에는 다음 가드·규약이 있다.

- **치환 토큰 규약** — `#{field}` 토큰을 `strtr`로 일괄 치환한다. `plugins/EmailNotify/Service/EmailNotifyService.php`의 `substitute()`, `plugins/SendonTalk/Service/SendonTalkService.php`의 `substituteMessage()`가 같은 구현이다.
- **미치환 토큰 가드** — 치환 후 `#{...}` 패턴이 남으면 EmailNotify는 발송 자체를 실패 처리하고(`UNRESOLVED_VARS`), SendonTalk은 같은 결과 코드로 발송 이력에 기록한다. 변수 이름 오타가 수신자에게 원시 토큰으로 노출되는 것을 막는 장치다.
- **공통 변수 선주입** — EmailNotify는 호출자의 `fieldValues` 앞에 사이트 공통 변수(`EmailNotifyService::SITE_VARIABLE_LABELS` — 로고·사이트 제목·사이트 주소 등)를 먼저 깔고, 같은 키는 호출자 값이 우선하게 병합한다. 공통 키는 미설정이어도 빈 문자열로 반드시 채운다 — 비우면 위의 미치환 가드에 걸려 발송이 실패하기 때문이다.
- **URL 절대화** — EmailNotify는 본문의 루트상대 URL(`/storage/...`)을 발송 시점의 도메인 기준으로 절대화한다(`absolutizeUrls()`). 본문을 상대경로로 저장하므로 도메인이 바뀌어도 이미지가 깨지지 않는다.

이 이벤트가 Plugin 소유가 아니라 `src/Contract/Notification/`에 있는 이유는 알림 Plugin과 Package가 함께 사용하는 중립 경계이기 때문이다. 같은 디렉토리에는 내부 알림 저장 완료를 알리는 `MemberNotificationPublishedEvent`도 있다.

## 회원 내부 알림 센터

Package/Plugin은 `MemberNotificationPublisherInterface`를 주입받아 `MemberNotification`을 발행한다. `src/Core/Provider/ServiceProvider.php`가 이 계약을 `MemberNotificationService`에 바인딩한다. 서비스의 실제 저장 흐름은 다음과 같다.

1. 수신 회원이 존재하고 `domainId`가 일치하는지 확인한다.
2. `actorMemberId`가 있으면 실제 회원인지 확인한다.
3. `deduplicationKey`가 있으면 같은 도메인·수신자·키의 기존 알림 ID를 반환한다.
4. `MemberNotificationRepository::create()`로 `member_notifications`에 저장한다.
5. 새로 저장된 경우 `MemberNotificationPublishedEvent`를 발행한다.

`MemberNotification`은 HTML 메시지 객체가 아니다. 제목·본문은 일반 문자열이고, `targetUrl`은 `/`로 시작하되 `//`, 역슬래시, 제어 문자를 허용하지 않는 사이트 내부 상대 경로다. 이 검증은 `src/Contract/Notification/MemberNotification.php` 생성자에 있다.

현재 완결된 소비 사례는 Board 댓글 알림이다. `packages/Board/Subscriber/BoardNotificationSubscriber.php`가 `CommentCreatedEvent`를 구독해 글 작성자와 부모 댓글 작성자에게 알림을 발행하고, 자기 댓글·중복 수신자는 제외한다. Core 마이페이지는 다음 고정 라우트로 목록과 읽음 상태를 처리한다.

- `GET /mypage/notifications` — 페이지당 20건 목록과 미읽음 수
- `POST /mypage/notifications/open` — 해당 회원의 알림을 읽음 처리하고 안전한 내부 URL로 이동
- `POST /mypage/notifications/read-all` — 해당 도메인·회원의 전체 알림 읽음 처리

`MemberNotificationPublishedEvent`는 Push·메일 확장이 외부 전달을 추가할 수 있는 지점이지만 현재 저장소에는 구독자가 없다. 따라서 내부 알림을 발행했다고 외부 채널까지 자동 발송된다고 가정하면 안 된다.

## 푸시(FCM) — 계약만 코어에 있는 채널

`NotificationGatewayInterface`가 "템플릿 코드 + 수신자"로 규격화된 메시지 발송이라면, `src/Contract/Fcm/`의 두 계약은 Installation·회원·토픽 단위 Push 전송과 토픽 구독을 규격화한다.

- `FcmMessageServiceInterface` — `dispatchToInstallation`, `dispatchToMember`, `dispatchToTopic`
- `TopicSubscriptionInterface` — Installation·회원 단위 토픽 구독/해제와 구독자 ID 조회

현재 저장소에는 두 계약의 구현체와 소비자가 없으며, `ContractRegistry` 등록 방식도 정의되어 있지 않다. 별도 구현 Plugin의 이름이나 조회 방법을 현재 규약으로 단정할 수 없다.

## 확장 개발자 규약

### 새 알림 트리거를 넣을 때 (Package)

1. `ContractRegistry`를 주입받고, 채널 후보는 `allMeta(NotificationGatewayInterface::class)`의 `channels` 메타로 탐색한다. 1:N 등록이므로 `has()`로 확인하지 않는다.
2. 게이트웨이가 없으면 예외 대신 로그를 남기고 건너뛴다. 알림은 부수효과다 — 본 트랜잭션(주문 저장, 가입 완료)을 알림 실패로 깨뜨리지 않는다. Shop은 이를 이벤트 구독자 후순위 실행 + try-catch(`ConfigurableActionSubscriber`)로 한 번 더 보강한다.
3. `CollectNotificationVariablesEvent`를 구독해 자기 변수를 광고하고, 광고 목록과 발송 시 `fieldValues` 키를 반드시 일치시킨다.
4. 어떤 상황에 어떤 템플릿을 쏠지는 하드코딩하지 말고 운영자 설정으로 뺀다 — Shop의 `order_state_actions`가 선례다.

### 회원 내부 알림을 발행할 때 (Package/Plugin)

1. `MemberNotificationPublisherInterface`만 주입받고 `MemberNotificationService`·Repository·테이블을 직접 참조하지 않는다.
2. 현재 도메인의 수신 회원 ID를 사용하고, 이동 주소는 사이트 내부 상대 경로로 제한한다.
3. 같은 사건의 재처리 가능성이 있으면 안정적인 `deduplicationKey`를 지정한다. Board는 댓글 ID와 수신 관계를 조합한다.
4. 외부 Push·메일 전달이 필요하면 `MemberNotificationPublishedEvent`를 별도 구독한다. 내부 알림 발행 자체는 외부 발송을 보장하지 않는다.

### 새 채널을 공급할 때 (Plugin)

1. 독립 Plugin(`plugins/{Name}/`)으로 만들고 `NotificationGatewayInterface` 3메서드를 구현한다. `send()`는 예외를 밖으로 던지지 말고 `['success' => bool, 'message' => string]`을 반환한다(인터페이스 규약).
2. Provider `boot()`에서 `register(계약, '고유 키', Closure, 메타)`로 등록한다. 메타에 `label`·`channels`는 필수이고, 키가 겹치면 `DuplicateRegistryException`으로 자기 Plugin의 boot가 실패한다([16장](16-contract-catalog.md)).
3. 템플릿·발송 이력 관리자 화면을 갖추고, 템플릿 편집 화면에서 `CollectNotificationVariablesEvent`를 dispatch해 변수 픽커를 제공한다. 치환 토큰은 번들과 같은 `#{field}` 규약을 따르는 것이 좋다 — Package가 채널을 갈아타도 템플릿 변수 표기가 유지된다.
4. 전체 구조(Provider/Gateway/Service/Repository/관리자 라우트)는 번들 3종 중 하나를 그대로 참조한다. Plugin 개발 일반 절차는 [30. Plugin Guide](30-plugin-guide.md)를 따른다.

### Best Practice / Anti Pattern

**Best Practice**

- 게이트웨이 후보 판별과 채널 드롭다운 구성은 메타(`allMeta()`)로만 하고, 인스턴스 생성(`get()`)은 실제 발송 직전으로 미룬다 — `NotificationActionHandler`가 선례다. lazy 등록의 이점이 유지된다.
- 알림 발송은 비즈니스 이벤트의 **후순위 구독자**에서 실행하고 실패를 삼킨다(로그만). Shop의 `ConfigurableActionSubscriber`(priority `-10`, try-catch)가 표준형이다.
- 변수 광고 구독자와 발송 필드 생성 코드는 같은 Package 안에서 나란히 관리한다 — Shop은 두 파일 주석으로 상호 참조를 명시한다(`packages/Shop/EventSubscriber/NotificationVariableSubscriber.php`).

**Anti Pattern**

- Package가 특정 알림 Plugin의 Service를 직접 참조하는 것 — 채널 교체가 불가능해지고 [15. Public API](15-public-api.md)의 경계 위반이다. 반드시 `NotificationGatewayInterface`로만 발송한다.
- 1:N 등록 계약을 `has()`로 확인하는 것 — `has()`는 `bind()`(1:1) 전용이라 항상 false다. 1:N은 `hasKey()`/`allMeta()`를 쓴다.
- 게이트웨이 미등록 시 예외를 사용자에게 전파하는 것 — 알림 채널은 설치 선택 사항이므로, 소비자는 미등록을 정상 상태로 취급해야 한다.

## 관련 문서

- [16. Contract 카탈로그](16-contract-catalog.md) — 내부·외부 알림 계약, FCM 2계약, ContractRegistry 등록 모드·메타 스키마
- [08. Event](08-event.md) — EventDispatcher와 Subscriber 규약
- [12. Plugin](12-plugin.md) · [30. Plugin Guide](30-plugin-guide.md) — 채널 Plugin을 만드는 자리와 절차
- [33. Reference Packages](33-reference-packages.md) — 번들 확장 카탈로그
- `docs/dev-guide/contract-system.md` — Contract vs Event 판단 기준과 `NotificationGatewayInterface` 원문 해설
