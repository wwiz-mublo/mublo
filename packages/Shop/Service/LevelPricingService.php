<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\LevelPricingRepository;

class LevelPricingService
{
    private LevelPricingRepository $levelPricingRepository;

    public function __construct(LevelPricingRepository $levelPricingRepository)
    {
        $this->levelPricingRepository = $levelPricingRepository;
    }

    public function getByDomain(int $domainId): array
    {
        return $this->levelPricingRepository->getByDomain($domainId);
    }

    public function getByLevel(int $domainId, int $levelValue): ?array
    {
        return $this->levelPricingRepository->getByLevel($domainId, $levelValue);
    }

    public function savePolicy(int $domainId, int $levelValue, array $data): Result
    {
        $allowed = [
            'discount_rate', 'reward_rate',
            'free_shipping', 'free_shipping_threshold',
            'auto_coupon_group_id',
        ];
        $filtered = array_intersect_key($data, array_flip($allowed));
        $filtered['discount_rate'] = max(0, min(100, (float) ($filtered['discount_rate'] ?? 0)));
        $filtered['reward_rate'] = max(0, min(100, (float) ($filtered['reward_rate'] ?? 0)));
        $filtered['free_shipping'] = (int) (bool) ($filtered['free_shipping'] ?? 0);
        $filtered['free_shipping_threshold'] = max(0, (int) ($filtered['free_shipping_threshold'] ?? 0));
        $filtered['auto_coupon_group_id'] = !empty($filtered['auto_coupon_group_id'])
            ? (int) $filtered['auto_coupon_group_id']
            : null;

        $ok = $this->levelPricingRepository->upsert($domainId, $levelValue, $filtered);

        return $ok
            ? Result::success('등급 정책이 저장되었습니다.')
            : Result::failure('등급 정책 저장에 실패했습니다.');
    }

    public function deletePolicy(int $domainId, int $levelValue): Result
    {
        $ok = $this->levelPricingRepository->delete($domainId, $levelValue);
        return $ok
            ? Result::success('등급 정책이 삭제되었습니다.')
            : Result::failure('등급 정책 삭제에 실패했습니다.');
    }

    /**
     * 회원 등급에 따른 할인율 적용 가격 계산
     *
     * @return array ['price' => int, 'discount_amount' => int, 'discount_rate' => float]
     */
    public function applyLevelDiscount(int $domainId, int $levelValue, int $price): array
    {
        $policy = $this->levelPricingRepository->getByLevel($domainId, $levelValue);
        if (!$policy || (float) $policy['discount_rate'] <= 0) {
            return ['price' => $price, 'discount_amount' => 0, 'discount_rate' => 0.0];
        }

        $rate = (float) $policy['discount_rate'];
        $discountAmount = (int) round($price * $rate / 100);
        $finalPrice = max(0, $price - $discountAmount);

        return [
            'price' => $finalPrice,
            'discount_amount' => $discountAmount,
            'discount_rate' => $rate,
        ];
    }

    /**
     * 회원 등급에 따른 무료배송 여부 확인
     */
    public function isFreeShipping(int $domainId, int $levelValue, int $orderAmount): bool
    {
        $policy = $this->levelPricingRepository->getByLevel($domainId, $levelValue);
        if (!$policy || !(int) $policy['free_shipping']) {
            return false;
        }

        $threshold = (int) $policy['free_shipping_threshold'];
        return $threshold === 0 || $orderAmount >= $threshold;
    }
}
