<?php

namespace Mublo\Packages\Shop\Action;

use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderItemStatusChangedEvent;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;

/**
 * StockRestoreActionHandler
 *
 * 주문 취소/반품 등 상태 진입 시 차감된 재고를 자동 복구한다.
 * 관리자가 주문취소, 반품완료 등 원하는 시점에 배치할 수 있다.
 *
 * stock_deducted 플래그가 1인 아이템만 복구 대상.
 * 복구 후 플래그를 0으로 리셋하여 중복 복구 방지.
 */
class StockRestoreActionHandler implements ActionHandlerInterface, ItemActionHandlerInterface
{
    public function __construct(
        private OrderRepository $orderRepository,
        private ProductRepository $productRepository,
        private ProductOptionRepository $optionRepository,
    ) {}

    public function execute(array $config, OrderStatusChangedEvent $event): void
    {
        $orderNo = $event->getOrderNo();
        $items = $this->orderRepository->getItems($orderNo);

        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            $this->restoreItem($item);
        }
    }

    public function executeForItem(array $config, OrderItemStatusChangedEvent $event): void
    {
        $this->restoreItem($event->getItem());
    }

    private function restoreItem(array $item): bool
    {
        $detailId = (int) ($item['order_detail_id'] ?? 0);
        if ($detailId <= 0 || empty($item['stock_deducted'])) {
            return false;
        }

        $affected = $this->restoreByMode(
            (string) ($item['option_mode'] ?? 'NONE'),
            (int) ($item['goods_id'] ?? 0),
            (int) ($item['option_id'] ?? 0),
            (string) ($item['option_code'] ?? ''),
            (int) ($item['quantity'] ?? 1),
        );
        if ($affected <= 0) {
            return false;
        }

        $this->orderRepository->updateItemFlags($detailId, ['stock_deducted' => 0]);
        return true;
    }

    private function restoreByMode(string $mode, int $goodsId, int $optionId, string $optionCode, int $quantity): int
    {
        $delta = $quantity; // 양수 = 증가

        switch ($mode) {
            case 'COMBINATION':
                if ($optionId > 0) {
                    return $this->optionRepository->adjustComboStock($optionId, $delta);
                }
                break;

            case 'SINGLE':
                $valueId = $this->resolveSingleValueId($optionCode);
                if ($valueId > 0) {
                    return $this->optionRepository->adjustValueStock($valueId, $delta);
                }
                break;

            default: // NONE
                if ($goodsId > 0) {
                    return $this->productRepository->adjustStock($goodsId, $delta);
                }
                break;
        }

        return 0;
    }

    private function resolveSingleValueId(string $optionCode): int
    {
        if (preg_match('/^opt-\d+-(\d+)$/', $optionCode, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    public function getType(): string
    {
        return 'stock_restore';
    }

    public function getLabel(): string
    {
        return '재고 복구';
    }

    public function getDescription(): string
    {
        return '차감된 재고를 자동으로 복구합니다. 주문취소, 반품완료 등 원하는 시점에 설정하세요.';
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
