<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\ShipmentRegisteredEvent;
use Mublo\Packages\Shop\Event\ShipmentStatusChangedEvent;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\ShipmentGroupResolver;

/**
 * 송장이 주문상품 상태를 끌고 간다.
 *
 * 송장 등록·배송완료는 물건이 실제로 어디까지 갔는지를 말해주는 유일한 신호인데,
 * 그동안 주문/주문상품 상태와 완전히 분리돼 있었다(이벤트는 발행됐지만 구독자가
 * 없었다). 그 결과 운영자가 배송을 다 끝내도 주문상품은 주문접수에 머물렀고,
 * 주문상품 상태를 보는 자리(교환 신청 등)가 열리지 않았다.
 *
 * - 송장 등록      → 그 송장이 싣고 있는 상품을 '배송중'으로
 * - 배송완료(DELIVERED) → 같은 상품을 '배송완료'로
 *
 * 대상 상품은 좁은 지정을 우선한다: 품목 지정 > 배송비 그룹 > 주문 전체.
 * 교환 송장(claim_id)은 교환 워크플로우가 소유하므로 건드리지 않는다.
 */
class ShipmentItemStatusSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OrderService $orderService,
        private OrderRepository $orderRepository,
        private ShipmentGroupResolver $groupResolver,
        private ?Logger $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ShipmentRegisteredEvent::class => ['onRegistered', -20],
            ShipmentStatusChangedEvent::class => ['onStatusChanged', -20],
        ];
    }

    public function onRegistered(ShipmentRegisteredEvent $event): void
    {
        $this->advance($event->getOrderNo(), $event->getShipment(), OrderAction::SHIPPING, '송장 등록');
    }

    public function onStatusChanged(ShipmentStatusChangedEvent $event): void
    {
        // FAILED(배송 실패)는 되돌리지 않는다. 재배송인지 반송인지는 운영자가
        // 판단할 문제고, 자동으로 상태를 내리면 고객 화면이 널뛴다.
        $action = match ($event->getNewStatus()) {
            'PICKED_UP', 'IN_TRANSIT' => OrderAction::SHIPPING,
            'DELIVERED' => OrderAction::DELIVERED,
            default => null,
        };
        if ($action === null) {
            return;
        }

        $label = $action === OrderAction::DELIVERED ? '배송완료 처리' : '배송 상태 갱신';
        $this->advance($event->getOrderNo(), $event->getShipment(), $action, $label);
    }

    private function advance(string $orderNo, array $shipment, OrderAction $action, string $reason): void
    {
        if (!empty($shipment['claim_id'])) {
            return; // 교환 송장은 교환 관리가 소유한다
        }
        if ($orderNo === '') {
            return;
        }

        try {
            $order = $this->orderRepository->find($orderNo);
            if ($order === null) {
                return;
            }
            $orderArray = $order->toArray();
            $domainId = (int) ($orderArray['domain_id'] ?? 0);
            if ($domainId <= 0) {
                return;
            }

            $items = $this->orderRepository->getItems($orderNo);
            $detailIds = $this->groupResolver->detailIdsForShipment($shipment, $orderArray, $items);
            if ($detailIds === []) {
                return;
            }

            $this->orderService->advanceItemsToAction(
                $orderNo,
                $domainId,
                $action,
                $detailIds,
                $reason,
                'SYSTEM',
            );
        } catch (\Throwable $e) {
            // 송장 처리 자체는 이미 성공했다. 상태 반영 실패로 운영자 작업을
            // 되돌리지 않고, 기록만 남긴다.
            $this->logger?->warning('송장 기반 주문상품 상태 반영 실패', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
