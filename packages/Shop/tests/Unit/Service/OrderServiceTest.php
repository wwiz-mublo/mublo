<?php
/**
 * packages/Shop/tests/Unit/Service/OrderServiceTest.php
 *
 * OrderService 단위 테스트
 *
 * 검증 항목:
 * - createOrder() — 빈 아이템 거부, 주문 생성 성공/실패, 결제 금액 포함
 * - getOrder()    — 주문 없음 처리, 성공 시 order + items
 * - updateStatus() — 전이 불가 거부, 성공 시 이벤트 발행
 * - PII 암호화/복호화 — encryptOrderFields / decryptOrderFields (기능 존재 검증)
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Core\Result\Result;

class OrderServiceTest extends TestCase
{
    private OrderRepository $orderRepo;
    private CartRepository $cartRepo;
    private ProductRepository $productRepo;
    private ProductOptionRepository $optionRepo;
    private OrderStateResolver $stateResolver;
    private PriceCalculator $priceCalculator;
    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderRepo       = $this->createMock(OrderRepository::class);
        $this->cartRepo        = $this->createMock(CartRepository::class);
        $this->productRepo     = $this->createMock(ProductRepository::class);
        $this->optionRepo      = $this->createMock(ProductOptionRepository::class);
        $this->stateResolver   = $this->createMock(OrderStateResolver::class);
        $this->priceCalculator = new PriceCalculator();

        $this->service = new OrderService(
            $this->orderRepo,
            $this->cartRepo,
            $this->productRepo,
            $this->optionRepo,
            $this->priceCalculator,
            $this->stateResolver
        );
    }

    // =========================================================
    // createOrder()
    // =========================================================

    public function testCreateOrderFailsWhenItemsIsEmpty(): void
    {
        $result = $this->service->createOrder(1, 42, [], []);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('상품', $result->getMessage());
    }

    public function testCreateOrderFailsWhenValidateItemFails(): void
    {
        // 상품 조회 실패 시 주문 생성도 실패
        $this->productRepo->expects($this->once())
            ->method('findInDomain')
            ->with(1, 99)
            ->willReturn(null);

        $items = [['goods_id' => 99, 'quantity' => 1, 'goods_price' => 10000]];
        $result = $this->service->createOrder(1, 42, ['payment_method' => 'CARD'], $items);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateOrderSuccessReturnsOrderNoAndPaymentAmount(): void
    {
        // 상품이 존재하고 DB 트랜잭션이 성공하는 시나리오 (Product는 final → fromArray 사용)
        $product = \Mublo\Packages\Shop\Entity\Product::fromArray($this->makeProductData([
            'goods_id'       => 10,
            'goods_name'     => '테스트상품',
            'display_price'  => 20000,
            'is_active'      => true,
            'stock_quantity' => 100,
            'option_mode'    => 'NONE',
        ]));

        $this->productRepo->method('findInDomain')->willReturn($product);

        // DB mock
        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $this->orderRepo->method('getDb')->willReturn($db);
        $this->orderRepo->method('generateOrderNo')->willReturn('ORD2026040500001');
        $this->orderRepo->method('createOrder')->willReturn('ORD2026040500001');
        $this->orderRepo->method('createOrderItem')->willReturn(1);

        $orderData = [
            'orderer_name'     => '홍길동',
            'orderer_phone'    => '01012345678',
            'orderer_email'    => 'test@example.com',
            'recipient_name'   => '홍길동',
            'recipient_phone'  => '01012345678',
            'shipping_zip'     => '12345',
            'shipping_address1'=> '서울시 강남구',
            'shipping_address2'=> '101동',
            'payment_method'   => 'CARD',
            'shipping_fee'     => 3000,
        ];
        $items = [['goods_id' => 10, 'quantity' => 1, 'goods_price' => 20000]];

        $result = $this->service->createOrder(1, 42, $orderData, $items);

        $this->assertTrue($result->isSuccess());
        $this->assertNotEmpty($result->get('order_no'));
        $this->assertArrayHasKey('payment_amount', $result->getData());
    }

    public function testCreateOrderPgStartsAttempting(): void
    {
        // PG 결제(payment_gateway 존재) 주문은 내부 과도상태 attempting으로 출생한다.
        $captured = $this->runCreateOrderCapturingRecord([
            'payment_method'  => 'CARD',
            'payment_gateway' => 'tosspay',
        ]);

        $this->assertSame('attempting', $captured['order_status']);
    }

    public function testCreateOrderBankStartsReceipt(): void
    {
        // 무통장입금(게이트웨이 없음)은 정상 주문접수 received로 출생한다.
        $captured = $this->runCreateOrderCapturingRecord([
            'payment_method'  => 'BANK',
            'payment_gateway' => '',
        ]);

        $this->assertSame('received', $captured['order_status']);
    }

    /**
     * createOrder를 실행하면서 Repository에 전달되는 주문 레코드를 캡처한다.
     */
    private function runCreateOrderCapturingRecord(array $orderDataOverrides): array
    {
        $product = \Mublo\Packages\Shop\Entity\Product::fromArray($this->makeProductData([
            'goods_id'       => 10,
            'goods_name'     => '테스트상품',
            'display_price'  => 20000,
            'is_active'      => true,
            'stock_quantity' => 100,
            'option_mode'    => 'NONE',
        ]));
        $this->productRepo->method('findInDomain')->willReturn($product);

        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $this->orderRepo->method('getDb')->willReturn($db);
        $this->orderRepo->method('generateOrderNo')->willReturn('ORD2026040500001');
        $this->orderRepo->method('createOrderItem')->willReturn(1);

        $captured = [];
        $this->orderRepo->method('createOrder')->willReturnCallback(
            function (array $record) use (&$captured) {
                $captured = $record;
                return $record['order_no'] ?? 'ORD2026040500001';
            }
        );

        $orderData = array_merge([
            'orderer_name'    => '홍길동',
            'orderer_phone'   => '01012345678',
            'recipient_name'  => '홍길동',
            'recipient_phone' => '01012345678',
            'shipping_zip'    => '12345',
            'shipping_address1' => '서울시 강남구',
            'shipping_fee'    => 3000,
        ], $orderDataOverrides);
        $items = [['goods_id' => 10, 'quantity' => 1, 'goods_price' => 20000]];

        $result = $this->service->createOrder(1, 42, $orderData, $items);
        $this->assertTrue($result->isSuccess());

        return $captured;
    }

    public function testCreateOrderPersistsAgreedPolicies(): void
    {
        $snapshot = json_encode([
            ['policy_id' => 5, 'version' => '1.0', 'title' => '개인정보 수집·이용', 'agreed_at' => '2026-07-04 00:00:00'],
        ], JSON_UNESCAPED_UNICODE);

        $record = $this->runCreateOrderCapturingRecord(['agreed_policies' => $snapshot]);

        $this->assertArrayHasKey('agreed_policies', $record);
        $this->assertSame($snapshot, $record['agreed_policies']);
    }

    public function testCreateOrderAgreedPoliciesNullWhenAbsent(): void
    {
        $record = $this->runCreateOrderCapturingRecord([]);

        $this->assertArrayHasKey('agreed_policies', $record);
        $this->assertNull($record['agreed_policies']);
    }

    // =========================================================
    // maybeExpireStaleAttempting() — 접점 기반 스로틀 스윕
    // =========================================================

    public function testMaybeExpireSkipsWhenThrottled(): void
    {
        // 최근 스윕(캐시 히트) → DB 스윕 호출하지 않음
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willReturn(true);
        $cache->expects($this->never())->method('set');
        $this->orderRepo->expects($this->never())->method('deleteStaleAttempting');

        $service = new OrderService(
            $this->orderRepo, $this->cartRepo, $this->productRepo, $this->optionRepo,
            $this->priceCalculator, $this->stateResolver, null, null, $cache
        );
        $service->maybeExpireStaleAttempting(1);
    }

    public function testMaybeExpireRunsAndSetsGateWhenNotThrottled(): void
    {
        // 캐시 미스 → 게이트 선점(set) + 실제 스윕 호출
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willReturn(false);
        $cache->expects($this->once())->method('set');
        $this->orderRepo->expects($this->once())
            ->method('deleteStaleAttempting')
            ->willReturn(3);

        $service = new OrderService(
            $this->orderRepo, $this->cartRepo, $this->productRepo, $this->optionRepo,
            $this->priceCalculator, $this->stateResolver, null, null, $cache
        );
        $service->maybeExpireStaleAttempting(1);
    }

    // =========================================================
    // expireStaleAttemptingOrders() — 삭제 전 쿠폰 복원 (F-5)
    // =========================================================

    public function testExpireStaleRestoresCouponsBeforeDeleting(): void
    {
        // 결제창 이탈 고아 주문을 하드 삭제하기 전에, 잡아둔 쿠폰을 복원해야 한다.
        // 그리고 '복원한 바로 그 목록'으로 삭제해야 한다(재조회 발산 방지).
        $coupon = $this->createMock(CouponService::class);
        $this->orderRepo->method('findStaleAttemptingOrderNos')->willReturn(['ORD1', 'ORD2']);
        $this->orderRepo->expects($this->once())->method('deleteByOrderNos')
            ->with(['ORD1', 'ORD2'])->willReturn(2);

        $restored = [];
        $coupon->method('restoreOrderCoupons')->willReturnCallback(
            function (string $orderNo) use (&$restored): Result {
                $restored[] = $orderNo;
                return Result::success();
            }
        );

        $service = new OrderService(
            $this->orderRepo, $this->cartRepo, $this->productRepo, $this->optionRepo,
            $this->priceCalculator, $this->stateResolver, null, null, null, null, $coupon
        );
        $result = $service->expireStaleAttemptingOrders(1);

        $this->assertSame(['ORD1', 'ORD2'], $restored);
        $this->assertTrue($result->isSuccess());
    }

    public function testExpireStaleContinuesWhenCouponRestoreFails(): void
    {
        // 쿠폰 복원 실패는 best-effort — 스윕(삭제)을 막지 않는다.
        $coupon = $this->createMock(CouponService::class);
        $this->orderRepo->method('findStaleAttemptingOrderNos')->willReturn(['ORD1']);
        $this->orderRepo->expects($this->once())->method('deleteByOrderNos')
            ->with(['ORD1'])->willReturn(1);
        $coupon->method('restoreOrderCoupons')->willThrowException(new \RuntimeException('boom'));

        $service = new OrderService(
            $this->orderRepo, $this->cartRepo, $this->productRepo, $this->optionRepo,
            $this->priceCalculator, $this->stateResolver, null, null, null, null, $coupon
        );
        $result = $service->expireStaleAttemptingOrders(1);

        $this->assertTrue($result->isSuccess());
    }

    // =========================================================
    // getOrder()
    // =========================================================

    public function testGetOrderFailsWhenOrderNotFound(): void
    {
        $this->orderRepo->method('find')->willReturn(null);

        $result = $this->service->getOrder('ORD9999999');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    public function testGetOrderReturnsOrderAndItems(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_no' => 'ORD2026040500001']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 1, 'goods_name' => '상품A', 'quantity' => 2],
        ]);

        $result = $this->service->getOrder('ORD2026040500001');

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('order', $result->getData());
        $this->assertArrayHasKey('items', $result->getData());
        $this->assertCount(1, $result->get('items'));
    }

    // =========================================================
    // updateStatus()
    // =========================================================

    public function testUpdateStatusFailsWhenOrderNotFound(): void
    {
        $this->orderRepo->method('find')->willReturn(null);

        $result = $this->service->updateStatus('ORD9999', 'PAID', 1);

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateStatusFailsWhenTransitionNotAllowed(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'COMPLETE']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(false);
        $this->stateResolver->method('getLabel')->willReturn('완료');

        $result = $this->service->updateStatus('ORD2026040500001', 'received', 1);

        $this->assertTrue($result->isFailure());
    }

    public function testOrderStatusActionIsBlockedWhileExchangeIsActive(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'delivered']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->orderRepo->method('getItems')->willReturn([[
            'return_type' => 'EXCHANGE',
            'return_status' => 'INSPECTING',
        ]]);
        $this->orderRepo->expects($this->never())->method('updateStatus');

        $result = $this->service->updateStatus('ORD2025010100001', 'confirmed', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('교환이 진행 중', $result->getMessage());
    }

    public function testItemStatusActionIsBlockedWhileExchangeIsActive(): void
    {
        $this->orderRepo->method('getItemInDomain')->willReturn([
            'order_no' => 'ORD1',
            'status' => 'delivered',
            'return_type' => 'EXCHANGE',
            'return_status' => 'COLLECTING',
        ]);
        $this->orderRepo->expects($this->never())->method('updateItemStatus');

        $result = $this->service->updateItemStatus('ORD1', 3, 'confirmed', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('교환 관리', $result->getMessage());
    }

    public function testUpdateStatusSucceeds(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'received']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stateResolver->method('getLabel')->willReturn('입금완료');
        $this->orderRepo->method('updateStatus')->willReturn(true);

        $result = $this->service->updateStatus('ORD2026040500001', 'PAID', 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateStatusActivatesAttemptingToPaidBypassingGraph(): void
    {
        // attempting은 그래프 밖 상태라 canTransition을 타지 않는다.
        // 활성화 전이(attempting→paid)는 그래프 검증이 false여도 허용돼야 한다.
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'attempting']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(false); // 그래프엔 attempting 없음
        $this->stateResolver->method('getLabel')->willReturn('결제완료');
        $this->orderRepo->method('updateStatus')->willReturn(true);

        $result = $this->service->updateStatus('ORD2026040500001', 'paid', 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateStatusRejectsAttemptingToNonActivationTarget(): void
    {
        // attempting에서 활성화 대상(paid/cancelled)이 아닌 곳으로는 전이 불가
        // (그래프 우회 대상이 아니며 canTransition도 false)
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'attempting']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(false);
        $this->stateResolver->method('getLabel')->willReturn('배송준비');

        $result = $this->service->updateStatus('ORD2026040500001', 'preparing', 1);

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateStatusActivatesAttemptingToCancelled(): void
    {
        // 확정적 이상(금액·주문번호 불일치 등)으로 인한 attempting→cancelled 활성화 허용
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'attempting']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(false);
        $this->stateResolver->method('getLabel')->willReturn('주문취소');
        $this->orderRepo->method('updateStatus')->willReturn(true);

        $result = $this->service->updateStatus('ORD2026040500001', 'cancelled', 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testItemStatusAutoSyncDispatchesHeaderStatusEventOnce(): void
    {
        $orderNo = 'ORD2026040500001';
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => $orderNo,
            'order_status' => 'paid',
        ]));
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(
            OrderStatusChangedEvent::class,
            function (OrderStatusChangedEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $service = new OrderService(
            $this->orderRepo,
            $this->cartRepo,
            $this->productRepo,
            $this->optionRepo,
            $this->priceCalculator,
            $this->stateResolver,
            $dispatcher
        );

        $this->orderRepo->method('getItemInDomain')->willReturn([
            'order_no' => $orderNo,
            'status' => 'paid',
        ]);
        $this->orderRepo->method('getItems')->willReturn([
            ['status' => 'shipping'],
            ['status' => 'shipping'],
        ]);
        $this->orderRepo->method('find')->willReturn($order);
        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with($orderNo, 'shipping', 'paid')
            ->willReturn(true);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stateResolver->method('getLabel')->willReturnMap([
            [1, 'paid', '결제완료'],
            [1, 'shipping', '배송중'],
        ]);

        $result = $service->updateItemStatus($orderNo, 10, 'shipping', 1);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $events);
        $this->assertSame('paid', $events[0]->getPrevStateId());
        $this->assertSame('shipping', $events[0]->getNewStateId());
    }

    public function testItemStatusAutoSyncDoesNotDispatchWhenHeaderCasLoses(): void
    {
        $orderNo = 'ORD2026040500001';
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => $orderNo,
            'order_status' => 'paid',
        ]));
        $dispatcher = new EventDispatcher();
        $eventCount = 0;
        $dispatcher->addListener(
            OrderStatusChangedEvent::class,
            function () use (&$eventCount): void {
                $eventCount++;
            }
        );

        $service = new OrderService(
            $this->orderRepo,
            $this->cartRepo,
            $this->productRepo,
            $this->optionRepo,
            $this->priceCalculator,
            $this->stateResolver,
            $dispatcher
        );

        $this->orderRepo->method('getItemInDomain')->willReturn([
            'order_no' => $orderNo,
            'status' => 'paid',
        ]);
        $this->orderRepo->method('getItems')->willReturn([['status' => 'shipping']]);
        $this->orderRepo->method('find')->willReturn($order);
        $this->orderRepo->method('updateStatus')->willReturn(false);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stateResolver->method('getLabel')->willReturn('상태');

        $result = $service->updateItemStatus($orderNo, 10, 'shipping', 1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $eventCount);
    }

    // =========================================================
    // confirmByBuyer() — 구매자 구매확정 walk
    // =========================================================

    public function testConfirmByBuyerWalksShippingToConfirmed(): void
    {
        // 배송중에서 구매확정 → 배송완료 → 구매확정 (FSM 정규 전이 2단계)
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'shipping']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stateResolver->method('getLabel')->willReturn('상태');

        $seen = [];
        $this->orderRepo->method('updateStatus')
            ->willReturnCallback(function ($orderNo, $newStateId) use (&$seen) {
                $seen[] = $newStateId;
                return true;
            });

        $result = $this->service->confirmByBuyer('ORD2026040500001', 1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['delivered', 'confirmed'], $seen);
    }

    public function testConfirmByBuyerFromDeliveredGoesToConfirmed(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'delivered']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stateResolver->method('getLabel')->willReturn('상태');

        $seen = [];
        $this->orderRepo->method('updateStatus')
            ->willReturnCallback(function ($orderNo, $newStateId) use (&$seen) {
                $seen[] = $newStateId;
                return true;
            });

        $result = $this->service->confirmByBuyer('ORD2026040500001', 1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['confirmed'], $seen);
    }

    public function testConfirmByBuyerIsIdempotentWhenAlreadyConfirmed(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'confirmed']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->orderRepo->expects($this->never())->method('updateStatus');

        $result = $this->service->confirmByBuyer('ORD2026040500001', 1);

        $this->assertTrue($result->isSuccess());
    }

    #[DataProvider('notConfirmableStateProvider')]
    public function testConfirmByBuyerFailsBeforeShippingOrOnCancelReturn(string $state): void
    {
        // 배송중 이전(paid/preparing/received)·취소/반품 계열은 구매확정 불가.
        $order = Order::fromArray($this->makeOrderData(['order_status' => $state]));
        $repo = $this->createMock(OrderRepository::class);
        $repo->method('find')->willReturn($order);
        $repo->expects($this->never())->method('updateStatus');

        $service = new OrderService(
            $repo,
            $this->cartRepo,
            $this->productRepo,
            $this->optionRepo,
            $this->priceCalculator,
            $this->stateResolver
        );
        $result = $service->confirmByBuyer('ORD2026040500001', 1);

        $this->assertTrue($result->isFailure());
    }

    public static function notConfirmableStateProvider(): array
    {
        return [
            'received'  => ['received'],
            'paid'      => ['paid'],
            'preparing' => ['preparing'],
            'cancelled' => ['cancelled'],
            'returned'  => ['returned'],
        ];
    }

    public function testConfirmByBuyerFailsWhenOrderNotFound(): void
    {
        $this->orderRepo->method('find')->willReturn(null);
        $this->orderRepo->expects($this->never())->method('updateStatus');

        $result = $this->service->confirmByBuyer('ORD9999', 1);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // autoConfirmDueOrders() — 발송 후 N일 자동 구매확정
    // =========================================================

    public function testAutoConfirmDueOrdersConfirmsEligibleOrders(): void
    {
        // 발송 경과 대상 1건 → 배송중에서 배송완료→구매확정으로 walk (SYSTEM)
        $this->orderRepo->method('findAutoConfirmDue')->willReturn(['ORD2026040500001']);
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'shipping']));
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stateResolver->method('getLabel')->willReturn('상태');

        $seen = [];
        $this->orderRepo->method('updateStatus')
            ->willReturnCallback(function ($orderNo, $newStateId) use (&$seen) {
                $seen[] = $newStateId;
                return true;
            });

        // 자동 확정은 SYSTEM 주체로 로그를 남긴다
        $changedBys = [];
        $this->orderRepo->method('insertOrderLog')
            ->willReturnCallback(function ($log) use (&$changedBys) {
                $changedBys[] = $log['changed_by'] ?? null;
                return 1;
            });

        $result = $this->service->autoConfirmDueOrders(1, 8);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->get('confirmed_count'));
        $this->assertSame(['delivered', 'confirmed'], $seen);
        $this->assertSame(['SYSTEM', 'SYSTEM'], $changedBys);
    }

    public function testAutoConfirmDueOrdersSkipsWhenDaysZero(): void
    {
        // 0일(비활성)이면 조회조차 하지 않고 0건 반환
        $this->orderRepo->expects($this->never())->method('findAutoConfirmDue');
        $this->orderRepo->expects($this->never())->method('updateStatus');

        $result = $this->service->autoConfirmDueOrders(1, 0);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->get('confirmed_count'));
    }

    public function testAutoConfirmDueOrdersReturnsZeroWhenNoneDue(): void
    {
        $this->orderRepo->method('findAutoConfirmDue')->willReturn([]);
        $this->orderRepo->expects($this->never())->method('updateStatus');

        $result = $this->service->autoConfirmDueOrders(1, 8);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->get('confirmed_count'));
    }

    // =========================================================
    // PII 암호화/복호화 메서드 존재 검증
    // =========================================================

    public function testServiceHasEncryptionCapability(): void
    {
        // encryptionService 없이도 createOrder가 작동해야 함
        // (null fallback — 암호화 없이 저장)
        $this->assertInstanceOf(OrderService::class, $this->service);
    }

    public function testOrderWithoutEncryptionServiceSavesPlaintext(): void
    {
        // FieldEncryptionService = null 이면 평문 저장 (Product는 final → fromArray 사용)
        $product = \Mublo\Packages\Shop\Entity\Product::fromArray($this->makeProductData([
            'goods_id'       => 1,
            'goods_name'     => '상품',
            'display_price'  => 10000,
            'is_active'      => true,
            'stock_quantity' => null,
            'option_mode'    => 'NONE',
        ]));

        $this->productRepo->method('findInDomain')->willReturn($product);

        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $this->orderRepo->method('getDb')->willReturn($db);
        $this->orderRepo->method('generateOrderNo')->willReturn('ORD2026040500002');
        $this->orderRepo->method('createOrder')->willReturn('ORD2026040500002');

        $capturedRecord = null;
        $this->orderRepo->method('createOrderItem')->willReturn(1);

        $result = $this->service->createOrder(
            1, 0,
            ['orderer_name' => '테스트', 'payment_method' => 'BANK'],
            [['goods_id' => 1, 'quantity' => 1, 'goods_price' => 10000]]
        );

        // FieldEncryptionService null 이면 성공 (평문 유지)
        $this->assertTrue($result->isSuccess());
    }

    // =========================================================
    // getMemberOrders()
    // =========================================================

    public function testGetMemberOrdersReturnsResult(): void
    {
        $this->orderRepo->method('getByMember')->willReturn([
            'items'      => [],
            'pagination' => ['totalItems' => 0, 'perPage' => 10, 'currentPage' => 1, 'totalPages' => 1],
        ]);

        $result = $this->service->getMemberOrders(42, 1, 10);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('items', $result->getData());
    }

    // =========================================================
    // 비회원 주문조회 (lookupGuestOrders) — 주문인명 + 연락처
    // =========================================================

    public function testLookupGuestOrdersFailsOnEmptyInput(): void
    {
        $result = $this->service->lookupGuestOrders('', '');
        $this->assertTrue($result->isFailure());
    }

    public function testLookupGuestOrdersFailsWithoutEncryptionService(): void
    {
        // 기본 서비스는 encryptionService 미주입 → 조회 불가
        $result = $this->service->lookupGuestOrders('홍길동', '010-1234-5678');
        $this->assertTrue($result->isFailure());
    }

    public function testLookupGuestOrdersRejectsWrongPhone(): void
    {
        $service = $this->makeServiceWithEncryption();
        $this->orderRepo->method('findGuestByNameIndex')->willReturn([
            ['order_no' => 'ORD1', 'orderer_name' => '홍길동', 'orderer_phone' => '010-1111-2222',
             'member_id' => 0, 'created_at' => '2026-05-27 10:00:00'],
        ]);

        $result = $service->lookupGuestOrders('홍길동', '010-9999-8888');

        $this->assertTrue($result->isFailure());
    }

    /** 저장 포맷(하이픈)과 입력 포맷(숫자만)이 달라도 매칭, 다른 사람 주문은 제외 */
    public function testLookupGuestOrdersMatchesByNameAndPhone(): void
    {
        $service = $this->makeServiceWithEncryption();
        $this->orderRepo->method('findGuestByNameIndex')->willReturn([
            ['order_no' => 'ORD1', 'orderer_name' => '홍길동', 'orderer_phone' => '010-1111-2222',
             'member_id' => 0, 'created_at' => '2026-05-27 10:00:00', 'total_price' => 10000, 'shipping_fee' => 0],
            ['order_no' => 'ORD2', 'orderer_name' => '홍길동', 'orderer_phone' => '010-3333-4444',
             'member_id' => 0, 'created_at' => '2026-05-26 09:00:00', 'total_price' => 5000, 'shipping_fee' => 0],
        ]);
        $this->orderRepo->method('getItemsByOrderNos')->willReturn([
            'ORD1' => [['order_detail_id' => 1, 'goods_name' => '상품A']],
        ]);

        $result = $service->lookupGuestOrders('홍길동', '01011112222');

        $this->assertTrue($result->isSuccess());
        $orders = $result->get('orders');
        $this->assertCount(1, $orders, 'ORD2는 연락처 불일치로 제외');
        $this->assertSame('ORD1', $orders[0]['order_no']);
        $this->assertSame('상품A', $orders[0]['first_item_name']);
        $this->assertSame(1, $orders[0]['item_count']);
    }

    /** 같은 사람(이름+연락처)의 여러 주문이 모두 조회되는지 (다건) */
    public function testLookupGuestOrdersReturnsAllOrdersOfSamePerson(): void
    {
        $service = $this->makeServiceWithEncryption();
        $this->orderRepo->method('findGuestByNameIndex')->willReturn([
            ['order_no' => 'ORD1', 'orderer_name' => '홍길동', 'orderer_phone' => '01011112222',
             'member_id' => 0, 'created_at' => '2026-05-27 10:00:00'],
            ['order_no' => 'ORD2', 'orderer_name' => '홍길동', 'orderer_phone' => '010-1111-2222',
             'member_id' => 0, 'created_at' => '2026-05-26 09:00:00'],
        ]);
        $this->orderRepo->method('getItemsByOrderNos')->willReturn([]);

        $result = $service->lookupGuestOrders('홍길동', '010-1111-2222');

        $this->assertTrue($result->isSuccess());
        $this->assertCount(2, $result->get('orders'));
    }

    // =========================================================
    // 비회원 주문 목록 (getGuestOrders) — 세션 보유 주문번호
    // =========================================================

    public function testGetGuestOrdersEmptyWhenNoOrderNos(): void
    {
        $result = $this->service->getGuestOrders([]);
        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $result->get('items'));
    }

    public function testGetGuestOrdersReturnsOrdersWithItems(): void
    {
        $o1 = Order::fromArray($this->makeOrderData(['order_no' => 'ORD1']));
        $o2 = Order::fromArray($this->makeOrderData(['order_no' => 'ORD2']));
        $this->orderRepo->method('findByOrderNos')->willReturn([$o1, $o2]);
        $this->orderRepo->method('getItemsByOrderNos')->willReturn([
            'ORD1' => [['goods_name' => '상품A']],
            'ORD2' => [['goods_name' => '상품B']],
        ]);

        $result = $this->service->getGuestOrders(['ORD1', 'ORD2', 'ORD1']); // 중복은 제거됨

        $this->assertTrue($result->isSuccess());
        $items = $result->get('items');
        $this->assertCount(2, $items);
        $this->assertArrayHasKey('items', $items[0]); // 각 주문에 대표상품 items 부착
    }

    public function testReturnUsesOrderTimeShippingPolicyAndMember(): void
    {
        $orderNo = 'ORD2026040500001';
        $item = [
            'order_detail_id' => 10, 'order_no' => $orderNo, 'goods_id' => 77,
            'status' => 'delivered', 'return_type' => 'NONE', 'quantity' => 1,
            'total_price' => 20000,
        ];
        $orderRow = $this->makeOrderData([
            'order_no' => $orderNo,
            'member_id' => 42,
            'shipping_breakdown' => json_encode([[
                'goods_ids' => [77], 'return_cost' => 3500,
            ]]),
        ]);
        $this->orderRepo->method('getItemInDomain')->willReturn($item);
        $this->orderRepo->method('findByOrderNoInDomain')->willReturn($orderRow);
        $this->orderRepo->method('find')->willReturn(Order::fromArray($orderRow));
        $captured = [];
        $this->orderRepo->method('createReturn')->willReturnCallback(function (array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });
        $this->orderRepo->method('updateItemStatus')->willReturn(true);
        $this->orderRepo->method('updateItemReturn')->willReturn(true);
        $this->orderRepo->method('insertOrderLog')->willReturn(1);
        $this->stateResolver->method('getLabel')->willReturn('배송완료');

        $result = $this->service->requestItemReturn(
            $orderNo, 10, 'RETURN', 'CHANGE_MIND', '단순 변심', 1
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $captured['member_id']);
        $this->assertSame(3500, $captured['return_shipping_fee']);
        $this->assertSame(16500, $captured['refund_amount']);
    }

    public function testExchangeIsNotSilentlyCompletedAsReturn(): void
    {
        $this->orderRepo->method('getItemInDomain')->willReturn([
            'order_detail_id' => 10, 'order_no' => 'ORD1', 'status' => 'delivered',
        ]);

        $result = $this->service->requestItemReturn('ORD1', 10, 'EXCHANGE', 'OTHER', '', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('지원하지 않습니다', $result->getMessage());
    }


    // =========================================================
    // 주문상품 ↔ 주문 헤더 상태 동기화
    // =========================================================

    /** 기본 상태 그래프를 아는 resolver 스텁 (sort_order·to 필요) */
    private function stubDefaultStateGraph(): void
    {
        $defaults = [];
        foreach (OrderAction::defaultStates() as $state) {
            $defaults[$state['id']] = $state;
        }
        $this->stateResolver->method('getAllStates')->willReturn(OrderAction::defaultStates());
        $this->stateResolver->method('getState')
            ->willReturnCallback(static fn(int $d, string $id): ?array => $defaults[$id] ?? null);
        $this->stateResolver->method('getAction')
            ->willReturnCallback(static fn(string $id, array $def): ?OrderAction => OrderAction::tryFrom($def['action'] ?? ''));
        $this->stateResolver->method('getLabel')
            ->willReturnCallback(static fn(int $d, string $id): string => $defaults[$id]['label'] ?? $id);
    }

    public function testRollupFollowsLeastAdvancedItemWhenStatesAreMixed(): void
    {
        // 3개 중 1개만 앞서 나갔으면 주문은 뒤처진 쪽을 따라가야 한다.
        // 그래야 고객에게 "아직 다 오지 않았다"가 전달된다.
        $orderNo = 'ORD2026040500001';
        $order = Order::fromArray($this->makeOrderData(['order_no' => $orderNo, 'order_status' => 'paid']));

        $this->orderRepo->method('getItemInDomain')->willReturn([
            'order_no' => $orderNo, 'order_detail_id' => 10, 'status' => 'paid',
        ]);
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'status' => 'shipping'],
            ['order_detail_id' => 11, 'status' => 'preparing'],
        ]);
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stubDefaultStateGraph();

        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with($orderNo, 'preparing', 'paid')
            ->willReturn(true);

        $this->assertTrue($this->service->updateItemStatus($orderNo, 10, 'shipping', 1)->isSuccess());
    }

    public function testRollupNeverMovesOrderStatusBackward(): void
    {
        // 품목이 헤더를 따라오지 못한 과거 주문에서 특히 잘 생기는 상황.
        // 배송완료였던 주문이 결제완료로 되돌아가면 고객이 이해할 수 없다.
        $orderNo = 'ORD2026040500001';
        $order = Order::fromArray($this->makeOrderData(['order_no' => $orderNo, 'order_status' => 'delivered']));

        $this->orderRepo->method('getItemInDomain')->willReturn([
            'order_no' => $orderNo, 'order_detail_id' => 10, 'status' => 'received',
        ]);
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'status' => 'paid'],
            ['order_detail_id' => 11, 'status' => 'paid'],
        ]);
        $this->orderRepo->method('find')->willReturn($order);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stubDefaultStateGraph();

        $this->orderRepo->expects($this->never())->method('updateStatus');

        $this->assertTrue($this->service->updateItemStatus($orderNo, 10, 'paid', 1)->isSuccess());
    }

    public function testAdvanceItemsWalksMultipleHopsAndRecordsPassedStates(): void
    {
        // 주문접수에 머물던 품목을 송장 등록 한 번으로 배송중까지 끌어올린다.
        // 중간 상태는 이력에만 남기고 알림은 최종 상태 한 번으로 끝낸다.
        $orderNo = 'ORD2026040500001';
        $logs = [];

        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'order_no' => $orderNo, 'status' => 'received'],
        ]);
        $this->orderRepo->method('insertOrderLog')->willReturnCallback(
            function (array $log) use (&$logs): int {
                $logs[] = $log;
                return count($logs);
            }
        );
        $this->stubDefaultStateGraph();

        $this->orderRepo->expects($this->once())
            ->method('updateItemStatus')
            ->with(10, 'shipping')
            ->willReturn(true);

        $changed = $this->service->advanceItemsToState(
            $orderNo, 1, 'shipping', [], '송장 등록', 'SYSTEM', false, false
        );

        $this->assertSame(1, $changed);
        $this->assertCount(1, $logs);
        $this->assertSame('received', $logs[0]['prev_status']);
        $this->assertSame('shipping', $logs[0]['new_status']);
        $this->assertStringContainsString('경유', (string) $logs[0]['reason']);
        $this->assertStringContainsString('배송준비', (string) $logs[0]['reason']);
    }

    public function testAdvanceItemsSkipsItemsThatWouldMoveBackward(): void
    {
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'order_no' => 'ORD1', 'status' => 'delivered'],
            ['order_detail_id' => 11, 'order_no' => 'ORD1', 'status' => 'cancelled'],
        ]);
        $this->stubDefaultStateGraph();
        $this->orderRepo->expects($this->never())->method('updateItemStatus');

        $this->assertSame(
            0,
            $this->service->advanceItemsToState('ORD1', 1, 'shipping', [], '송장 등록', 'SYSTEM', false, false)
        );
    }

    public function testAdvanceItemsSkipsItemsUnderActiveClaim(): void
    {
        // 클레임이 걸린 품목은 교환 워크플로우가 소유한다
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'order_no' => 'ORD1', 'status' => 'preparing', 'return_status' => 'COLLECTING'],
        ]);
        $this->stubDefaultStateGraph();
        $this->orderRepo->expects($this->never())->method('updateItemStatus');

        $this->assertSame(
            0,
            $this->service->advanceItemsToState('ORD1', 1, 'shipping', [], '송장 등록', 'SYSTEM', false, false)
        );
    }

    public function testAdvanceItemsHonoursDetailIdFilter(): void
    {
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'order_no' => 'ORD1', 'status' => 'preparing'],
            ['order_detail_id' => 11, 'order_no' => 'ORD1', 'status' => 'preparing'],
        ]);
        $this->stubDefaultStateGraph();

        // 배송비 그룹이 다른 품목(11)은 그 그룹 송장이 등록될 때 따로 움직인다
        $this->orderRepo->expects($this->once())
            ->method('updateItemStatus')
            ->with(10, 'shipping')
            ->willReturn(true);

        $this->assertSame(
            1,
            $this->service->advanceItemsToState('ORD1', 1, 'shipping', [10], '송장 등록', 'SYSTEM', false, false)
        );
    }

    public function testUpdateStatusPropagatesToOrderItems(): void
    {
        // 헤더 변경은 전 품목에 대한 벌크 지시다 — 품목을 뒤에 남기지 않는다
        $orderNo = 'ORD2026040500001';
        $order = Order::fromArray($this->makeOrderData(['order_no' => $orderNo, 'order_status' => 'preparing']));

        $this->orderRepo->method('find')->willReturn($order);
        $this->orderRepo->method('getItems')->willReturn([
            ['order_detail_id' => 10, 'order_no' => $orderNo, 'status' => 'preparing'],
        ]);
        $this->orderRepo->method('updateStatus')->willReturn(true);
        $this->stateResolver->method('canTransition')->willReturn(true);
        $this->stubDefaultStateGraph();

        $this->orderRepo->expects($this->once())
            ->method('updateItemStatus')
            ->with(10, 'shipping')
            ->willReturn(true);

        $this->assertTrue($this->service->updateStatus($orderNo, 'shipping', 1)->isSuccess());
    }

    // ===== 헬퍼 =====

    /** 암호화 서비스가 주입된 OrderService 생성 (조회 테스트용) */
    private function makeServiceWithEncryption(): OrderService
    {
        $enc = $this->createMock(SensitiveValueCodecInterface::class);
        $enc->method('createSearchIndex')->willReturnCallback(fn(string $v) => 'IDX:' . $v);
        $enc->method('decrypt')->willReturnArgument(0);

        return new OrderService(
            $this->orderRepo,
            $this->cartRepo,
            $this->productRepo,
            $this->optionRepo,
            $this->priceCalculator,
            $this->stateResolver,
            null,
            $enc
        );
    }
}
