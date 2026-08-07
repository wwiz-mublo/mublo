<?php
declare(strict_types=1);

namespace Mublo\Service\Balance;

use Mublo\Contract\Balance\BalanceRankingEntry;
use Mublo\Contract\Balance\BalanceRankingFilter;
use Mublo\Contract\Balance\BalanceRankingMetric;
use Mublo\Contract\Balance\BalanceRankingPage;
use Mublo\Contract\Balance\BalanceRankingQueryInterface;
use Mublo\Repository\Balance\BalanceRankingRepository;

final class BalanceRankingQueryService implements BalanceRankingQueryInterface
{
    public function __construct(private BalanceRankingRepository $rankings)
    {
    }

    public function paginate(
        int $domainId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $page = 1,
        int $perPage = 30,
        ?BalanceRankingFilter $filter = null,
    ): BalanceRankingPage {
        [$from, $to] = $this->validate($domainId, $metric, $from, $to);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $filter ??= new BalanceRankingFilter();

        $result = $this->rankings->paginate(
            $domainId,
            $metric,
            $from,
            $to,
            $page,
            $perPage,
            $filter
        );

        return new BalanceRankingPage(
            items: array_map($this->toEntry(...), $result['items']),
            page: $page,
            perPage: $perPage,
            total: $result['total'],
            metric: $metric,
            from: $from,
            to: $to,
        );
    }

    public function findMemberRank(
        int $domainId,
        int $memberId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?BalanceRankingFilter $filter = null,
    ): ?BalanceRankingEntry {
        if ($memberId < 1) {
            throw new \InvalidArgumentException('회원 ID가 올바르지 않습니다.');
        }

        [$from, $to] = $this->validate($domainId, $metric, $from, $to);
        if ($filter === null) {
            $filter = new BalanceRankingFilter();
        }
        $filter = $filter->withoutKeyword();
        $row = $this->rankings->findMemberRank(
            $domainId,
            $memberId,
            $metric,
            $from,
            $to,
            $filter
        );

        return $row === null ? null : $this->toEntry($row);
    }

    public function findMemberRanks(
        int $domainId,
        array $memberIds,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?BalanceRankingFilter $filter = null,
    ): array {
        $memberIds = array_values(array_unique(array_filter(
            array_map('intval', $memberIds),
            static fn(int $memberId): bool => $memberId > 0
        )));
        if (count($memberIds) > 500) {
            throw new \InvalidArgumentException('한 번에 조회할 수 있는 회원은 최대 500명입니다.');
        }
        if ($memberIds === []) {
            return [];
        }

        [$from, $to] = $this->validate($domainId, $metric, $from, $to);
        if ($filter === null) {
            $filter = new BalanceRankingFilter();
        }
        $filter = $filter->withoutKeyword();

        return array_map(
            $this->toEntry(...),
            $this->rankings->findMemberRanks(
                $domainId,
                $memberIds,
                $metric,
                $from,
                $to,
                $filter
            )
        );
    }

    /** @return array{0:?\DateTimeImmutable,1:?\DateTimeImmutable} */
    private function validate(
        int $domainId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): array {
        if ($domainId < 1) {
            throw new \InvalidArgumentException('도메인 ID가 올바르지 않습니다.');
        }

        if ($metric !== BalanceRankingMetric::BALANCE && ($from === null || $to === null)) {
            throw new \InvalidArgumentException('기간 랭킹에는 시작과 종료 시각이 필요합니다.');
        }

        if ($from !== null && $to !== null && $from >= $to) {
            throw new \InvalidArgumentException('랭킹 종료 시각은 시작 시각보다 뒤여야 합니다.');
        }

        if ($metric === BalanceRankingMetric::BALANCE) {
            $from = null;
        }

        return [$from, $to];
    }

    /** @param array{member_id:mixed,score:mixed,rank_position:mixed} $row */
    private function toEntry(array $row): BalanceRankingEntry
    {
        return new BalanceRankingEntry(
            memberId: (int) $row['member_id'],
            score: (int) $row['score'],
            rank: (int) $row['rank_position'],
        );
    }
}
