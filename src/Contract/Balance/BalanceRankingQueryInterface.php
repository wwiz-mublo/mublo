<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * 확장이 코어 원장과 잔액 스냅샷으로 랭킹을 읽는 안정 계약.
 *
 * - domainId는 항상 필수다.
 * - earned/net은 from과 to가 모두 필요하고 [from, to) 구간을 쓴다.
 * - balance는 to가 null이면 현재 잔액, 있으면 그 시각 직전까지의 원장 합이다.
 * - 0 이하 점수는 랭킹에서 제외한다.
 * - 동점은 경쟁 순위(1, 2, 2, 4)다.
 */
interface BalanceRankingQueryInterface
{
    public function paginate(
        int $domainId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $page = 1,
        int $perPage = 30,
        ?BalanceRankingFilter $filter = null,
    ): BalanceRankingPage;

    public function findMemberRank(
        int $domainId,
        int $memberId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?BalanceRankingFilter $filter = null,
    ): ?BalanceRankingEntry;

    /**
     * 여러 회원의 전체 모집단 순위를 한 번에 조회한다.
     *
     * @param list<int> $memberIds
     * @return list<BalanceRankingEntry>
     */
    public function findMemberRanks(
        int $domainId,
        array $memberIds,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?BalanceRankingFilter $filter = null,
    ): array;
}
