<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Repository\ClaimRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;

final class ExchangeStockService
{
    public function __construct(
        private ClaimRepository $claims,
        private ProductRepository $products,
        private ProductOptionRepository $options,
    ) {}

    public function reserveReplacement(array $claim): bool
    {
        if (($claim['stock_reservation_status'] ?? 'NONE') === 'RESERVED') {
            return true;
        }
        if (($claim['stock_reservation_status'] ?? 'NONE') !== 'NONE') {
            return false;
        }

        $quantity = max(1, (int) ($claim['exchange_quantity'] ?? 1));
        if (!$this->adjust(
            (int) ($claim['domain_id'] ?? 0),
            (string) ($claim['target_option_mode'] ?? 'NONE'),
            (int) ($claim['target_goods_id'] ?? 0),
            (int) ($claim['target_option_id'] ?? 0),
            (string) ($claim['target_option_code'] ?? ''),
            -$quantity,
        )) {
            return false;
        }

        return $this->claims->updateExchangeItem((int) $claim['return_id'], [
            'stock_reservation_status' => 'RESERVED',
            'stock_reserved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function releaseReplacement(array $claim): bool
    {
        if (($claim['stock_reservation_status'] ?? '') === 'RELEASED') {
            return true;
        }
        if (($claim['stock_reservation_status'] ?? '') !== 'RESERVED') {
            return true;
        }
        $quantity = max(1, (int) ($claim['exchange_quantity'] ?? 1));
        if (!$this->adjust(
            (int) ($claim['domain_id'] ?? 0),
            (string) ($claim['target_option_mode'] ?? 'NONE'),
            (int) ($claim['target_goods_id'] ?? 0),
            (int) ($claim['target_option_id'] ?? 0),
            (string) ($claim['target_option_code'] ?? ''),
            $quantity,
        )) {
            return false;
        }
        return $this->claims->updateExchangeItem((int) $claim['return_id'], [
            'stock_reservation_status' => 'RELEASED',
            'stock_released_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markReplacementShipped(array $claim): bool
    {
        if (($claim['stock_reservation_status'] ?? '') === 'SHIPPED') {
            return true;
        }
        if (($claim['stock_reservation_status'] ?? '') !== 'RESERVED') {
            return false;
        }
        return $this->claims->updateExchangeItem((int) $claim['return_id'], [
            'stock_reservation_status' => 'SHIPPED',
        ]);
    }

    public function restockSource(array $claim, string $inspectionResult): bool
    {
        if ($inspectionResult !== 'SALEABLE' || !empty($claim['source_restocked_at'])) {
            return true;
        }
        // 검수 기록은 클레임 본체에 남긴다 — 반품에는 교환 대상 상품 행이 없다.
        if (empty($claim['source_stock_deducted'])) {
            return $this->markSourceRestocked($claim);
        }
        $quantity = max(1, (int) ($claim['exchange_quantity'] ?? $claim['quantity'] ?? 1));
        if (!$this->adjust(
            (int) ($claim['domain_id'] ?? 0),
            (string) ($claim['source_option_mode'] ?? 'NONE'),
            (int) ($claim['source_goods_id'] ?? 0),
            (int) ($claim['source_option_id'] ?? 0),
            (string) ($claim['source_option_code'] ?? ''),
            $quantity,
        )) {
            return false;
        }
        return $this->markSourceRestocked($claim);
    }

    private function markSourceRestocked(array $claim): bool
    {
        return $this->claims->updateClaim(
            (int) ($claim['domain_id'] ?? 0),
            (int) $claim['return_id'],
            ['source_restocked_at' => date('Y-m-d H:i:s')]
        );
    }

    private function adjust(int $domainId, string $mode, int $goodsId, int $optionId, string $optionCode, int $delta): bool
    {
        if ($domainId <= 0 || $this->products->findInDomain($domainId, $goodsId) === null) {
            return false;
        }
        if ($mode === 'COMBINATION') {
            $combo = $this->options->findCombo($optionId);
            if ($combo === null || (int) ($combo['goods_id'] ?? 0) !== $goodsId) {
                return false;
            }
            $stock = ($combo['stock_quantity'] ?? null) === null ? null : (int) $combo['stock_quantity'];
            return $stock === null || $this->options->adjustComboStock($optionId, $delta) > 0;
        }
        if ($mode === 'SINGLE') {
            if (!preg_match('/^opt-\d+-(\d+)$/', $optionCode, $matches)) {
                return false;
            }
            $value = $this->options->findValue((int) $matches[1]);
            if ($value === null || (int) ($value['option_id'] ?? 0) !== $optionId) {
                return false;
            }
            $stock = ($value['stock_quantity'] ?? null) === null ? null : (int) $value['stock_quantity'];
            return $stock === null || $this->options->adjustValueStock((int) $matches[1], $delta) > 0;
        }
        $stock = $this->products->getStock($goodsId);
        return $stock === null || $this->products->adjustStock($goodsId, $delta) > 0;
    }
}
