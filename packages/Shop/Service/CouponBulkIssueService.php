<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Contract\Member\MemberSegmentQueryInterface;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\OrderRepository;

/**
 * 쿠폰 일괄 지급 — 조건(등급·가입일·구매 이력)으로 회원을 골라 한 정책의 쿠폰을 한꺼번에 발행한다.
 *
 * 발행 자체는 회원마다 CouponService::issueByAdmin() 을 거친다 — 사이트 검증, 발행 기간,
 * 총 발행량·회원별 한도(잠금 하 재검증)가 낱개 발행과 똑같이 적용된다.
 * 이미 한도만큼 받은 회원은 건너뛴 것으로 센다.
 */
class CouponBulkIssueService
{
    /** 한 번에 지급하는 최대 인원 — 요청 시간을 넘기지 않게 */
    public const MAX_TARGETS = 5000;

    public function __construct(
        private CouponService $couponService,
        private MemberSegmentQueryInterface $members,
        private OrderRepository $orders,
    ) {
    }

    /**
     * 조건에 맞는 회원 ID
     *
     * @param array{level_values?:array, joined_from?:string, joined_to?:string, purchase?:string, min_purchase?:int|string} $criteria
     *   purchase: ''(상관없음) | 'yes'(구매 이력 있음) | 'no'(없음). min_purchase 는 누적 구매액 하한(원, 'yes' 와 함께).
     * @return list<int>
     */
    public function findTargets(int $domainId, array $criteria): array
    {
        $levels = array_map('intval', (array) ($criteria['level_values'] ?? []));
        $from = $this->dateOrNull($criteria['joined_from'] ?? null);
        $to = $this->dateOrNull($criteria['joined_to'] ?? null);

        // 구매 조건은 주문 합계로 다시 거르므로 넉넉히 뽑는다
        $ids = $this->members->findActiveMemberIds($domainId, $levels, $from, $to, 50000);

        $purchase = (string) ($criteria['purchase'] ?? '');
        $minPurchase = max(0, (int) ($criteria['min_purchase'] ?? 0));
        if ($purchase === 'yes' || $purchase === 'no' || $minPurchase > 0) {
            $totals = $this->orders->getPaidTotalsByMembers($domainId, $ids);
            $ids = array_values(array_filter($ids, function (int $id) use ($totals, $purchase, $minPurchase) {
                $has = isset($totals[$id]);
                if ($purchase === 'no') {
                    return !$has;
                }
                return $has && ($totals[$id] ?? 0) >= $minPurchase;
            }));
        }

        return $ids;
    }

    /**
     * 조건에 맞는 회원에게 발행
     *
     * @return Result 성공 시 data: targets, issued, skipped, failed, truncated(최대 인원 초과로 잘림)
     */
    public function issue(int $domainId, int $couponGroupId, array $criteria): Result
    {
        $targets = $this->findTargets($domainId, $criteria);
        if ($targets === []) {
            return Result::failure('조건에 맞는 회원이 없습니다.');
        }

        $truncated = count($targets) > self::MAX_TARGETS;
        $targets = array_slice($targets, 0, self::MAX_TARGETS);

        $issued = 0;
        $skipped = 0;
        $failed = 0;
        $reasons = [];
        foreach ($targets as $memberId) {
            $result = $this->couponService->issueByAdmin($couponGroupId, $memberId, $domainId);
            if ($result->isSuccess()) {
                $issued++;
                continue;
            }

            $message = $result->getMessage();
            if (str_contains($message, '한도를 초과')) {
                // 회원별 한도 — 이미 받은 회원
                $skipped++;
                continue;
            }
            if (!str_contains($message, '발행에 실패')) {
                // 정책 단위 거절(없음·중지·발행 기간 밖·수량 소진) — 남은 회원도 같으므로 멈춘다
                if ($issued === 0) {
                    return Result::failure($message);
                }
                $reasons[$message] = ($reasons[$message] ?? 0) + 1;
                $failed += count($targets) - $issued - $skipped - $failed;
                break;
            }
            $failed++;
            $reasons[$message] = ($reasons[$message] ?? 0) + 1;
        }

        $summary = "{$issued}명에게 발행했습니다."
            . ($skipped > 0 ? " 이미 받은 {$skipped}명은 건너뛰었습니다." : '')
            . ($failed > 0 ? " {$failed}명은 발행하지 못했습니다(" . implode(', ', array_keys($reasons)) . ').' : '')
            . ($truncated ? ' 한 번에 최대 ' . number_format(self::MAX_TARGETS) . '명까지 지급합니다 — 남은 회원은 다시 실행하세요.' : '');

        return Result::success($summary, [
            'targets' => count($targets),
            'issued' => $issued,
            'skipped' => $skipped,
            'failed' => $failed,
            'truncated' => $truncated,
        ]);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
