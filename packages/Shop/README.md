# Mublo Shop 패키지

Mublo Framework용 쇼핑몰 패키지입니다. 상품·옵션, 장바구니, 주문 상태 관리(FSM), 결제, 배송, 쿠폰, 리뷰/문의, 포인트, 기획전, 마이페이지 연동을 제공합니다.

- **버전** 1.0.0 · **요구** 코어 `>=1.0.0`, PHP `>=8.2` · **카테고리** commerce

## 주요 기능

- **상품/카테고리** — 다중 이미지, 옵션(단일·조합·추가), 옵션 프리셋, 트리 카테고리, 상품정보 고시 템플릿
- **주문/결제** — 장바구니·바로구매, 체크아웃 서버 재계산, 비회원 주문 조회, 취소·반품·환불, 배송/송장 관리. 주문 상태는 Config 기반 FSM + 상태별 자동 액션(알림·포인트·재고·웹훅·구매확정)
- **교환 클레임** — 일부 수량, 재고 예약, 회수·검수·재출고·거절 반송, 귀책별 교환비, 전용 FSM·이력. 주문 FSM/Action과 분리해 후처리 중복을 막음
- **결제 연동** — PG는 코어 `PaymentGatewayInterface` Contract로 주입(플러그인), 무통장·포인트는 Shop 자체 제공
- **쿠폰/혜택** — 정액·정률, 대상 제한, 자동 발급, 복수 쿠폰 스택, 취소 시 복원 / 등급별 가격 / 포인트 적립·사용
- **배송** — 배송 템플릿(무료·조건부·정액·수량), 도서산간 추가 배송비
- **고객 접점** — 구매후기, 상품문의 QnA, 위시리스트, 배송지 주소록, 마이페이지 허브 `/mypage/shop`
- **운영/확장** — 관리자 대시보드·주문 리포트, 상품/리뷰 블록, 통합 검색 연동, PII(주문·수령·배송지) AES-256-GCM 암호화

## 프론트 스킨

기능별로 스킨을 분리해 교체합니다(`views/Front/{기능}/{skin}/`). 선택 스킨은 `shop_config.skin_config`(JSON 맵)에 저장하며, 파일이 없으면 `basic`으로 폴백합니다.

## 설치

1. `packages/Shop` 디렉토리에 배치 (또는 관리자 확장 관리에서 zip 업로드)
2. 관리자 > 패키지 관리에서 활성화 → 마이그레이션·기본 메뉴 자동 등록
3. (선택) 관리자 > 쇼핑몰 설정 > 결제에서 결제 플러그인(`PayApp`, `TestPay` 등) 선택

## 디렉토리 구조

```text
packages/Shop/
├── Action/          # 주문 상태 액션 핸들러 (알림/포인트/재고/웹훅)
├── Api/ Contract/Extension/  # 종속 플러그인용 공개 API·readonly DTO
├── Block/           # 상품·리뷰 블록
├── Controller/      # Admin/ · Front/
├── Entity/  Enum/  Event/  EventSubscriber/  Helper/  Report/
├── Plugins/         # Shop 종속 플러그인 표준 위치
├── Repository/      # 데이터 접근
├── Service/         # 비즈니스 로직 (OrderService, CartService 등)
├── database/migrations/   # 마이그레이션 SQL
├── views/           # Admin/ · Block/ · Front/(기능별 스킨)
├── ShopProvider.php          # 부팅 진입점 (register/boot)
├── ShopCategoryProvider.php  # 카테고리 어댑터 (CategoryProviderInterface)
├── routes.php  manifest.json
```

## 개발 참고

- 코어를 수정하지 않고 `ShopProvider`에서 서비스·이벤트·블록을 등록합니다.
- 주문 상태별 후처리는 관리자 설정으로 선언하면 `ConfigurableActionSubscriber`가 자동 실행합니다.
- 자체 PG는 `ContractRegistry`에 `PaymentGatewayInterface` 구현체로 등록합니다.
- DB 변경은 `database/migrations`에 SQL 파일로 추가합니다.
- 현재 발행 이벤트 전수와 추가 권고안은 [`docs/events.md`](docs/events.md)를 참고합니다.
- 교환 상태·재고·중복 후처리 경계는 [`docs/exchange-workflow.md`](docs/exchange-workflow.md)를 참고합니다.

## Shop Extension API

Shop 종속 플러그인은 `Service`, `Repository`, `Entity`, DB 테이블을 직접 사용하지 않습니다.
다음 공개 표면만 장기 호환 대상으로 취급합니다.

- `Contract/Extension/ShopExtensionApiInterface.php`: 공개 API 단일 진입점
- `ShopProductReaderInterface.php`: 현재 도메인 상품 조회
- `ShopOrderReaderInterface.php`: 개인정보를 제외한 현재 도메인 주문 조회
- `ShopCommandInterface.php`: 도메인 검증을 거친 상품 삭제와 주문상태 전이
- `Api/DTO/ProductSnapshot.php`, `OrderSnapshot.php`: 내부 Entity 대신 전달되는 readonly 값 객체
- `Event/`: 상품·주문·결제·카테고리·기획전 변경 이벤트

Provider에서는 `ShopExtensionApiInterface`를 주입받아 사용합니다.

```php
$container->singleton(MyShopPluginService::class, fn($c) =>
    new MyShopPluginService($c->get(ShopExtensionApiInterface::class))
);
```

종속 플러그인은 `packages/Shop/Plugins/{PluginName}`에 배치합니다. 활성 이름은
`Shop/{PluginName}`이며, 부모 Shop이 비활성화되면 함께 로드되지 않습니다.

```json
{
    "type": "plugin",
    "parent": "Shop",
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Shop": ">=1.0.0 <2.0.0"
    }
}
```

주문 Snapshot에는 주문자·수령인·연락처·주소가 포함되지 않습니다. 주문상태 변경은
현재 도메인 소유권과 기존 FSM 전이 규칙을 모두 통과해야 하며 변경 이력에는 `SYSTEM`으로 기록됩니다.
PG 구현은 기존처럼 코어 `PaymentGatewayInterface`, 커스텀 주문상태 액션은
`ActionHandlerInterface`와 `ActionTypeRegistry`를 사용합니다.

## 라이선스

Mublo Framework의 일부로 MIT 라이선스를 따릅니다.
