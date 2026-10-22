<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\Entity\CouponPolicy;
use Mublo\Packages\Shop\Service\CouponBulkIssueService;
use Mublo\Packages\Shop\Service\CouponIssueHistoryService;

/**
 * Admin CouponIssueController — 쿠폰 일괄 지급과 발급 내역
 *
 * 라우팅:
 * - GET  /admin/shop/coupons/{id}/bulk-issue          → bulkForm (조건 지급 화면)
 * - POST /admin/shop/coupons/{id}/bulk-issue/preview  → preview (대상 인원)
 * - POST /admin/shop/coupons/{id}/bulk-issue          → bulkIssue (발행)
 * - GET  /admin/shop/coupons/{id}/issued              → issued (발급 내역)
 *
 * 모든 동작은 현재 관리 중인 사이트의 정책에만 허용한다.
 */
class CouponIssueController
{
    private const VIEW_PATH = __DIR__ . '/../../views/Admin/Coupon/';

    public function __construct(
        private CouponIssueHistoryService $history,
        private CouponBulkIssueService $bulkIssueService,
        private MemberLevelCatalogInterface $levelCatalog,
        private MemberQueryInterface $memberQuery,
    ) {
    }

    public function bulkForm(array $params, Context $context): ViewResponse
    {
        $policy = $this->policy($params, $context);
        if ($policy === null) {
            return $this->notFound();
        }

        $levels = [];
        foreach ($this->levelCatalog->all() as $level) {
            if ($level->levelValue > 0 && !$level->admin && !$level->super) {
                $levels[$level->levelValue] = $level->name;
            }
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'BulkIssue')
            ->withData([
                'pageTitle' => '쿠폰 일괄 지급',
                'coupon' => $policy->toArray(),
                'levels' => $levels,
                'summary' => $this->history->getSummary($policy->getCouponGroupId()),
                'maxTargets' => CouponBulkIssueService::MAX_TARGETS,
            ]);
    }

    public function preview(array $params, Context $context): JsonResponse
    {
        $policy = $this->policy($params, $context);
        if ($policy === null) {
            return JsonResponse::error('쿠폰 정책을 찾을 수 없습니다.');
        }

        $count = count($this->bulkIssueService->findTargets((int) $context->getDomainId(), $this->criteria($context)));

        return JsonResponse::success(['count' => $count], number_format($count) . '명이 조건에 맞습니다.');
    }

    public function bulkIssue(array $params, Context $context): JsonResponse
    {
        $policy = $this->policy($params, $context);
        if ($policy === null) {
            return JsonResponse::error('쿠폰 정책을 찾을 수 없습니다.');
        }

        $result = $this->bulkIssueService->issue(
            (int) $context->getDomainId(),
            $policy->getCouponGroupId(),
            $this->criteria($context)
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function issued(array $params, Context $context): ViewResponse
    {
        $policy = $this->policy($params, $context);
        if ($policy === null) {
            return $this->notFound();
        }

        $request = $context->getRequest();
        $status = (string) ($request->get('status') ?? '');
        $status = in_array($status, ['available', 'used', 'expired'], true) ? $status : '';
        $page = max(1, (int) ($request->get('page') ?? 1));
        $perPage = 50;

        $result = $this->history->getIssuedPage($policy->getCouponGroupId(), $page, $perPage, $status);

        // 회원 표시 이름 (아이디·닉네임) — 탈퇴 등으로 없는 회원은 번호만
        $memberIds = array_values(array_unique(array_map(fn($r) => (int) $r['member_id'], $result['items'])));
        $members = [];
        foreach ($this->memberQuery->findProfiles($memberIds) as $profile) {
            $members[$profile->memberId] = ['user_id' => $profile->userId, 'name' => $profile->displayName()];
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Issued')
            ->withData([
                'pageTitle' => '쿠폰 발급 내역',
                'coupon' => $policy->toArray(),
                'items' => $result['items'],
                'members' => $members,
                'status' => $status,
                'summary' => $this->history->getSummary($policy->getCouponGroupId()),
                'pagination' => [
                    'totalItems' => $result['total'],
                    'perPage' => $perPage,
                    'currentPage' => $page,
                    'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
                ],
            ]);
    }

    private function policy(array $params, Context $context): ?CouponPolicy
    {
        return $this->history->findPolicyInDomain((int) ($params['id'] ?? 0), (int) $context->getDomainId());
    }

    /**
     * 요청 본문의 대상 조건
     */
    private function criteria(Context $context): array
    {
        $request = $context->getRequest();
        $levels = $request->json('level_values') ?? [];

        return [
            'level_values' => is_array($levels) ? array_map('intval', $levels) : [],
            'joined_from' => (string) ($request->json('joined_from') ?? ''),
            'joined_to' => (string) ($request->json('joined_to') ?? ''),
            'purchase' => in_array($request->json('purchase'), ['yes', 'no'], true) ? (string) $request->json('purchase') : '',
            'min_purchase' => max(0, (int) ($request->json('min_purchase') ?? 0)),
        ];
    }

    private function notFound(): ViewResponse
    {
        return ViewResponse::view('Error/404')->withData(['message' => '쿠폰 정책을 찾을 수 없습니다.']);
    }
}
