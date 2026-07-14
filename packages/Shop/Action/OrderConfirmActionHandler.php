<?php

namespace Mublo\Packages\Shop\Action;

use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Infrastructure\Log\Logger;

/**
 * OrderConfirmActionHandler
 *
 * 주문 상태 진입 시 주문 아이템 전체를 완료 처리한다.
 * 구매확정, 배송완료 등 종료 상태에서 아이템의 is_completed 플래그를 일괄 갱신.
 */
class OrderConfirmActionHandler implements ActionHandlerInterface
{
    private OrderRepository $orderRepository;

    public function __construct(OrderRepository $orderRepository, private ?Logger $logger = null)
    {
        $this->orderRepository = $orderRepository;
    }

    public function execute(array $config, OrderStatusChangedEvent $event): void
    {
        $order = $event->getOrder();
        $orderNo = $event->getOrderNo();

        $items = $this->orderRepository->getItems($orderNo);
        if (empty($items)) {
            return;
        }

        $count = 0;
        foreach ($items as $item) {
            $detailId = (int) ($item['order_detail_id'] ?? 0);
            if ($detailId <= 0) {
                continue;
            }

            if (!$this->orderRepository->updateItemFlags($detailId, [
                'is_completed' => 1,
            ])) {
                throw new \RuntimeException("주문상품 {$detailId} 완료 처리에 실패했습니다.");
            }
            $count++;
        }

        $this->logger?->info('OrderStateAction:order_confirm 주문 확정 처리', [
            'order_no' => $orderNo,
            'items_count' => $count,
        ]);
    }

    public function getType(): string
    {
        return 'order_confirm';
    }

    public function getLabel(): string
    {
        return '주문 확정';
    }

    public function getDescription(): string
    {
        return '주문 아이템 전체를 완료(is_completed) 처리합니다. 구매확정 등 종료 상태 진입 시 사용합니다.';
    }

    public function allowDuplicate(): bool
    {
        return false;
    }

    public function getSchema(): array
    {
        return [
            'required' => [],
            'fields' => [],
        ];
    }
}
