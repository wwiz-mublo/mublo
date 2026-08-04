<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Action;

use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;

/**
 * StockDeductActionHandler
 *
 * 주문 상태 진입 시 주문 아이템의 재고를 자동 차감한다.
 * 관리자가 결제완료, 배송준비 등 원하는 시점에 배치할 수 있다.
 *
 * 재고 차감 대상:
 * - NONE: shop_products.stock_quantity
 * - SINGLE: shop_product_option_values.stock_quantity
 * - COMBINATION: shop_product_option_combos.stock_quantity
 *
 * stock_quantity가 NULL(미관리)인 경우 차감하지 않음.
 * idempotency: shop_order_details.stock_deducted 플래그로 중복 차감 방지.
 */
class StockDeductActionHandler implements ActionHandlerInterface
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

        $deducted = 0;
        $shortages = []; // 재고 부족(관리 대상인데 못 채운) 아이템

        foreach ($items as $item) {
            $detailId = (int) ($item['order_detail_id'] ?? 0);
            if ($detailId <= 0) {
                continue;
            }

            // 이미 차감된 아이템 스킵
            if (!empty($item['stock_deducted'])) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $optionMode = $item['option_mode'] ?? 'NONE';
            $optionId = (int) ($item['option_id'] ?? 0);
            $goodsId = (int) ($item['goods_id'] ?? 0);
            $optionCode = (string) ($item['option_code'] ?? '');

            // 재고 차감과 완료 플래그를 원자적으로. 둘 사이에 프로세스가 죽으면 플래그 없이 차감만
            // 커밋되어, Action 재시도(stale lease 회수) 시 같은 아이템이 다시 차감된다(이중 차감).
            // 트랜잭션으로 묶어 '차감+플래그' 또는 '둘 다 롤백'만 남게 한다.
            $db = $this->orderRepository->getDb();
            $db->beginTransaction();
            try {
                $affected = $this->deductByMode($optionMode, $goodsId, $optionId, $optionCode, $quantity);
                if ($affected > 0) {
                    $this->orderRepository->updateItemFlags($detailId, ['stock_deducted' => 1]);
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("[SHOP][STOCK] 재고 차감 트랜잭션 실패 order={$orderNo} detail={$detailId}: " . $e->getMessage());
                continue;
            }

            if ($affected > 0) {
                $deducted++;
                continue;
            }

            // 차감 0행: 미관리(NULL 재고)면 의도된 skip, 관리 대상인데 부족이면 오버셀 → 시끄럽게 알림.
            $current = $this->currentStock($optionMode, $goodsId, $optionId, $optionCode);
            if ($current === null) {
                continue; // 재고 미관리 상품 — 정상 skip
            }

            $shortages[] = [
                'name' => (string) ($item['product_name'] ?? $item['goods_name'] ?? ('상품#' . $goodsId)),
                'need' => $quantity,
                'have' => $current,
            ];
        }

        // 재고 부족을 조용히 넘기지 않는다: 주문 로그 + 운영 로그로 운영자에게 노출.
        if (!empty($shortages)) {
            $this->reportShortages($event, $shortages);
        }
    }

    /**
     * 모드별 현재 재고 조회 (미관리면 null)
     */
    private function currentStock(string $mode, int $goodsId, int $optionId, string $optionCode): ?int
    {
        if ($mode === 'COMBINATION' || $mode === 'SINGLE') {
            return $this->optionRepository->getOptionStock($mode, $optionId, $optionCode);
        }
        return $goodsId > 0 ? $this->productRepository->getStock($goodsId) : null;
    }

    /**
     * 재고 부족을 주문 로그 + 운영 로그에 기록 (오버셀 무통보 방지)
     */
    private function reportShortages(OrderStatusChangedEvent $event, array $shortages): void
    {
        $orderNo = $event->getOrderNo();
        $parts = array_map(
            static fn(array $s): string => "{$s['name']} (필요 {$s['need']}/재고 {$s['have']})",
            $shortages
        );
        $reason = '재고 부족으로 차감되지 않은 항목: ' . implode(', ', $parts);

        try {
            $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'prev_status' => $event->getNewStateId(),
                'prev_status_label' => $event->getNewLabel(),
                'new_status' => $event->getNewStateId(),
                'new_status_label' => $event->getNewLabel(),
                'change_type' => 'STOCK',
                'changed_by' => 'SYSTEM',
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            // 로그 기록 실패해도 운영 로그로는 반드시 남긴다
        }

        error_log("[SHOP][STOCK] 재고 부족 오버셀 주문={$orderNo}: {$reason}");
    }

    private function deductByMode(string $mode, int $goodsId, int $optionId, string $optionCode, int $quantity): int
    {
        $delta = -$quantity;

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
        return 'stock_deduct';
    }

    public function getLabel(): string
    {
        return '재고 차감';
    }

    public function getDescription(): string
    {
        return '주문 아이템의 재고를 자동으로 차감합니다. 결제완료, 배송준비 등 원하는 시점에 설정하세요.';
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
