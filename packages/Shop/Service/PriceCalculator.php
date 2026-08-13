<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Enum\ShippingMethod;

class PriceCalculator
{
    /**
     * 할인 적용 판매가 계산
     *
     * 계산 방식은 **할인 유형이 결정한다**. 값의 크기로 정률/정액을 추정하지 않는다
     * — 그 규칙 아래서는 "50원 정액 할인"이 50% 할인으로, "100% 할인"이 100원
     * 할인으로 뒤집혔다.
     *
     *  - PERCENTAGE / FIXED : 넘어온 값을 그 방식으로 적용
     *  - LEVEL              : 회원 등급(levelId)에 해당하는 설정을 적용
     *  - DEFAULT            : 쇼핑몰 설정의 유형·값으로 한 번 재해석
     *  - BASIC              : 값 크기로 방식을 추정하던 옛 규칙 (@deprecated, 하위호환용)
     *  - NONE               : 할인 없음
     *
     * @param int|null $levelId       회원 등급 ID. 비회원이거나 알 수 없으면 null — LEVEL 은 할인 없음이 된다.
     * @param array    $levelSettings 상품의 등급별 할인 설정 (levelId => ['type', 'value'])
     * @return array ['sales_price', 'discount_amount', 'discount_percent']
     */
    public function calculateSalesPrice(
        int $displayPrice,
        DiscountType $type,
        float $value,
        array $shopConfig = [],
        ?int $levelId = null,
        array $levelSettings = []
    ): array {
        $resolved = $this->resolveDiscount($type, $value, $shopConfig, $levelId, $levelSettings);

        if ($resolved === null) {
            return ['sales_price' => $displayPrice, 'discount_amount' => 0, 'discount_percent' => 0];
        }

        [$kind, $amount] = $resolved;

        if ($kind === DiscountType::FIXED) {
            $discountAmount = (int) $amount;
            $discountPercent = $displayPrice > 0 ? (int) round(($discountAmount / $displayPrice) * 100) : 0;
        } else {
            // 100%를 넘는 정률은 표시상 100%로 묶는다 (판매가는 어차피 0에서 멈춘다)
            $rate = min(100.0, $amount);
            $discountPercent = (int) round($rate);
            $discountAmount = (int) round($displayPrice * ($rate / 100));
        }

        return [
            'sales_price' => max(0, $displayPrice - $discountAmount),
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
        ];
    }

    /**
     * 적립금 계산
     *
     * 방식 결정 규칙은 calculateSalesPrice()와 같다 — 적립 유형이 정하며,
     * 값의 크기로 추정하지 않는다.
     *
     * @param int|null $levelId       회원 등급 ID (비회원이면 null)
     * @param array    $levelSettings 상품의 등급별 적립 설정
     * @return array ['point_amount', 'reward_percent']
     */
    public function calculateRewardPoints(
        int $salesPrice,
        RewardType $type,
        float $value,
        array $shopConfig = [],
        ?int $levelId = null,
        array $levelSettings = []
    ): array {
        $resolved = $this->resolveReward($type, $value, $shopConfig, $levelId, $levelSettings);

        if ($resolved === null) {
            return ['point_amount' => 0, 'reward_percent' => 0];
        }

        [$kind, $amount] = $resolved;

        if ($kind === RewardType::FIXED) {
            $pointAmount = (int) $amount;
            $rewardPercent = $salesPrice > 0 ? (int) round(($pointAmount / $salesPrice) * 100) : 0;
        } else {
            $rewardPercent = (int) round($amount);
            $pointAmount = (int) round($salesPrice * ($amount / 100));
        }

        return [
            'point_amount' => $pointAmount,
            'reward_percent' => $rewardPercent,
        ];
    }

    /* =========================================================
     * 유형 해석 — "어떤 방식으로 얼마"까지 확정한다
     * ========================================================= */

    /**
     * 할인 유형을 구체적인 [방식, 값]으로 해석한다
     *
     * @return array{0: DiscountType, 1: float}|null 할인이 없으면 null.
     *         0번은 항상 PERCENTAGE 또는 FIXED — 나머지 유형은 여기서 다 풀린다.
     */
    private function resolveDiscount(
        DiscountType $type,
        float $value,
        array $shopConfig,
        ?int $levelId = null,
        array $levelSettings = []
    ): ?array {
        return match ($type) {
            DiscountType::NONE => null,
            DiscountType::PERCENTAGE,
            DiscountType::FIXED => $value > 0 ? [$type, $value] : null,
            DiscountType::DEFAULT => $this->resolveShopDefaultDiscount($shopConfig, $levelId),
            DiscountType::BASIC => $this->resolveBySize(
                (float) ($shopConfig['discount_value'] ?? 0),
                DiscountType::PERCENTAGE,
                DiscountType::FIXED
            ),
            DiscountType::LEVEL => $this->resolveLevelSetting(
                $levelSettings,
                $levelId,
                DiscountType::PERCENTAGE,
                DiscountType::FIXED
            ),
        };
    }

    /**
     * 적립 유형을 구체적인 [방식, 값]으로 해석한다
     *
     * @return array{0: RewardType, 1: float}|null
     */
    private function resolveReward(
        RewardType $type,
        float $value,
        array $shopConfig,
        ?int $levelId = null,
        array $levelSettings = []
    ): ?array {
        return match ($type) {
            RewardType::NONE => null,
            RewardType::PERCENTAGE,
            RewardType::FIXED => $value > 0 ? [$type, $value] : null,
            RewardType::DEFAULT => $this->resolveShopDefaultReward($shopConfig, $levelId),
            RewardType::BASIC => $this->resolveBySize(
                (float) ($shopConfig['reward_value'] ?? 0),
                RewardType::PERCENTAGE,
                RewardType::FIXED
            ),
            RewardType::LEVEL => $this->resolveLevelSetting(
                $levelSettings,
                $levelId,
                RewardType::PERCENTAGE,
                RewardType::FIXED
            ),
        };
    }

    /**
     * DEFAULT("쇼핑몰 기본설정 적용") → 쇼핑몰 설정의 유형으로 재해석
     *
     * 설정이 다시 DEFAULT를 가리키면 자기참조이므로 할인 없음으로 본다.
     */
    private function resolveShopDefaultDiscount(array $shopConfig, ?int $levelId): ?array
    {
        $configType = DiscountType::tryFrom((string) ($shopConfig['discount_type'] ?? ''))
            ?? DiscountType::NONE;

        if ($configType === DiscountType::DEFAULT) {
            return null;
        }

        // 설정이 등급별이면 등급표도 설정 쪽 것을 쓴다 (상품이 아니라 쇼핑몰 기본값이므로)
        return $this->resolveDiscount(
            $configType,
            (float) ($shopConfig['discount_value'] ?? 0),
            $shopConfig,
            $levelId,
            $this->levelSettingsFrom($shopConfig, 'discount_level_settings')
        );
    }

    private function resolveShopDefaultReward(array $shopConfig, ?int $levelId): ?array
    {
        $configType = RewardType::tryFrom((string) ($shopConfig['reward_type'] ?? ''))
            ?? RewardType::NONE;

        if ($configType === RewardType::DEFAULT) {
            return null;
        }

        return $this->resolveReward(
            $configType,
            (float) ($shopConfig['reward_value'] ?? 0),
            $shopConfig,
            $levelId,
            $this->levelSettingsFrom($shopConfig, 'reward_level_settings')
        );
    }

    /**
     * 등급별 설정에서 이 회원의 등급에 해당하는 [방식, 값]을 꺼낸다
     *
     * 등급을 모르거나(비회원) 해당 등급에 설정이 없으면 적용하지 않는다.
     *
     * @template T of DiscountType|RewardType
     * @param T $percentage
     * @param T $fixed
     * @return array{0: T, 1: float}|null
     */
    private function resolveLevelSetting(
        array $levelSettings,
        ?int $levelId,
        DiscountType|RewardType $percentage,
        DiscountType|RewardType $fixed
    ): ?array {
        if ($levelId === null || $levelSettings === []) {
            return null;
        }

        $setting = $levelSettings[$levelId] ?? $levelSettings[(string) $levelId] ?? null;
        if (!is_array($setting)) {
            return null;
        }

        $amount = (float) ($setting['value'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        return ($setting['type'] ?? 'PERCENTAGE') === 'FIXED'
            ? [$fixed, $amount]
            : [$percentage, $amount];
    }

    /**
     * 쇼핑몰 설정의 등급별 설정 컬럼 해석 (DB에는 JSON 문자열로 저장된다)
     */
    private function levelSettingsFrom(array $shopConfig, string $key): array
    {
        $raw = $shopConfig[$key] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * 값 크기로 방식을 추정하던 옛 규칙 (100 미만이면 정률, 이상이면 정액)
     *
     * @deprecated BASIC 유형으로 저장된 기존 설정을 계속 계산해 주기 위해서만 남는다.
     *             새 설정은 정률/정액을 명시하는 PERCENTAGE·FIXED를 쓴다.
     *
     * @template T of DiscountType|RewardType
     * @param T $percentage
     * @param T $fixed
     * @return array{0: T, 1: float}|null
     */
    private function resolveBySize(float $value, DiscountType|RewardType $percentage, DiscountType|RewardType $fixed): ?array
    {
        if ($value <= 0) {
            return null;
        }

        return $value >= 100 ? [$fixed, $value] : [$percentage, $value];
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
