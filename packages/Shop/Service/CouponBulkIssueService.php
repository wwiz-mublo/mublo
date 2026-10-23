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
     * @param array{level_values?:array, joined_from?:string, joined_to?:string, purchase?:string, min_purchase?:int|string, after_member_id?:int} $criteria
     *   purchase: ''(상관없음) | 'yes'(구매 이력 있음) | 'no'(없음). min_purchase 는 누적 구매액 하한(원, 'yes' 와 함께).
     * @param int|null $maxTargets 발행용 조회 상한; 미리보기는 null로 전체 조회
     * @return list<int>
     */
    public function findTargets(int $domainId, array $criteria, ?int $maxTargets = null): array
    {
        $levels = array_map('intval', (array) ($criteria['level_values'] ?? []));
        $from = $this->dateOrNull($criteria['joined_from'] ?? null);
        $to = $this->dateOrNull($criteria['joined_to'] ?? null);

        $after = max(0, (int) ($criteria['after_member_id'] ?? 0));
        $purchase = (string) ($criteria['purchase'] ?? '');
        $minPurchase = max(0, (int) ($criteria['min_purchase'] ?? 0));
        $targets = [];
        do {
            // 구매 조건에 맞는 회원이 뒤쪽에 있어도 빠지지 않도록 끝까지 순회한다.
            $ids = $this->members->findActiveMemberIds($domainId, $levels, $from, $to, 5000, $after);
            if ($ids === []) {
                break;
            }
            $after = max($ids);
            $totals = ($purchase === 'yes' || $purchase === 'no' || $minPurchase > 0)
                ? $this->orders->getPaidTotalsByMembers($domainId, $ids) : null;
            foreach ($ids as $id) {
                if ($totals !== null) {
                    $has = isset($totals[$id]);
                    if ($purchase === 'no' ? $has : (!$has || $totals[$id] < $minPurchase)) {
                        continue;
                    }
                }
                $targets[] = $id;
                if ($maxTargets !== null && count($targets) >= $maxTargets) {
                    return $targets;
                }
            }
        } while (count($ids) === 5000);

        return $targets;
    }

    /**
     * 조건에 맞는 회원에게 발행
     *
     * @return Result 성공 시 data: targets, issued, skipped, failed, truncated(다음 묶음 있음), next_after_member_id(다음 조회 커서)
     */
    public function issue(int $domainId, int $couponGroupId, array $criteria): Result
    {
        $targets = $this->findTargets($domainId, $criteria, self::MAX_TARGETS + 1);
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

        // 실패한 지급이 있으면 다음 묶음으로 넘어가지 않고 결과를 확인한다.
        $truncated = $truncated && $failed === 0;
        $summary = "{$issued}명에게 발행했습니다."
            . ($skipped > 0 ? " 이미 받은 {$skipped}명은 건너뛰었습니다." : '')
            . ($failed > 0 ? " {$failed}명은 발행하지 못했습니다(" . implode(', ', array_keys($reasons)) . ').' : '')
            . ($truncated ? ' 한 번에 최대 ' . number_format(self::MAX_TARGETS) . '명까지 지급합니다 — 남은 회원은 계속 지급 버튼으로 이어서 처리하세요.' : '');

        return Result::success($summary, [
            'targets' => count($targets),
            'issued' => $issued,
            'skipped' => $skipped,
            'failed' => $failed,
            'truncated' => $truncated,
            'next_after_member_id' => $truncated ? max($targets) : null,
        ]);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
