<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Balance;

use Mublo\Contract\Balance\BalanceRankingFilter;
use Mublo\Contract\Balance\BalanceRankingMetric;
use Mublo\Repository\Balance\BalanceRankingRepository;
use Mublo\Service\Balance\BalanceRankingQueryService;
use PHPUnit\Framework\TestCase;

final class BalanceRankingQueryServiceTest extends TestCase
{
    public function testMapsRepositoryRowsAndClampsPagination(): void
    {
        $repo = $this->createMock(BalanceRankingRepository::class);
        $from = new \DateTimeImmutable('2026-08-01 00:00:00');
        $to = new \DateTimeImmutable('2026-08-08 00:00:00');

        $repo->expects(self::once())
            ->method('paginate')
            ->with(2, BalanceRankingMetric::NET, $from, $to, 1, 100, self::isInstanceOf(BalanceRankingFilter::class))
            ->willReturn([
                'items' => [
                    ['member_id' => '9', 'score' => '120', 'rank_position' => '1'],
                    ['member_id' => '10', 'score' => '100', 'rank_position' => '2'],
                ],
                'total' => 102,
            ]);

        $service = new BalanceRankingQueryService($repo);
        $page = $service->paginate(2, BalanceRankingMetric::NET, $from, $to, 0, 1000);

        self::assertSame(1, $page->page);
        self::assertSame(100, $page->perPage);
        self::assertSame(2, $page->totalPages());
        self::assertSame(9, $page->items[0]->memberId);
        self::assertSame(120, $page->items[0]->score);
    }

    public function testPeriodMetricRequiresBothBoundaries(): void
    {
        $service = new BalanceRankingQueryService($this->createMock(BalanceRankingRepository::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->paginate(1, BalanceRankingMetric::EARNED);
    }

    public function testFindMemberRankIgnoresResultKeyword(): void
    {
        $repo = $this->createMock(BalanceRankingRepository::class);
        $repo->expects(self::once())
            ->method('findMemberRank')
            ->with(
                4,
                22,
                BalanceRankingMetric::BALANCE,
                null,
                null,
                self::callback(static fn(BalanceRankingFilter $filter): bool => $filter->keyword === null)
            )
            ->willReturn(['member_id' => 22, 'score' => 300, 'rank_position' => 5]);

        $service = new BalanceRankingQueryService($repo);
        $entry = $service->findMemberRank(
            4,
            22,
            BalanceRankingMetric::BALANCE,
            filter: new BalanceRankingFilter(keyword: 'ignored')
        );

        self::assertSame(5, $entry?->rank);
    }

    public function testFindMemberRanksNormalizesIdsAndMapsEntries(): void
    {
        $repo = $this->createMock(BalanceRankingRepository::class);
        $repo->expects(self::once())
            ->method('findMemberRanks')
            ->with(
                5,
                [2, 3],
                BalanceRankingMetric::BALANCE,
                null,
                null,
                self::isInstanceOf(BalanceRankingFilter::class)
            )
            ->willReturn([
                ['member_id' => 2, 'score' => 50, 'rank_position' => 4],
                ['member_id' => 3, 'score' => 20, 'rank_position' => 8],
            ]);

        $service = new BalanceRankingQueryService($repo);
        $items = $service->findMemberRanks(
            5,
            [0, 2, 2, 3],
            BalanceRankingMetric::BALANCE
        );

        self::assertSame([2, 3], array_map(static fn($item) => $item->memberId, $items));
        self::assertSame([4, 8], array_map(static fn($item) => $item->rank, $items));
    }
}
