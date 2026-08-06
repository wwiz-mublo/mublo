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
}
