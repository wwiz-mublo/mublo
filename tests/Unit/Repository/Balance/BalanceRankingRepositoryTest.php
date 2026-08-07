<?php
declare(strict_types=1);

namespace Tests\Unit\Repository\Balance;

use Mublo\Contract\Balance\BalanceRankingFilter;
use Mublo\Contract\Balance\BalanceRankingMetric;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Balance\BalanceRankingRepository;
use PHPUnit\Framework\TestCase;

final class BalanceRankingRepositoryTest extends TestCase
{
    public function testEarnedRankingKeepsDomainPeriodAndCompetitionRankInSql(): void
    {
        $db = $this->createMock(Database::class);
        $from = new \DateTimeImmutable('2026-08-01 00:00:00');
        $to = new \DateTimeImmutable('2026-08-02 00:00:00');

        $db->expects(self::once())
            ->method('selectOne')
            ->with(
                self::callback(static fn(string $sql): bool =>
                    str_contains($sql, 'COUNT(*) AS total')
                    && str_contains($sql, 'bl.amount > 0')
                    && str_contains($sql, 'listed.nickname LIKE ?')
                    && !str_contains($sql, 'user_id')
                ),
                [7, 'active', '2026-08-01 00:00:00', '2026-08-02 00:00:00', '%kim%']
            )
            ->willReturn(['total' => 2]);

        $db->expects(self::once())
            ->method('select')
            ->with(
                self::callback(static fn(string $sql): bool =>
                    str_contains($sql, 'higher.score > listed.score')
                    && str_contains($sql, 'LIMIT 30 OFFSET 0')
                    && !str_contains($sql, 'RANK()')
                ),
                [
                    7, 'active', '2026-08-01 00:00:00', '2026-08-02 00:00:00',
                    7, 'active', '2026-08-01 00:00:00', '2026-08-02 00:00:00',
                    '%kim%',
                ]
            )
            ->willReturn([
                ['member_id' => 10, 'score' => 500, 'rank_position' => 1],
                ['member_id' => 11, 'score' => 500, 'rank_position' => 1],
            ]);

        $repository = new BalanceRankingRepository($db);
        $result = $repository->paginate(
            7,
            BalanceRankingMetric::EARNED,
            $from,
            $to,
            1,
            30,
            new BalanceRankingFilter(keyword: 'kim')
        );

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['items']);
    }

    public function testCurrentBalanceUsesSnapshotWithoutLedgerPeriod(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects(self::once())
            ->method('selectOne')
            ->with(
                self::callback(static fn(string $sql): bool =>
                    str_contains($sql, 'm.point_balance AS score')
                    && !str_contains($sql, 'balance_logs')
                ),
                [3, 'active']
            )
            ->willReturn(['total' => 0]);
        $db->expects(self::never())->method('select');

        $repository = new BalanceRankingRepository($db);
        $result = $repository->paginate(
            3,
            BalanceRankingMetric::BALANCE,
            null,
            null,
            1,
            10,
            new BalanceRankingFilter()
        );

        self::assertSame(['items' => [], 'total' => 0], $result);
    }
}
