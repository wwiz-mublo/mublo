<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Entity\CouponPolicy;
use Mublo\Packages\Shop\Repository\CouponRepository;

/**
 * 쿠폰 발급 내역 조회 (관리자) — 정책은 현재 사이트 것만 돌려준다.
 */
class CouponIssueHistoryService
{
    public function __construct(
        private CouponRepository $couponRepository
    ) {
    }

    public function findPolicyInDomain(int $couponGroupId, int $domainId): ?CouponPolicy
    {
        $policy = $this->couponRepository->find($couponGroupId);

        return ($policy instanceof CouponPolicy && $policy->getDomainId() === $domainId) ? $policy : null;
    }

    /**
     * @return array{items: array, total: int}
     */
    public function getIssuedPage(int $couponGroupId, int $page, int $perPage, string $status = ''): array
    {
        return $this->couponRepository->getIssuedPage($couponGroupId, $page, $perPage, $status);
    }

    /**
     * @return array{total:int, used:int, expired:int, available:int}
     */
    public function getSummary(int $couponGroupId): array
    {
        return $this->couponRepository->getIssueSummary($couponGroupId);
    }
}
