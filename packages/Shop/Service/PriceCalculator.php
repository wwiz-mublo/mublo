<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Enum\ShippingMethod;

class PriceCalculator
{
    /**
     * Calculate sale price after discount
     * Rule: value >= 100 → fixed amount, value < 100 → percentage
     * @return array ['sales_price', 'discount_amount', 'discount_percent']
     */
    public function calculateSalesPrice(int $displayPrice, DiscountType $type, float $value, array $shopConfig = []): array
    {
        if (!$type->isApplicable() || $value <= 0) {
            return ['sales_price' => $displayPrice, 'discount_amount' => 0, 'discount_percent' => 0];
        }

        // BASIC uses config default value
        if ($type === DiscountType::BASIC) {
            $value = (float) ($shopConfig['discount_value'] ?? 0);
            if ($value <= 0) {
                return ['sales_price' => $displayPrice, 'discount_amount' => 0, 'discount_percent' => 0];
            }
        }

        // Calculate discount
        if ($value >= 100) {
            // Fixed amount
            $discountAmount = (int) $value;
            $salesPrice = max(0, $displayPrice - $discountAmount);
            $discountPercent = $displayPrice > 0 ? round(($discountAmount / $displayPrice) * 100) : 0;
        } else {
            // Percentage
            $discountPercent = $value;
            $discountAmount = (int) round($displayPrice * ($value / 100));
            $salesPrice = max(0, $displayPrice - $discountAmount);
        }

        return [
            'sales_price' => $salesPrice,
            'discount_amount' => $discountAmount,
            'discount_percent' => (int) $discountPercent,
        ];
    }

    /**
     * Calculate reward points
     * Same rule: value >= 100 → fixed, value < 100 → percentage
     * @return array ['point_amount', 'reward_percent']
     */
    public function calculateRewardPoints(int $salesPrice, RewardType $type, float $value, array $shopConfig = []): array
    {
        if (!$type->isApplicable() || $value <= 0) {
            return ['point_amount' => 0, 'reward_percent' => 0];
        }

        if ($type === RewardType::BASIC) {
            $value = (float) ($shopConfig['reward_value'] ?? 0);
            if ($value <= 0) {
                return ['point_amount' => 0, 'reward_percent' => 0];
            }
        }

        if ($value >= 100) {
            $pointAmount = (int) $value;
            $rewardPercent = $salesPrice > 0 ? round(($pointAmount / $salesPrice) * 100) : 0;
        } else {
            $rewardPercent = $value;
            $pointAmount = (int) round($salesPrice * ($value / 100));
        }

        return [
            'point_amount' => $pointAmount,
            'reward_percent' => (int) $rewardPercent,
        ];
    }

    /**
     * Calculate shipping fee based on template
     * @return int shipping fee
     */
    public function calculateShippingFee(array $template, int $totalPrice, int $quantity): int
    {
        $method = ShippingMethod::tryFrom($template['shipping_method'] ?? '') ?? ShippingMethod::FREE;
        $basicCost = (int) ($template['basic_cost'] ?? 0);
        $freeThreshold = (int) ($template['free_threshold'] ?? 0);

        return match ($method) {
            ShippingMethod::FREE => 0,
            ShippingMethod::PAID => $basicCost,
            ShippingMethod::COND => ($freeThreshold > 0 && $totalPrice >= $freeThreshold) ? 0 : $basicCost,
            ShippingMethod::QUANTITY => $this->calculateByQuantity($template, $quantity),
            ShippingMethod::AMOUNT => $this->calculateByAmount($template, $totalPrice),
        };
    }

    /**
     * 도서산간 추가배송비 계산
     *
     * 배송지 우편번호가 템플릿의 extra_cost_ranges 대역에 들면 그 추가요금을 반환한다.
     * 기본 배송비(calculateShippingFee)와 독립적으로 가산되므로, 무료배송이어도 부과된다.
     *
     * 대역이 겹치면(예: 넓은 "광주" 안에 좁은 "광주시청") **가장 좁은(구체적인) 대역**이 이긴다.
     * → 좁은 행 = 의도된 예외/override. 싼 예외든 비싼 예외든 그대로 적용되고, 입력 순서에
     *   의존하지 않아 정렬 UI가 필요 없다. 너비(to-from)가 같으면 더 비싼 추가비로 tie-break.
     *
     * @return array ['cost' => int, 'label' => string]  // 미해당이면 cost=0
     */
    public function calculateExtraShippingFee(array $template, ?string $zipcode): array
    {
        $none = ['cost' => 0, 'label' => ''];

        if (empty($template['extra_cost_enabled']) || $zipcode === null) {
            return $none;
        }

        $zip = (int) preg_replace('/[^0-9]/', '', $zipcode);
        if ($zip <= 0) {
            return $none;
        }

        $ranges = $template['extra_cost_ranges'] ?? [];
        if (is_string($ranges)) {
            $ranges = json_decode($ranges, true) ?: [];
        }

        $best = null; // ['cost', 'label', 'width']
        foreach ($ranges as $range) {
            $from = (int) ($range['zip_from'] ?? 0);
            $to = (int) ($range['zip_to'] ?? 0);
            if ($from <= 0 || $to <= 0 || $zip < $from || $zip > $to) {
                continue;
            }
            $width = $to - $from;
            $cost = (int) ($range['cost'] ?? 0);
            // 더 좁으면 우선, 너비가 같으면 더 비싼 쪽
            if ($best === null || $width < $best['width'] || ($width === $best['width'] && $cost > $best['cost'])) {
                $best = ['cost' => $cost, 'label' => (string) ($range['label'] ?? ''), 'width' => $width];
            }
        }

        return $best === null ? $none : ['cost' => $best['cost'], 'label' => $best['label']];
    }

    private function calculateByQuantity(array $template, int $quantity): int
    {
        $basicCost = (int) ($template['basic_cost'] ?? 0);
        $perUnit = (int) ($template['goods_per_unit'] ?? 1);
        if ($perUnit <= 0) $perUnit = 1;
        return $basicCost * (int) ceil($quantity / $perUnit);
    }

    private function calculateByAmount(array $template, int $totalPrice): int
    {
        $ranges = $template['price_ranges'] ?? [];
        if (is_string($ranges)) {
            $ranges = json_decode($ranges, true) ?: [];
        }
        foreach ($ranges as $range) {
            $min = (int) ($range['min'] ?? 0);
            $max = (int) ($range['max'] ?? PHP_INT_MAX);
            if ($totalPrice >= $min && $totalPrice <= $max) {
                return (int) ($range['cost'] ?? 0);
            }
        }
        return (int) ($template['basic_cost'] ?? 0);
    }

    /**
     * 주문의 최종 결제 금액 계산
     *
     * 상품 합계 + 배송비 + 추가금액 + 세금 - 포인트 - 쿠폰
     *
     * @param array $orderData 주문 데이터 (total_price, shipping_fee, extra_price, tax_amount, point_used, coupon_discount)
     */
    public function calculatePaymentAmount(array $orderData): int
    {
        return (int) ($orderData['total_price'] ?? 0)
             + (int) ($orderData['shipping_fee'] ?? 0)
             + (int) ($orderData['extra_price'] ?? 0)
             + (int) ($orderData['tax_amount'] ?? 0)
             - (int) ($orderData['point_used'] ?? 0)
             - (int) ($orderData['coupon_discount'] ?? 0);
    }
}
