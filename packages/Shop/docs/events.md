# Shop 이벤트 전수 조사 및 확장 권고

조사일: 2026-07-22
갱신일: 2026-07-24
최초 조사 기준: Git commit `bd7fdf4`

조사 범위: `packages/Shop`의 이벤트 클래스, 발행 지점, 구독자, 주요 서비스의 공개 변경 흐름

## 1. 결론

현재 Shop Package가 자체 발행하는 이벤트는 **14종**, 운영 코드의 정적 이벤트 생성 지점은 **17곳**, 발행 서비스는 **8개**다.

- 상품: `ProductChangedEvent`
- 카테고리: `CategoryUpdatedEvent`, `CategoryDeletedEvent`
- 기획전: `ExhibitionCreatedEvent`, `ExhibitionUpdatedEvent`, `ExhibitionDeletedEvent`
- 주문: `OrderStatusChangedEvent`, `OrderItemStatusChangedEvent`
- 결제: `PaymentCompletedEvent`, `PaymentMismatchEvent`
- 배송: `ShipmentRegisteredEvent`, `ShipmentStatusChangedEvent`, `ShipmentDeletedEvent`
- 클레임: `ClaimStatusChangedEvent`

현재 이벤트는 전부 작업이 끝난 뒤 알리는 **사후 이벤트**다. 상품 조회, 장바구니 담기, 체크아웃, 주문 생성 전에 외부 플러그인이 작업을 거부하거나 추가 조건을 요구할 수 있는 사전 이벤트는 없다.

최초 조사에서 확인된 다음 두 가지 전달 공백은 2026-07-24 구현으로 보완했다.

- 주문상품 상태가 모두 같아져 주문 헤더가 자동 동기화될 때도 CAS 성공 요청이 `OrderStatusChangedEvent`를 한 번 발행한다.
- 결제 완료 후처리는 주문번호별 `shop_payment_completions` 원장과 lease로 단일 실행되며, `PAID` 재요청이 실패·중단된 후처리를 멱등하게 재개한다.
- 주문상품 상태 변경은 별도 이벤트를 먼저 발행하고, 주문 헤더가 실제로 바뀐 경우에만 헤더 이벤트를 추가 발행한다. 상품 이벤트에서는 `ItemActionHandlerInterface`를 명시적으로 구현한 Action만 실행해 알림·웹훅의 중복 실행을 막는다.
- 운영자 설정 Action은 `shop_action_executions` 실행 이력과 영구 `action_id`로 중복을 막고 실패를 재시도한다. 웹훅은 요청 기반 비동기 큐로 처리한다.

또한 신규 이벤트에 내부 구독자를 연결하기 전에 기존 직접 후처리와의 중복 여부를 전수 대조해야 한다. 후처리마다 단일 소유자와 멱등 키를 확정하지 않은 상태에서는 이벤트를 운영 처리에 사용하지 않는다.

따라서 다음 확장의 최우선 순위는 아래 네 구간이다.

1. 상품 접근 판정: 회원 전용, 성인 인증, 회원 등급, 포인트 차감형 열람
2. 장바구니 변경: 담기·수량 변경·삭제 전후
3. 체크아웃과 주문 생성: 최종 가격·재고·접근 권한 재검증, 주문 생성 전후
4. 결제 이후 운영 흐름: 실패·취소·환불과 세분화된 반품 수명주기

## 2. 조사 방법과 집계 기준

다음 항목을 서로 대조했다.

- `packages/Shop/Event/*Event.php`의 이벤트 클래스 전수
- Shop 운영 코드의 `dispatch(...)`, `EventDispatcher`, `EventInterface` 사용 전수
- `getSubscribedEvents()`를 구현한 Shop 구독자 전수
- `Service/*Service.php`의 공개 메서드와 상태 변경 흐름 전수
- Controller, 상태 액션 핸들러, 이벤트 구독자가 Service/Repository를 통해 수행하는 변경 흐름 대조
- 상품, 카테고리, 기획전, 장바구니, 바로구매, 주문, 주문상품, 결제, 환불, 배송, 쿠폰, 포인트, 후기, 문의, 위시리스트, 옵션 프리셋, 설정 변경 흐름 대조

테스트 코드에서 이벤트 객체를 직접 만드는 부분은 발행 지점 수에서 제외했다. 서비스의 공통 `dispatch()` 보조 메서드도 실제 이벤트 발생으로 세지 않았다.

운영 코드의 실제 정적 이벤트 생성 및 발행 지점은 **16곳**이다. 재시도용 `ActionExecutionService::rebuildEvent()`는 과거 payload를 복원할 뿐 새 도메인 이벤트를 발행하지 않으므로 집계에서 제외했다.

## 3. 현재 발행 이벤트 전체 목록

| 이벤트 | 실제 발행 위치 | 발행 시점 | 주요 payload | Shop 내부 구독자 |
|---|---|---|---|---|
| `ProductChangedEvent` | `ProductService` 4곳 | 생성·수정·단건 삭제·일괄 삭제 성공 후 | `domainId`, `goodsIds`, `changeType` | `BlockCacheInvalidateSubscriber` |
| `CategoryUpdatedEvent` | `CategoryService::updateItem()` | 이름 변경과 경로명 갱신 후 | `domainId`, `categoryCode`, `categoryName` | `CategoryMenuSubscriber` |
| `CategoryDeletedEvent` | `CategoryService::deleteItem()` | 카테고리 삭제 후 | `domainId`, `categoryCode` | `CategoryMenuSubscriber` |
| `ExhibitionCreatedEvent` | `ExhibitionService::create()` | 기획전 생성 후 | `domainId`, `exhibitionId`, `title`, `slug`, 계산 URL | `ExhibitionMenuSubscriber` |
| `ExhibitionUpdatedEvent` | `ExhibitionService::update()` | 기획전 수정 후 | `domainId`, `exhibitionId`, `title`, 변경 전·후 URL | `ExhibitionMenuSubscriber` |
| `ExhibitionDeletedEvent` | `ExhibitionService::delete()` | 기획전 삭제 후 | `domainId`, `exhibitionId`, `slug`, 계산 URL | `ExhibitionMenuSubscriber` |
| `OrderStatusChangedEvent` | `OrderService::dispatchStatusChanged()` | 일반 또는 자동 상태 CAS 갱신 및 주문 로그 기록 후 | 주문번호, 전후 상태·라벨·액션, 복호화된 주문 배열 | 상태 액션, 쿠폰 복원, 포인트 복원 |
| `OrderItemStatusChangedEvent` | `OrderService::dispatchItemStatusChanged()` | 상품 상태·로그 반영 후, 헤더 자동 집계 전 | 주문번호, 주문상품 ID, 전후 상태·라벨·액션, 주문·상품 배열 | 상품 단위를 명시 지원하는 설정형 Action |
| `PaymentCompletedEvent` | `PaymentCompletionService::process()` | 거래 기록·장바구니·쿠폰·포인트 필수 후처리 성공 후 | `eventId`, `domainId`, 주문번호, PG 키, 거래 ID, 검증 데이터 | Shop 내부 구독자 없음(외부 확장 알림) |
| `PaymentMismatchEvent` | `PaymentService` 1곳 | PG 결제 성공 후 주문을 `PAID`로 바꾸지 못했을 때 | 주문번호, PG 키, 거래 ID, 실패 사유 | 관리자 메모 및 critical 로그 |
| `ShipmentRegisteredEvent` | `ShipmentService::registerShipment()` | 송장 등록 성공 후 | 송장 ID, 주문번호, 배송 배열 | 없음(플러그인 확장 지점) |
| `ShipmentStatusChangedEvent` | `ShipmentService::updateStatus()` | 허용 전이와 DB 반영 성공 후 | 송장 ID, 주문번호, 전후 배송 상태, 배송 배열 | 없음(플러그인 확장 지점) |
| `ShipmentDeletedEvent` | `ShipmentService::deleteShipment()` | 송장 삭제 성공 후 | 송장 ID, 주문번호, 삭제 전 배송 배열 | 없음(플러그인 확장 지점) |
| `ClaimStatusChangedEvent` | `ExchangeService` | 교환 신청 생성 또는 교환 FSM 트랜잭션 완료 후 | 클레임 ID, `domainId`, 주문번호, 주문상품 ID, 전후 클레임 상태, 개인정보를 제외한 클레임 스냅샷 | 없음(플러그인 확장 지점) |

`ClaimStatusChangedEvent`는 주문·주문상품 상태 이벤트와 별개의 계약이다. 교환 처리는 주문 FSM 상태를 바꾸지 않으므로 운영자가 주문 상태에 설정한 Action을 재실행하지 않는다. 교환 전용 설정은 알림·웹훅만 허용하고 `claimId + newStatus + actionId`로 중복을 막는다. 외부 구독자는 Shop 내부 재고 처리를 반복하지 않아야 한다.

### 3.1 `ProductChangedEvent` — 4개 발행 지점

발행 조건은 다음과 같다.

- 상품 생성 트랜잭션 커밋 후 `created`
- 상품 수정 트랜잭션 커밋 후 `updated`
- 단건 삭제 트랜잭션 커밋 후 `deleted`
- 일괄 삭제 트랜잭션 커밋 후 `deleted`

내부 구독자는 해당 상품이 포함된 수동 상품 블록 캐시를 무효화한다. 생성과 삭제는 자동 진열 결과의 구성도 달라질 수 있으므로 자동 상품 블록에도 영향을 준다.

제약:

- 변경 필드, 변경 전후 값, 상품 스냅샷은 제공하지 않는다.
- 상품 상세 조회와 조회수 증가는 이벤트를 발행하지 않는다.
- 상품 접근 허용 여부를 묻는 사전 이벤트가 아니다.

### 3.2 카테고리 이벤트 — 2개 발행 지점

`CategoryUpdatedEvent`는 이름이 입력된 수정에서만 발행된다. 카테고리 메뉴 라벨과 하위 경로명을 갱신하는 용도다. 회원 허용 등급, 활성 여부, 성인 여부만 바꾼 수정에서는 발행되지 않는다.

`CategoryDeletedEvent`는 삭제 후 메뉴에서 해당 카테고리를 제거하는 용도다.

발행되지 않는 변경:

- 카테고리 생성
- 트리 저장 및 순서·부모 변경
- `allow_member_level`, `is_active`, `is_adult` 정책 변경

특히 위 접근 정책 필드는 관리자와 Entity에는 존재하지만, 조사 시점 기준 프론트 상품 조회·장바구니·주문 흐름에서 실제 접근 차단에 사용되지 않는다.

### 3.3 기획전 이벤트 — 3개 발행 지점

기획전 생성·수정·삭제 후 메뉴 항목을 생성, 갱신, 제거한다. 수정 이벤트는 슬러그 변경에 대응하기 위해 이전 URL과 새 URL을 모두 노출한다.

발행되지 않는 변경:

- 기획전 상품/카테고리 항목 추가
- 항목 삭제
- 항목 전체 동기화 및 노출 순서 변경
- 기획전 상세 조회

### 3.4 `OrderStatusChangedEvent` — 1개 공통 생성 지점

`OrderService::updateStatus()`가 다음 순서로 처리한다.

1. 현재 상태 조회 및 FSM 전이 검증
2. 기대 상태를 조건으로 한 CAS 상태 갱신
3. 전후 라벨을 포함한 주문 로그 기록
4. DB 커밋 후 `OrderStatusChangedEvent` 발행

`dispatchStatusChanged()`가 이벤트 payload 생성과 개인정보 복호화 규칙을 단일 소유한다. `updateStatus()`를 경유하는 결제 완료, 취소, 반품, 배송, 구매확정 등의 일반 전이와 주문상품 상태 기반 자동 전이가 이 헬퍼를 공유한다.

`autoSyncOrderStatus()`는 아이템 집계 결과를 신뢰해야 하므로 FSM 그래프 검증은 우회하지만, 이전 헤더 상태를 기대값으로 사용하는 CAS를 수행한다. CAS 승자만 주문 로그와 이벤트를 만들고 패자는 조용히 종료한다.

내부 처리:

- `ConfigurableActionSubscriber`: 관리자 설정의 상태별 액션 실행(우선순위 `-10`)
- `CouponRestoreSubscriber`: 취소·반품 상태에서 쿠폰 복원
- `PointPaymentSubscriber`: 취소·반품 상태에서 사용 포인트 복원
- 설정형 액션 핸들러: 알림, 포인트, 재고, 웹훅, 구매확정 등

주의할 점:

- 이벤트의 `order` 배열은 상태 변경 전에 읽은 Entity를 `toArray()` 한 뒤 개인정보를 복호화한 값이다. 주문자·수령인 이름, 전화, 이메일, 전체 배송지 같은 개인정보가 포함될 수 있다.
- 배열 안의 `order_status`는 변경 전 값일 수 있다. 새 상태는 반드시 `getNewStateId()`를 사용해야 한다.
- `domainId` 전용 getter가 없고 주문 배열에 간접 포함된다.
- 상태 갱신과 로그 기록은 하나의 DB 트랜잭션이며, 외부 Action은 커밋 뒤 실행한다.
- 상품 상태 이벤트를 먼저 발행하고, 전체 상품이 동일 상태가 되어 헤더 CAS까지 성공한 경우에만 헤더 이벤트가 이어진다.
- 상품 이벤트는 상품 단위를 명시 지원하는 Action만 실행한다. 현재 내장 구현은 `stock_restore`이며 주문 단위 알림·웹훅·포인트 Action은 실행하지 않는다.

### 3.5 송장·배송 이벤트 — 3개 발행 지점

송장 등록, 배송 상태 변경, 송장 삭제 성공 후 각각 사후 이벤트를 발행한다. 주문 FSM과 배송 FSM을 코어에서 강제로 결합하지 않는다. 배송완료를 특정 주문 상태로 연결하려면 운영자 설정 또는 플러그인이 이벤트를 구독해 정책을 결정해야 한다. 송장 수정은 아직 전용 이벤트가 없다.

### 3.6 결제 이벤트 — 2개 공통 생성 지점

정상 PG 결제에서는 다음 순서다.

1. PG 검증과 주문번호·금액 검증
2. 주문번호를 멱등 키로 `shop_payment_completions` 원장을 `PENDING` 상태로 준비
3. 주문 상태를 `PAID`로 CAS 전이하고 `OrderStatusChangedEvent` 발행
4. 원장 처리권을 lease로 획득해 결제 트랜잭션 기록, 실제 결제수단 반영, 장바구니 정리, 쿠폰 확정, 포인트 차감
5. 필수 내부 후처리가 모두 성공하면 안정적인 `eventId`로 `PaymentCompletedEvent` 발행 후 원장을 `COMPLETED`로 변경

클라이언트 검증과 PG 콜백이 동시에 들어오면 두 요청이 같은 주문 원장을 사용한다. 주문 상태 CAS와 원장의 처리 lease 때문에 한 요청만 필수 후처리를 실행하며, 다른 요청은 진행 중 또는 완료 상태를 멱등 성공으로 반환한다.

`PAID` 전이 뒤 프로세스가 중단되면 다음 프론트 검증, PG 콜백 재전송 또는 0원 결제 재요청이 `PENDING`·`FAILED` 원장을 다시 처리한다. `PROCESSING` 상태에서 중단된 경우 5분 lease가 만료된 뒤 재획득할 수 있다. 재시도 과정의 장바구니 상태 변경, 같은 주문의 쿠폰 재마킹, 포인트 원장 처리는 각각 멱등하게 동작한다.

0원 결제도 `PAID` 전이 후 같은 `PaymentCompletedEvent`를 사용한다. 이때 `pgKey`와 `transactionId`는 빈 문자열이고 `verifyData.zero_amount`가 `true`다.

PG 결제는 성공했지만 주문 상태 전이가 실패하고 최신 상태도 `PAID`가 아니면 `PaymentMismatchEvent`를 발행한다. 이 경우 결제 자체는 성공했기 때문에 호출 결과는 성공으로 반환하면서 운영자 개입용 메모와 critical 로그를 남긴다.

현재 `PaymentCompletedEvent`는 필수 내부 후처리가 완료됐다는 의미다. 장바구니·쿠폰·포인트는 이벤트 구독자가 아니라 `PaymentCompletionService`가 단일 소유하며, 이벤트는 알림·분석·CRM 같은 외부 확장에 사용한다.

`PaymentCompletedEvent`는 `eventId`, `domainId`, 발생 시각, 계약 버전을 직접 제공한다. 금액·통화·결제수단은 아직 정규화된 전용 getter가 아니라 PG별 `verifyData` 배열에 일부 의존한다. `PaymentMismatchEvent`는 여전히 `domainId`를 직접 제공하지 않는다.

## 4. 현재 이벤트 전달 보장과 한계

### 4.1 모두 사후·best-effort 이벤트다

13개 이벤트는 모두 `AbstractEvent`만 상속하고 `FailFastEventInterface`를 구현하지 않는다. 이벤트 리스너에서 일반 예외가 발생하면 `EventDispatcher`는 이를 기록하고 다음 흐름을 계속한다. PHP `Error` 계열은 다시 던진다.

따라서 현재 이벤트는 캐시 무효화, 메뉴 동기화, 알림 같은 후처리에는 적합하지만 아래 용도로 사용하면 안 된다.

- 조회 또는 구매를 반드시 차단해야 하는 권한 판정
- 결제·재고·포인트처럼 실패 시 원 작업도 실패해야 하는 원자적 처리
- 이벤트만 받으면 반드시 한 번 실행됐다고 가정하는 회계 처리

`stopPropagation()`은 뒤의 리스너 실행만 중단한다. 현재 생산자는 이벤트 반환값이나 전파 중단 여부를 검사하지 않으므로 원래 작업을 취소하지 않는다.

### 4.2 발행기 주입은 선택형이다

상품, 카테고리, 기획전, 주문, 결제 서비스는 선택형 `EventDispatcher`를 사용한다. 정상 Provider 부팅에서는 발행기가 주입되지만, 직접 서비스를 생성하면서 발행기를 생략하면 해당 이벤트는 조용히 전달되지 않는다. 다만 결제 필수 후처리는 `PaymentCompletionService`의 필수 의존성으로 분리돼 선택형 발행기에 의존하지 않는다.

`CouponService`, `OptionPresetService`도 같은 발행 보조 메서드를 가지고 있으나 실제 이벤트를 한 번도 만들지 않는다. `RefundService`는 발행기를 주입받지만 사용하지 않는다. 배송 이벤트는 배송비 템플릿이 아니라 실제 송장을 관리하는 `ShipmentService`가 발행한다.

### 4.3 트랜잭션 경계가 일정하지 않다

- 상품 생성·수정·삭제 이벤트: DB 커밋 후 발행
- 주문 상태 이벤트: 상태 변경과 로그를 트랜잭션으로 커밋한 뒤 발행
- 카테고리·기획전 이벤트: 저장 성공 후 발행, 명시적 트랜잭션 없음
- 결제 완료 이벤트: 외부 PG 승인과 `PAID` 전이를 DB 트랜잭션 하나로 묶을 수는 없지만, `PAID` 전 원장을 먼저 준비하고 필수 후처리 완료 상태를 별도로 기록
- 결제 완료 재시도: 원장 lease와 기존 멱등 Service API로 중단 지점부터 안전하게 재실행. 독립 작업자가 아니라 다음 검증·콜백 요청이 재개하는 구조

결제 완료 경로는 `shop_payment_completions`의 `PENDING`·`PROCESSING`·`COMPLETED`·`FAILED` 상태로 주문 상태와 후처리 완료를 구분한다. 장시간 재시도 요청이 오지 않는 실패를 자동 복구하려면 이 원장을 주기적으로 처리하는 작업자를 추가해야 한다. 향후 금전·재고 이벤트도 단순 동기 이벤트만 늘리기보다 outbox와 멱등 소비를 함께 검토해야 한다.

## 5. 이벤트가 없는 주요 변경 흐름

아래는 서비스의 공개 변경 메서드를 기준으로 확인한 공백이다.

| 영역 | 현재 이벤트가 없는 대표 흐름 | 영향 |
|---|---|---|
| 상품 접근 | 상세 조회, 조회수 증가, 목록 노출 | 회원 전용·성인 전용·유료 열람 플러그인이 공통으로 개입할 곳이 없음 |
| 장바구니 | 담기/바로구매, 옵션 변경, 수량 변경, 삭제, 가격 갱신 | 번들, 제한 수량, 회원 전용, 장바구니 리마케팅 확장이 어려움 |
| 체크아웃 | 체크아웃 준비, 선택 저장, 합계 재계산 | 최종 접근 정책과 외부 프로모션 검증 지점이 없음 |
| 주문 | 주문 생성, 주문상품 생성 | 주문 전 차단과 주문 생성 후 외부 연동 지점이 없음 |
| 주문상품 | 전용 취소·반품 수명주기 이벤트 | 통합 상태 이벤트는 생겼지만 취소/반품 사유·금액 전용 계약은 아직 없음 |
| 결제 | 결제 준비, 확정 실패, 사용자 취소, PG 취소 성공/실패 | 실패 분석, 복구, 외부 회계 연동에 공백이 있음 |
| 환불 | 내부 환불, 외부 환불 기록, 부분 환불 | 환불 완료·실패·부분 환불을 외부 플러그인이 알 수 없음 |
| 송장/배송 | 송장 정보 수정 | 등록·삭제·상태 이벤트는 제공하지만 송장번호/택배사 수정 전용 이벤트는 아직 없음 |
| 쿠폰 | 정책 CRUD, 발급, 사용 확정, 복원, 프로모션 코드, 만료 | CRM과 캠페인 분석 연동 지점이 없음 |
| 포인트 | 적립, 사용, 환불, 만료 | 외부 멤버십 원장과 동기화하기 어려움 |
| 후기 | 작성·수정·노출 변경·삭제·답변 | 보상, 검수, 알림 플러그인 지점이 없음 |
| 문의 | 작성·수정·답변·삭제 | 상담/알림 연동 지점이 없음 |
| 위시리스트 | 추가·삭제 | 관심상품 캠페인과 분석 지점이 없음 |
| 기획전 항목 | 항목 추가·삭제·동기화 | 기획전 캐시 및 외부 피드 동기화 지점이 없음 |
| 운영 설정 | Shop 설정, 상태 액션, 배송 템플릿, 등급가격, 옵션 프리셋, 상품정보 템플릿 | 캐시·외부 설정 동기화 지점이 없음 |

Shop이 코어 이벤트를 **구독**하는 경우도 있다. 관리자 메뉴, 로그인 폼, 알림 변수, 프레임 변수, 회원 가입·로그인·수정, 도메인 생성, 통합 검색, 마이페이지 구성 이벤트가 이에 해당한다. 이들은 Shop Package가 발행하는 이벤트 수에는 포함하지 않았다.

## 6. 추가 권고 이벤트

### P0 — 상품 접근, 장바구니, 체크아웃, 주문 생성

#### `ProductAccessCheckingEvent`

상품 데이터의 외부 노출이나 구매 동작 전에 권한을 판정하는 최우선 사전 이벤트다.

권장 발생 지점:

- 상품 상세: 최소 상품 식별정보 조회 후, 조회수 증가와 상세 콘텐츠 조립 전
- 장바구니/바로구매: 상품·옵션 확인 후, 장바구니 또는 세션 기록 전
- 체크아웃: 선택 상품 재조회 후, 주문서 표시 전
- 주문 생성: 주문상품 저장 직전의 마지막 방어선

권장 payload:

- `domainId`
- 개인정보가 없는 readonly `ProductSnapshot`
- `intent`: `VIEW`, `ADD_TO_CART`, `BUY_NOW`, `CHECKOUT`, `PURCHASE`
- `actor`: 로그인 여부, 회원 ID, 회원 등급, 성인 인증 여부
- `requestId` 또는 `accessAttemptId`
- 명시적 결정 객체: `allow()`, `deny(code, message)`, 필요 조건 목록

운영 원칙:

- `stopPropagation()`만으로 차단하지 말고, 생산자가 최종 `isDenied()`를 반드시 검사한다.
- 리스너 실패가 접근을 허용하는 결과가 되지 않도록 fail-closed와 `FailFastEventInterface` 적용을 검토한다.
- 카테고리의 `allow_member_level`, `is_active`, `is_adult`는 기본 Shop 정책이 먼저 판정해야 한다. 플러그인은 이를 대체하기보다 추가 정책을 제공한다.
- 여러 카테고리 경로가 가능한 경우 적용 정책의 우선순위와 합성 규칙을 고정해야 한다.

성인 전용·회원 전용은 이 이벤트에서 거부할 수 있다. 포인트 차감형 열람은 재시도나 새로고침으로 중복 차감되지 않도록 `accessAttemptId`와 상품·회원 기준 멱등 키가 필요하다. 차감 리스너가 직접 임의 처리하기보다, 이벤트에 `requirePointCharge(amount, policyKey)` 같은 요구 조건을 추가하고 Shop의 접근 조정기가 노출 전에 이를 확정하는 방식이 안전하다.

#### `ProductAccessGrantedEvent`와 `ProductViewedEvent`

- `ProductAccessGrantedEvent`: 모든 정책과 필수 비용 처리가 끝나 실제 콘텐츠를 반환해도 되는 시점
- `ProductViewedEvent`: 상세 ViewModel 조립이 끝난 뒤의 best-effort 분석 이벤트

유료 열람은 `Granted` 이전에 확정돼야 하고, 단순 조회 분석·추천·최근 본 상품은 `Viewed`를 사용해야 한다. 조회수 증가도 장기적으로는 접근 허용 뒤 한 곳에서 처리하는 것이 일관적이다.

#### 장바구니 전후 이벤트

- `CartItemAddingEvent` / `CartItemAddedEvent`
- `CartItemUpdatingEvent` / `CartItemUpdatedEvent`
- `CartItemRemovingEvent` / `CartItemRemovedEvent`
- 필요 시 `CartPriceRefreshingEvent` / `CartPriceRefreshedEvent`

사전 이벤트는 상품 제한, 최소·최대 수량, 묶음 구성, 회원 정책을 검사한다. 사후 이벤트는 리마케팅, UI 카운트, 외부 장바구니 동기화에 사용한다. 직접구매는 `cart`와 구별되는 `intent`를 반드시 제공한다.

#### 체크아웃·주문 이벤트

- `CheckoutStartingEvent`: 체크아웃 진입 전 차단 및 요구 조건 수집
- `CheckoutPreparedEvent`: 서버가 상품·가격·배송비를 다시 계산한 뒤
- `OrderCreatingEvent`: DB 쓰기 전 최종 검증, fail-fast
- `OrderCreatedEvent`: 주문과 주문상품 트랜잭션 커밋 후

`OrderCreatedEvent`는 결제완료와 다르다. PG 주문은 `ATTEMPTING`, 무통장 주문은 `RECEIVED`로 생성되므로 생성 이벤트에 초기 상태와 결제 방식이 포함돼야 한다.

### P1 — 결제, 환불, 주문상품, 배송

#### 결제·환불

- `PaymentPreparedEvent`: PG 요청 정보 준비 성공 후
- `PaymentFailedEvent`: 검증이 확정적으로 실패한 경우. 단순 결제창 이탈과 구분
- `PaymentCancelledEvent`: PG 취소 성공 및 내부 거래 기록 후
- `PaymentCancelFailedEvent`: 외부 취소 실패 또는 내부 기록 불일치
- `RefundCompletedEvent`: 전액·부분 환불이 내부 원장에 확정된 뒤
- `RefundFailedEvent`: 외부 환불 또는 내부 반영 실패
- `ExternalRefundRecordedEvent`: 관리자에 의한 외부 환불 기록 후

모든 금전 이벤트에는 `domainId`, 주문번호, PG 키, 거래 ID, 금액, 통화, 부분/전액 구분, 원거래 ID, 멱등 키가 필요하다. 원본 PG 응답 전체를 장기 공개 계약으로 삼지 않는 것이 좋다.

#### 주문상품·반품

- `OrderItemStatusChangedEvent` — **완료**
- `OrderItemCancelledEvent`
- `ReturnRequestedEvent`
- `ReturnApprovedEvent`
- `ReturnRejectedEvent`
- `ExchangeRequestedEvent` 및 필요한 교환 처리 이벤트

주문 헤더 이벤트와 주문상품 이벤트의 발생 순서를 정해야 한다. 권장은 상품 이벤트를 먼저 확정한 뒤, 모든 상품 상태를 집계해 헤더가 바뀌면 헤더 이벤트를 발행하는 것이다.

#### 송장·배송

- `ShipmentRegisteredEvent` — **완료**
- `ShipmentUpdatedEvent`
- `ShipmentDeletedEvent` — **완료**
- `ShipmentStatusChangedEvent` — **완료**

택배사 웹훅, 고객 배송 알림, 외부 풀필먼트에 필요한 주문번호, 주문상품 ID, 송장 ID, 택배사, 송장번호, 전후 배송 상태를 제공한다. 송장번호가 개인정보 또는 보안 식별자로 취급될 수 있으므로 공개 범위를 정해야 한다.

### P2 — 카탈로그, 프로모션, 고객 콘텐츠

- 카테고리: `CategoryCreatedEvent`, `CategoryPolicyChangedEvent`, `CategoryTreeChangedEvent`
- 기획전: `ExhibitionItemsChangedEvent`
- 쿠폰: `CouponIssuedEvent`, `CouponUsedEvent`, `CouponRestoredEvent`, `CouponExpiredEvent`
- 포인트: `PointEarnedEvent`, `PointUsedEvent`, `PointRefundedEvent`, `PointExpiredEvent`
- 후기: `ReviewCreatedEvent`, `ReviewUpdatedEvent`, `ReviewDeletedEvent`, `ReviewRepliedEvent`
- 문의: `InquiryCreatedEvent`, `InquiryAnsweredEvent`, `InquiryDeletedEvent`
- 위시리스트: `WishlistAddedEvent`, `WishlistRemovedEvent`

고빈도 이벤트는 동기 리스너가 응답시간에 직접 영향을 주지 않도록 outbox/queue 연계를 고려한다.

### P3 — 관리자 설정과 제작 도구

- `ShopConfigChangedEvent`
- `OrderStateActionsChangedEvent`
- `ShippingTemplateChangedEvent`
- `LevelPricingChangedEvent`
- `OptionPresetChangedEvent`
- `ProductInfoTemplateChangedEvent`

이 그룹은 즉시 필수는 아니지만 외부 캐시, 관리자 감사 기록, 배포 자동화 플러그인을 만들 때 유용하다.

## 7. 이벤트 계약 설계 원칙

### 사전 이벤트와 사후 이벤트를 이름으로 구분

- 사전: `*Checking`, `*Starting`, `*Creating`, `*Changing`
- 사후: `*Checked`, `*Started`, `*Created`, `*Changed`, `*Completed`, `*Failed`

사전 이벤트는 어떤 쓰기도 하기 전에 발행하고 생산자가 결정을 검사한다. 사후 이벤트는 가능한 한 트랜잭션 커밋 뒤 발행한다.

### 사전 차단은 명시적 결정 계약 사용

최소한 다음 API가 필요하다.

```php
$event->deny('ADULT_VERIFICATION_REQUIRED', '성인 인증이 필요합니다.');
$event->isDenied();
$event->getDenialCode();
$event->getDenialMessage();
```

`stopPropagation()`은 리스너 순회 제어로만 사용하고 접근 허용/거부와 혼용하지 않는다.

### 공개 payload는 Snapshot으로 제한

- Entity나 저장소 배열을 그대로 노출하지 않는다.
- `domainId`, 안정적인 식별자, 이벤트 발생 시각, 계약 버전을 공통 제공한다.
- 금전 이벤트에는 멱등 키와 상관관계 ID를 제공한다.
- 주문 개인정보는 기본 Snapshot에서 제외한다. 꼭 필요한 구독자에는 별도 권한 계약이나 최소 필드 accessor를 제공한다.
- 변경 이벤트에는 `changedFields` 또는 전후 Snapshot을 제공하되 민감정보는 제외한다.

현재 `OrderStatusChangedEvent::getOrder()`의 복호화된 전체 배열은 Shop 내부 알림 호환을 위해 유지하더라도 외부 플러그인의 장기 공개 계약으로 권장하지 않는다. 기존 `Api/DTO/OrderSnapshot`처럼 개인정보가 제거된 DTO를 새 이벤트 버전에 사용하는 편이 안전하다.

### 발행 위치는 Service 계층으로 통일

Controller나 Repository에서 발행하면 관리자, 프론트, 콜백, CLI 경로마다 누락되기 쉽다. 실제 상태 변경을 소유한 Service에서 한 번만 발행한다.

### 금전·재고 후처리는 전달 보장 분리

동기 이벤트의 best-effort 구독자만으로 회계적 정확성을 보장하지 않는다. 필수 내부 처리와 외부 확장 알림을 구분한다.

- 필수 처리: 트랜잭션 또는 명시적 오케스트레이션, 실패 전파, 멱등 보장
- 외부 알림: 커밋 후 이벤트, outbox, 재시도 가능한 소비자

### 기존 후처리와 신규 구독자의 중복 실행 방지

신규 이벤트를 추가할 때는 같은 부수효과가 Controller·Service의 직접 호출과 이벤트 구독자에서 동시에 실행되지 않도록 먼저 소유권을 정한다. 다음 표를 구현 전 필수 산출물로 작성한다.

| 후처리 | 현재 실행 위치 | 목표 단일 소유자 | 멱등 기준 | 기존 경로 제거 시점 |
|---|---|---|---|---|
| 장바구니 주문완료 처리 | 무통장 직접 처리, PG·0원은 `PaymentCompletionService` | 결제 방식별 오케스트레이터 | `cart_item_id`와 목표 상태 | 결제 방식별 상호 배타 경로 유지 |
| 쿠폰 사용 확정·복원 | 주문 생성 claim, 완료 원장 재확인, 상태 변경 복원 | `CouponService` | 쿠폰 ID와 주문번호 | 같은 주문 재호출의 멱등 계약 적용 완료 |
| 주문 포인트 사용·복원 | 무통장 직접 처리, PG·0원은 완료 원장, 상태 변경 복원 | `OrderPointService` | 주문번호 기반 원장 멱등 키 | 결제 방식별 상호 배타 경로 적용 완료 |
| 재고 차감·복구 | 설정형 상태 액션 | 재고 액션 서비스 | 주문상품 ID와 `stock_deducted` 상태 | 직접 재고 변경 경로 전수 확인 후 |
| 알림·웹훅 | 설정형 상태 액션과 향후 이벤트 구독자 | outbox 소비자 | 이벤트 ID와 소비자 키 | 소비 이력의 unique 제약 도입 후 |

현재 코드에는 일부 중복 방어가 이미 있다.

- 포인트 사용·복원은 주문번호 기반 원장 키를 사용하고, 설정형 포인트 Action은 주문번호·상태·영구 `action_id` 조합을 사용한다.
- 재고 차감·복구는 주문상품의 `stock_deducted` 플래그를 확인한다.
- 쿠폰 사용 확정은 이미 같은 주문번호로 사용된 쿠폰의 재마킹을 성공으로 간주한다.
- 장바구니 주문완료 처리는 상태 갱신이므로 반복 호출의 결과는 같지만, 호출 경로와 대상 ID가 동일한지 검증해야 한다.
- 설정형 Action은 주문 상태 로그 기반 이벤트 ID와 `shop_action_executions.execution_key` unique 제약으로 같은 전이·Action의 중복 실행을 차단한다. 웹훅 실패는 요청 기반 큐가 제한 횟수로 재시도한다.
- PG·0원 결제의 장바구니·쿠폰·포인트 처리는 `PaymentCompletionService`가 단일 소유하고 기존 결제 완료 내부 구독자는 제거했다.

운영 원칙:

- 회계·재고·쿠폰 같은 필수 처리는 이벤트 리스너가 임의로 다시 구현하지 않고, 하나의 멱등 Service API만 호출한다.
- 기존 직접 호출을 구독자로 이전할 때 두 경로를 동시에 활성화하지 않는다. 먼저 신규 구독자를 관찰 전용으로 검증한 뒤 한 번에 소유권을 전환한다.
- 모든 재시도 가능 이벤트에 `eventId`, `idempotencyKey`, `occurredAt`, `contractVersion`을 제공한다.
- outbox 소비자는 `(consumerKey, eventId)` unique 제약이나 동등한 소비 이력으로 중복 실행을 차단한다.
- 같은 이벤트 2회 전달, 부수효과 완료 후 ACK 전 중단, 직접 호출과 이벤트의 동시 실행, 일부 구독자 실패 후 재시도를 테스트한다.
- 테스트는 포인트 원장, 쿠폰 상태, 재고, 장바구니가 정확히 한 번만 변경되고 알림·웹훅도 정책상 허용된 횟수만 전송되는지 검증한다.

## 8. 권장 구현 순서

1. **완료** — 후처리 소유권 표 작성과 장바구니·쿠폰·포인트·재고 중복 실행 방어 테스트 추가
2. **완료** — 결제 완료 원장, lease, 멱등 재개로 `PAID` 전이 이후 복구 공백 제거
3. **완료** — `autoSyncOrderStatus()`를 공통 이벤트 생성 경로로 통합해 CAS 승자의 로그와 `OrderStatusChangedEvent` 발행 보장
4. `ProductAccessCheckingEvent`와 공통 접근 정책 조정기 구현
5. 상품 상세, 장바구니/바로구매, 체크아웃, 주문 생성의 네 방어 지점 연결
6. `ProductViewedEvent`, 장바구니 전후 이벤트, `OrderCreatedEvent` 추가
7. 결제 실패·취소와 환불 완료/실패 이벤트 추가
8. **부분 완료** — 주문상품 통합 상태 이벤트와 송장 등록·삭제·상태 이벤트 추가. 반품 전용 계약과 송장 수정 이벤트는 후속
9. 카테고리 정책, 쿠폰, 후기, 문의, 위시리스트 이벤트 추가
10. 나머지 고신뢰 이벤트에 outbox와 이벤트 계약 버전 도입

접근 이벤트를 먼저 한 경로에만 추가하면 우회가 생긴다. 상품 상세만 막고 장바구니 API나 직접구매가 열려 있거나, 장바구니만 막고 기존 장바구니 항목으로 체크아웃할 수 있는 식이다. 따라서 4~5단계는 한 묶음으로 적용해야 한다.

## 9. 운영 코드 발행 지점 부록

현재 정적 이벤트 생성/발행 위치는 다음 16곳이다.

```text
Service/CategoryService.php
  - CategoryUpdatedEvent
  - CategoryDeletedEvent

Service/ExhibitionService.php
  - ExhibitionCreatedEvent
  - ExhibitionUpdatedEvent
  - ExhibitionDeletedEvent

Service/ProductService.php
  - ProductChangedEvent(created)
  - ProductChangedEvent(updated)
  - ProductChangedEvent(deleted, 단건)
  - ProductChangedEvent(deleted, 일괄)

Service/OrderService.php
  - OrderStatusChangedEvent(일반 FSM 전이와 주문상품 자동 동기화의 공통 생성 헬퍼)
  - OrderItemStatusChangedEvent(상품 상태 변경·취소·반품 요청/승인/거절 공통 생성 헬퍼)

Service/PaymentService.php
  - PaymentMismatchEvent

Service/PaymentCompletionService.php
  - PaymentCompletedEvent(PG 검증·0원 결제 공통, 필수 내부 후처리 완료 후)

Service/ShipmentService.php
  - ShipmentRegisteredEvent
  - ShipmentStatusChangedEvent
  - ShipmentDeletedEvent
```

이 부록과 `Event/`의 13개 클래스, Shop 내부 구독자, 서비스 공개 변경 흐름을 교차 확인했다. `PaymentCompletedEvent`의 기존 내부 장바구니·쿠폰·포인트 구독자는 `PaymentCompletionService`로 통합돼 제거됐다.
