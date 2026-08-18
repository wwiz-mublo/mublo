<?php
/**
 * packages/Shop/tests/Unit/EventSubscriber/ShipmentItemStatusSubscriberTest.php
 *
 * ShipmentItemStatusSubscriber 단위 테스트
 *
 * 송장이 주문상품 상태를 끌고 가는 배선을 검증한다. 이 배선이 없으면 운영자가
 * 배송을 끝내도 주문상품은 주문접수에 머물고, 교환 신청이 열리지 않는다.
 */

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\ShipmentRegisteredEvent;
use Mublo\Packages\Shop\Event\ShipmentStatusChangedEvent;
use Mublo\Packages\Shop\EventSubscriber\ShipmentItemStatusSubscriber;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\ShipmentGroupResolver;
use Tests\Shop\TestCase;

final class ShipmentItemStatusSubscriberTest extends TestCase
{
    private const ORDER_NO = 'ORD2026081800001';

    /** 상품 3개가 배송비 그룹 2개로 갈린 주문 */
    private function orderRepoStub(): OrderRepository
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => self::ORDER_NO,
            'shipping_breakdown' => json_encode([
                ['group_key' => 'tpl_1', 'template_name' => '조건부 무료', 'base_fee' => 3000, 'goods_ids' => [10]],
                ['group_key' => 'tpl_2', 'template_name' => '무료배송', 'base_fee' => 0, 'goods_ids' => [20, 30]],
            ]),
        ]));

        $repo = $this->createMock(OrderRepository::class);
        $repo->method('find')->willReturn($order);
        $repo->method('getItems')->willReturn([
            ['order_detail_id' => 1, 'goods_id' => 10, 'goods_name' => '티셔츠'],
            ['order_detail_id' => 2, 'goods_id' => 20, 'goods_name' => '모자'],
            ['order_detail_id' => 3, 'goods_id' => 30, 'goods_name' => '선글라스'],
        ]);
        return $repo;
    }

    private function makeSubscriber(OrderService $orders): ShipmentItemStatusSubscriber
    {
        return new ShipmentItemStatusSubscriber(
            $orders,
            $this->orderRepoStub(),
            new ShipmentGroupResolver(),
        );
    }

    public function testRegisteringShipmentMovesOnlyItsGroupToShipping(): void
    {
        $orders = $this->createMock(OrderService::class);
        $orders->expects($this->once())
            ->method('advanceItemsToAction')
            ->with(self::ORDER_NO, 1, OrderAction::SHIPPING, [2, 3], '송장 등록', 'SYSTEM')
            ->willReturn(2);

        $this->makeSubscriber($orders)->onRegistered(new ShipmentRegisteredEvent(
            7,
            self::ORDER_NO,
            ['shipment_id' => 7, 'order_no' => self::ORDER_NO, 'shipping_group_key' => 'tpl_2'],
        ));
    }

    public function testShipmentWithoutGroupCoversWholeOrder(): void
    {
        $orders = $this->createMock(OrderService::class);
        $orders->expects($this->once())
            ->method('advanceItemsToAction')
            ->with(self::ORDER_NO, 1, OrderAction::SHIPPING, [1, 2, 3])
            ->willReturn(3);

        $this->makeSubscriber($orders)->onRegistered(new ShipmentRegisteredEvent(
            7,
            self::ORDER_NO,
            ['shipment_id' => 7, 'order_no' => self::ORDER_NO],
        ));
    }

    public function testDeliveredShipmentMovesItsItemsToDelivered(): void
    {
        $orders = $this->createMock(OrderService::class);
        $orders->expects($this->once())
            ->method('advanceItemsToAction')
            ->with(self::ORDER_NO, 1, OrderAction::DELIVERED, [1])
            ->willReturn(1);

        $this->makeSubscriber($orders)->onStatusChanged(new ShipmentStatusChangedEvent(
            7,
            self::ORDER_NO,
            'IN_TRANSIT',
            'DELIVERED',
            ['shipment_id' => 7, 'order_no' => self::ORDER_NO, 'shipping_group_key' => 'tpl_1'],
        ));
    }

    public function testFailedShipmentDoesNotRollBackItemStatus(): void
    {
        // 재배송인지 반송인지는 운영자가 판단할 문제다. 자동으로 상태를 내리면
        // 고객 화면이 널뛴다.
        $orders = $this->createMock(OrderService::class);
        $orders->expects($this->never())->method('advanceItemsToAction');

        $this->makeSubscriber($orders)->onStatusChanged(new ShipmentStatusChangedEvent(
            7,
            self::ORDER_NO,
            'IN_TRANSIT',
            'FAILED',
            ['shipment_id' => 7, 'order_no' => self::ORDER_NO],
        ));
    }

    public function testClaimShipmentIsOwnedByExchangeWorkflow(): void
    {
        $orders = $this->createMock(OrderService::class);
        $orders->expects($this->never())->method('advanceItemsToAction');

        $this->makeSubscriber($orders)->onRegistered(new ShipmentRegisteredEvent(
            7,
            self::ORDER_NO,
            ['shipment_id' => 7, 'order_no' => self::ORDER_NO, 'claim_id' => 42, 'shipment_role' => 'COLLECTION'],
        ));
    }
}
