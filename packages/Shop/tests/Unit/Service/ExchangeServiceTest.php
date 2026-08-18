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
use Mublo\Packages\Shop\Service\ExchangeService;
use Mublo\Packages\Shop\Service\ExchangeStockService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Tests\Shop\TestCase;

final class ExchangeServiceTest extends TestCase
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
        $claims->method('hasBlockingNonExchangeClaim')->willReturn(false);
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
        $claims->method('hasBlockingNonExchangeClaim')->willReturn(false);
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
    // isExchangeable() — 프론트 버튼과 서버 접수의 단일 규칙
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

        $this->assertTrue($service->isExchangeable(1, ['status' => 'delivered'], 'shipping'));
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

        $this->assertTrue($service->isExchangeable(1, ['status' => 'received'], 'delivered'));
        $this->assertFalse($service->isExchangeable(1, ['status' => 'received'], 'shipping'));
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
        $this->assertFalse($service->isExchangeable(1, ['status' => 'confirmed'], 'delivered'));
        $this->assertFalse($service->isExchangeable(1, ['status' => 'cancelled'], 'delivered'));
        $this->assertFalse($service->isExchangeable(1, ['status' => 'return_requested'], 'delivered'));
    }

    private function makeService(
        ClaimRepository $claims,
        OrderRepository $orders,
        OrderStateResolver $resolver,
        EventDispatcher $dispatcher,
    ): ExchangeService {
        $productOptions = $this->createMock(ProductOptionRepository::class);
        return new ExchangeService(
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
        );
    }
}
