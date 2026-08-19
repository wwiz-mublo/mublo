<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\ClaimStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderItemStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\ClaimRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Service\ClaimStateMachine;
use Mublo\Packages\Shop\Service\ClaimService;
use Mublo\Packages\Shop\Service\ExchangeStockService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Tests\Shop\TestCase;

final class ClaimServiceTest extends TestCase
{
    public function testCustomerCannotForgeSellerResponsibilityToAvoidExchangeFee(): void
    {
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);
        $resolver = $this->createMock(OrderStateResolver::class);
        $capturedClaim = null;

        $claims->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $claims->method('lockOrderDetailInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'goods_id' => 10,
            'quantity' => 2, 'status' => 'delivered', 'option_mode' => 'NONE',
            'option_id' => 0, 'option_code' => '', 'option_price' => 0,
        ]);
        $claims->method('getActiveQuantityForUpdate')->willReturn(0);
        $claims->expects($this->once())->method('createClaim')->willReturnCallback(
            static function (array $data) use (&$capturedClaim): int {
                $capturedClaim = $data;
                return 77;
            }
        );
        $claims->expects($this->once())->method('createExchangeItem')->willReturn(1);
        $claims->expects($this->once())->method('addLog')->willReturn(1);
        $claims->method('findInDomain')->willReturn([
            'return_id' => 77, 'domain_id' => 1, 'order_no' => 'ORD1',
            'order_detail_id' => 3, 'return_status' => 'REQUESTED',
            'pickup_phone' => 'encrypted-phone',
            'member_id' => 5,
            'reason_detail' => '연락처 010-0000-0000',
            'staff_memo' => '내부 메모',
        ]);
        $orders->method('findByOrderNoInDomain')->willReturn([
            'member_id' => 5,
            'recipient_name' => 'encrypted-name',
            'recipient_phone' => 'encrypted-phone',
            'shipping_zip' => '12345',
            'shipping_address1' => 'encrypted-address',
            'shipping_address2' => '',
            'shipping_breakdown' => json_encode([[
                'goods_ids' => [10], 'exchange_cost' => 6000,
            ]]),
        ]);
        $orders->expects($this->once())->method('updateItemReturn')->with(3, 'EXCHANGE', 'REQUESTED')->willReturn(true);
        $resolver->method('getState')->willReturn(['id' => 'delivered', 'action' => 'delivered']);
        $resolver->method('getAction')->willReturn(OrderAction::DELIVERED);

        $dispatcher = new EventDispatcher();
        $events = [];
        $orderEvents = [];
        $dispatcher->addListener(ClaimStatusChangedEvent::class, static function ($event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->addListener(OrderStatusChangedEvent::class, static function ($event) use (&$orderEvents): void {
            $orderEvents[] = $event;
        });
        $dispatcher->addListener(OrderItemStatusChangedEvent::class, static function ($event) use (&$orderEvents): void {
            $orderEvents[] = $event;
        });
        $service = $this->makeService($claims, $orders, $resolver, $dispatcher);

        $result = $service->request(1, 'ORD1', 3, [
            'quantity' => 1,
            'reason_type' => 'CHANGE_MIND',
            'responsibility' => 'SELLER', // 변조 시도
        ], 'CUSTOMER', 5);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('CUSTOMER', $capturedClaim['responsibility']);
        $this->assertSame(6000, $capturedClaim['exchange_shipping_fee']);
        $this->assertSame('UNPAID', $capturedClaim['fee_status']);
        $this->assertCount(1, $events);
        $this->assertSame('REQUESTED', $events[0]->getNewStatus());
        $this->assertArrayNotHasKey('pickup_phone', $events[0]->getClaim());
        $this->assertArrayNotHasKey('member_id', $events[0]->getClaim());
        $this->assertArrayNotHasKey('reason_detail', $events[0]->getClaim());
        $this->assertArrayNotHasKey('staff_memo', $events[0]->getClaim());
        $this->assertSame(77, $events[0]->getClaim()['return_id']);
        $this->assertSame([], $orderEvents, '교환 신청은 주문 Action 이벤트를 발화하면 안 됩니다.');
    }

    public function testCompletedExchangeQuantityCannotBeRequestedAgain(): void
    {
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);
        $resolver = $this->createMock(OrderStateResolver::class);
        $claims->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $claims->method('lockOrderDetailInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'goods_id' => 10,
            'quantity' => 2, 'status' => 'delivered', 'option_mode' => 'NONE', 'option_price' => 0,
        ]);
        $claims->method('getActiveQuantityForUpdate')->willReturn(2);
        $claims->expects($this->never())->method('createClaim');
        $resolver->method('getState')->willReturn(['id' => 'delivered', 'action' => 'delivered']);
        $resolver->method('getAction')->willReturn(OrderAction::DELIVERED);
        $service = $this->makeService($claims, $orders, $resolver, new EventDispatcher());

        $result = $service->request(1, 'ORD1', 3, ['quantity' => 1, 'reason_type' => 'OTHER'], 'CUSTOMER', 5);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('교환 가능한 수량', $result->getMessage());
    }

    // =========================================================
    // isClaimable() — 프론트 버튼과 서버 접수의 단일 규칙
    // =========================================================

    /** 상태 id를 그대로 액션으로 해석하는 resolver 스텁 */
    private function passthroughResolver(): OrderStateResolver
    {
        $resolver = $this->createMock(OrderStateResolver::class);
        $resolver->method('getState')
            ->willReturnCallback(static fn(int $d, string $id): ?array => $id !== '' ? ['id' => $id, 'action' => $id] : null);
        $resolver->method('getAction')
            ->willReturnCallback(static fn(string $id, array $def): ?OrderAction => OrderAction::tryFrom($def['action'] ?? ''));
        return $resolver;
    }

    public function testDeliveredItemIsExchangeableRegardlessOfOrderHeader(): void
    {
        // 부분 배송: 먼저 도착한 품목은 주문 헤더가 배송중이어도 교환할 수 있어야 한다
        $service = $this->makeService(
            $this->createMock(ClaimRepository::class),
            $this->createMock(OrderRepository::class),
            $this->passthroughResolver(),
            new EventDispatcher(),
        );

        $this->assertTrue($service->isClaimable(1, ['status' => 'delivered'], 'shipping'));
    }

    public function testItemFallsBackToOrderHeaderWhenItStillLagsBehind(): void
    {
        // 송장을 쓰지 않는 상점에서는 주문 헤더가 배송 완료를 아는 유일한 신호다
        $service = $this->makeService(
            $this->createMock(ClaimRepository::class),
            $this->createMock(OrderRepository::class),
            $this->passthroughResolver(),
            new EventDispatcher(),
        );

        $this->assertTrue($service->isClaimable(1, ['status' => 'received'], 'delivered'));
        $this->assertFalse($service->isClaimable(1, ['status' => 'received'], 'shipping'));
    }

    public function testConfirmedOrCancelledItemsAreNotExchangeable(): void
    {
        $service = $this->makeService(
            $this->createMock(ClaimRepository::class),
            $this->createMock(OrderRepository::class),
            $this->passthroughResolver(),
            new EventDispatcher(),
        );

        // 구매확정은 클레임이 닫힌 것으로 본다 — 헤더가 배송완료여도 열지 않는다
        $this->assertFalse($service->isClaimable(1, ['status' => 'confirmed'], 'delivered'));
        $this->assertFalse($service->isClaimable(1, ['status' => 'cancelled'], 'delivered'));
        $this->assertFalse($service->isClaimable(1, ['status' => 'return_requested'], 'delivered'));
    }

    // =========================================================
    // 반품 접수 — 교환과 같은 워크플로우, 마지막 갈래만 다르다
    // =========================================================

    public function testReturnRequestRecordsRefundAmountAndSkipsExchangeItem(): void
    {
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);
        $resolver = $this->passthroughResolver();
        $captured = null;

        $claims->method('transaction')->willReturnCallback(static fn(callable $cb): mixed => $cb());
        $claims->method('lockOrderDetailInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'goods_id' => 10,
            'quantity' => 2, 'status' => 'delivered', 'option_mode' => 'NONE',
            'goods_price' => 10000, 'option_price' => 0, 'option_id' => 0, 'option_code' => '',
        ]);
        $claims->method('getActiveQuantityForUpdate')->willReturn(0);
        $orders->method('findByOrderNoInDomain')->willReturn([
            'order_no' => 'ORD1', 'member_id' => 5, 'order_status' => 'delivered',
            'shipping_breakdown' => json_encode([
                ['goods_ids' => [10], 'return_cost' => 2500, 'exchange_cost' => 6000],
            ]),
        ]);
        $claims->expects($this->once())->method('createClaim')->willReturnCallback(
            static function (array $data) use (&$captured): int {
                $captured = $data;
                return 88;
            }
        );
        // 반품에는 바꿔 보낼 상품이 없다
        $claims->expects($this->never())->method('createExchangeItem');
        $claims->method('addLog')->willReturn(1);

        $service = $this->makeService($claims, $orders, $resolver, new EventDispatcher());
        $result = $service->request(
            1, 'ORD1', 3, ['quantity' => 1, 'reason_type' => 'CHANGE_MIND'], 'CUSTOMER', 5, 'RETURN'
        );

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('반품', $result->getMessage());
        $this->assertSame('RETURN', $captured['return_type']);
        // 단순 변심은 고객 귀책 → 반품비를 환불액에서 뺀다
        $this->assertSame(2500, $captured['return_shipping_fee']);
        $this->assertSame(7500, $captured['refund_amount']);
        $this->assertSame(0, $captured['exchange_shipping_fee']);
        $this->assertSame('WAIVED', $captured['fee_status'], '반품비는 환불에서 빼므로 따로 받을 것이 없습니다.');
    }

    public function testSellerFaultReturnRefundsTheFullAmount(): void
    {
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);
        $captured = null;

        $claims->method('transaction')->willReturnCallback(static fn(callable $cb): mixed => $cb());
        $claims->method('lockOrderDetailInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'goods_id' => 10,
            'quantity' => 2, 'status' => 'delivered', 'option_mode' => 'NONE',
            'goods_price' => 10000, 'option_price' => 500, 'option_id' => 0, 'option_code' => '',
        ]);
        $claims->method('getActiveQuantityForUpdate')->willReturn(0);
        $orders->method('findByOrderNoInDomain')->willReturn([
            'order_no' => 'ORD1', 'member_id' => 5, 'order_status' => 'delivered',
            'shipping_breakdown' => json_encode([['goods_ids' => [10], 'return_cost' => 2500]]),
        ]);
        $claims->method('createClaim')->willReturnCallback(
            static function (array $data) use (&$captured): int {
                $captured = $data;
                return 89;
            }
        );
        $claims->method('addLog')->willReturn(1);

        $service = $this->makeService($claims, $orders, $this->passthroughResolver(), new EventDispatcher());
        $result = $service->request(
            1, 'ORD1', 3, ['quantity' => 2, 'reason_type' => 'DEFECT'], 'CUSTOMER', 5, 'RETURN'
        );

        $this->assertTrue($result->isSuccess());
        // 불량은 판매자 귀책 → 반품비 없이 전액 환불
        $this->assertSame(0, $captured['return_shipping_fee']);
        $this->assertSame(21000, $captured['refund_amount']);
    }

    public function testUnknownClaimTypeIsRejected(): void
    {
        $service = $this->makeService(
            $this->createMock(ClaimRepository::class),
            $this->createMock(OrderRepository::class),
            $this->passthroughResolver(),
            new EventDispatcher(),
        );

        $result = $service->request(1, 'ORD1', 3, ['quantity' => 1], 'CUSTOMER', 5, 'CANCEL');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('클레임 유형', $result->getMessage());
    }

    public function testCompletedExchangeReleasesItsQuantityForAnotherClaim(): void
    {
        // 교환 완료는 교체품이 고객에게 있는 상태다. 그 교체품이 또 하자일 수 있으므로
        // 수량이 되돌아와야 한다 (반품 완료는 물건이 우리에게 왔으므로 소진된다).
        $this->assertFalse(
            \Mublo\Packages\Shop\Enum\ClaimStatus::COMPLETED->consumesQuantity('EXCHANGE'),
            '교환 완료분은 다시 신청할 수 있어야 합니다.'
        );
        $this->assertTrue(\Mublo\Packages\Shop\Enum\ClaimStatus::COMPLETED->consumesQuantity('RETURN'));
        $this->assertTrue(\Mublo\Packages\Shop\Enum\ClaimStatus::COMPLETED->consumesQuantity('CANCEL'));
        // 진행 중인 교환은 그대로 잡혀 있어야 이중 신청이 막힌다
        $this->assertTrue(\Mublo\Packages\Shop\Enum\ClaimStatus::COLLECTING->consumesQuantity('EXCHANGE'));
        // 거절·취소·종결은 물건이 고객에게 있으므로 되돌아온다
        $this->assertFalse(\Mublo\Packages\Shop\Enum\ClaimStatus::REFUSED->consumesQuantity('EXCHANGE'));
        $this->assertFalse(\Mublo\Packages\Shop\Enum\ClaimStatus::CLOSED->consumesQuantity('RETURN'));
    }

    public function testExchangeAndReturnCanCoexistOnTheSameOrderLine(): void
    {
        // 3개 중 2개는 불량이라 교환, 1개는 필요 없어져 반품 — 서로 다른 개체다
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);
        $captured = null;

        $claims->method('transaction')->willReturnCallback(static fn(callable $cb): mixed => $cb());
        $claims->method('lockOrderDetailInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'goods_id' => 10,
            'quantity' => 3, 'status' => 'delivered', 'option_mode' => 'NONE',
            'goods_price' => 10000, 'option_price' => 0, 'option_id' => 0, 'option_code' => '',
        ]);
        // 이미 교환 2개가 걸려 있다
        $claims->method('getActiveQuantityForUpdate')->willReturn(2);
        $orders->method('findByOrderNoInDomain')->willReturn([
            'order_no' => 'ORD1', 'member_id' => 5, 'order_status' => 'delivered',
            'shipping_breakdown' => json_encode([['goods_ids' => [10], 'return_cost' => 2500]]),
        ]);
        $claims->method('createClaim')->willReturnCallback(
            static function (array $data) use (&$captured): int {
                $captured = $data;
                return 90;
            }
        );
        $claims->method('addLog')->willReturn(1);

        $service = $this->makeService($claims, $orders, $this->passthroughResolver(), new EventDispatcher());
        $result = $service->request(
            1, 'ORD1', 3, ['quantity' => 1, 'reason_type' => 'CHANGE_MIND'], 'CUSTOMER', 5, 'RETURN'
        );

        $this->assertTrue($result->isSuccess(), '남은 1개는 반품으로 신청할 수 있어야 합니다.');
        $this->assertSame('RETURN', $captured['return_type']);
        $this->assertSame(1, $captured['quantity']);
    }

    public function testClaimIsRejectedOnceTheWholeQuantityIsSpokenFor(): void
    {
        // 교환 2개 + 반품 1개로 3개가 다 찼으면 더 신청할 수 없다
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);

        $claims->method('transaction')->willReturnCallback(static fn(callable $cb): mixed => $cb());
        $claims->method('lockOrderDetailInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'goods_id' => 10,
            'quantity' => 3, 'status' => 'delivered', 'option_mode' => 'NONE',
            'goods_price' => 10000, 'option_price' => 0, 'option_id' => 0, 'option_code' => '',
        ]);
        $claims->method('getActiveQuantityForUpdate')->willReturn(3);
        $claims->expects($this->never())->method('createClaim');

        $service = $this->makeService($claims, $orders, $this->passthroughResolver(), new EventDispatcher());
        $result = $service->request(
            1, 'ORD1', 3, ['quantity' => 1, 'reason_type' => 'CHANGE_MIND'], 'CUSTOMER', 5, 'RETURN'
        );

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('수량을 초과', $result->getMessage());
    }

    // =========================================================
    // 반품 완료 후 주문상품 상태
    // =========================================================

    /** 반품 완료 전이를 태우고, 주문상품에 어떤 상태 전이가 요청됐는지 돌려준다 */
    private function runReturnCompletion(int $orderedQuantity, int $completedQuantity): ?array
    {
        $claim = [
            'return_id' => 77, 'domain_id' => 1, 'order_no' => 'ORD1', 'order_detail_id' => 3,
            'return_type' => 'RETURN', 'return_status' => 'READY_TO_REFUND', 'quantity' => $completedQuantity,
        ];
        $claims = $this->createMock(ClaimRepository::class);
        $orders = $this->createMock(OrderRepository::class);
        $orderFlow = $this->createMock(\Mublo\Packages\Shop\Service\OrderService::class);
        $advanced = null;

        $claims->method('transaction')->willReturnCallback(static fn(callable $cb): mixed => $cb());
        $claims->method('findForUpdate')->willReturn($claim);
        $claims->method('compareAndSetStatus')->willReturn(true);
        $claims->method('addLog')->willReturn(1);
        $claims->method('getActiveByDetailId')->willReturn([]);
        $claims->method('hasCompletedClaim')->willReturn(true);
        $claims->method('getCompletedQuantity')->willReturn($completedQuantity);
        $claims->method('findInDomain')->willReturn($claim);
        $orders->method('getItemInDomain')->willReturn([
            'order_no' => 'ORD1', 'order_detail_id' => 3, 'quantity' => $orderedQuantity,
        ]);
        $orderFlow->method('advanceItemsToAction')->willReturnCallback(
            static function (string $orderNo, int $domainId, $action, array $detailIds) use (&$advanced): int {
                $advanced = ['action' => $action, 'detail_ids' => $detailIds];
                return 1;
            }
        );

        $service = $this->makeService($claims, $orders, $this->passthroughResolver(), new EventDispatcher(), $orderFlow);
        $this->assertTrue($service->completeRefund(1, 77, 5)->isSuccess());

        return $advanced;
    }

    public function testFullyReturnedItemMovesToReturned(): void
    {
        // 전량이 돌아왔으면 주문상품도 반품완료여야 한다 —
        // 배송완료로 남으면 돌려보낸 상품을 고객이 구매확정할 수 있다
        $advanced = $this->runReturnCompletion(orderedQuantity: 2, completedQuantity: 2);

        $this->assertNotNull($advanced);
        $this->assertSame(OrderAction::RETURNED, $advanced['action']);
        $this->assertSame([3], $advanced['detail_ids']);
    }

    public function testPartiallyReturnedItemStaysDelivered(): void
    {
        // 3개 중 1개만 돌아왔으면 남은 2개가 살아 있으므로 줄 상태를 바꾸지 않는다
        $this->assertNull($this->runReturnCompletion(orderedQuantity: 3, completedQuantity: 1));
    }

    private function makeService(
        ClaimRepository $claims,
        OrderRepository $orders,
        OrderStateResolver $resolver,
        EventDispatcher $dispatcher,
        ?\Mublo\Packages\Shop\Service\OrderService $orderFlow = null,
    ): ClaimService {
        $productOptions = $this->createMock(ProductOptionRepository::class);
        return new ClaimService(
            $claims,
            $orders,
            $productOptions,
            $resolver,
            new ClaimStateMachine(),
            new ExchangeStockService(
                $claims,
                $this->createMock(\Mublo\Packages\Shop\Repository\ProductRepository::class),
                $productOptions,
            ),
            $this->createMock(ShipmentService::class),
            $this->createMock(SensitiveValueCodecInterface::class),
            $dispatcher,
            $orderFlow,
        );
    }
}
