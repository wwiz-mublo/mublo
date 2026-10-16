<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\Balance;

use Mublo\Contract\Balance\BalanceRankingFilter;
use PHPUnit\Framework\TestCase;

final class BalanceRankingFilterTest extends TestCase
{
    public function testNormalizesAndDeduplicatesValues(): void
    {
        $filter = new BalanceRankingFilter(
            statuses: ['ACTIVE', 'active'],
            excludedLevelTypes: ['staff', 'STAFF'],
            keyword: '  tester  ',
        );

        self::assertSame(['active'], $filter->statuses);
        self::assertSame(['STAFF'], $filter->excludedLevelTypes);
        self::assertSame('tester', $filter->keyword);
        self::assertNull($filter->withoutKeyword()->keyword);
    }

    public function testRejectsUnknownMemberStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceRankingFilter(statuses: ['unknown']);
    }

    public function testRejectsUnknownLevelType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceRankingFilter(excludedLevelTypes: ['OWNER']);
    }

    public function testLedgerConditionsAreDeduplicatedAndKeptWithoutKeyword(): void
    {
        $filter = new BalanceRankingFilter(
            keyword: 'kim',
            actions: ['ad_mission', 'ad_mission', 'referral_l1'],
            sources: ['RankCash', 'RankCash'],
        );

        self::assertSame(['ad_mission', 'referral_l1'], $filter->actions);
        self::assertSame(['RankCash'], $filter->sources);
        self::assertSame(['ad_mission', 'referral_l1'], $filter->withoutKeyword()->actions);
        self::assertSame(['RankCash'], $filter->withoutKeyword()->sources);
        self::assertSame([], (new BalanceRankingFilter())->actions);
        self::assertSame([], (new BalanceRankingFilter())->sources);
    }

    public function testRejectsMalformedAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceRankingFilter(actions: ["ad_mission' OR 1=1"]);
    }

    public function testRejectsMalformedSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceRankingFilter(sources: [str_repeat('a', 51)]);
    }
}
