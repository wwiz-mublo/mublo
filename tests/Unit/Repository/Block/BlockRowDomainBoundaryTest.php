<?php

namespace Tests\Unit\Repository\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Repository\Block\BlockRowRepository;
use PHPUnit\Framework\TestCase;

class BlockRowDomainBoundaryTest extends TestCase
{
    public function testCachedPositionRowsAreReloadedWithinExpectedDomain(): void
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('whereIn')->with('row_id', [2, 1])->willReturnSelf();
        $query->expects($this->once())->method('where')->with('domain_id', '=', 10)->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('block_rows')->willReturn($query);

        $this->assertSame([], (new BlockRowRepository($db))->findByIdsForDomain(10, [2, 1]));
    }

    public function testCachedPageRowsRequirePageAndOwningDomain(): void
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('whereIn')->with('row_id', [2, 1])->willReturnSelf();
        $query->expects($this->once())->method('where')->with('page_id', '=', 7)->willReturnSelf();
        $query->expects($this->once())
            ->method('whereRaw')
            ->with(
                'domain_id = (SELECT p.domain_id FROM block_pages p WHERE p.page_id = ?)',
                [7]
            )
            ->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('block_rows')->willReturn($query);

        $this->assertSame([], (new BlockRowRepository($db))->findByIdsForPage(7, [2, 1]));
    }
}
