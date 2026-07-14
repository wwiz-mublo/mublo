<?php

namespace Tests\Board\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use PHPUnit\Framework\TestCase;

class BoardConfigDomainPolicyTest extends TestCase
{
    public function testFindAccessibleByIdUsesLocalOrGlobalPackagePolicy(): void
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())->method('where')->with('board_id', '=', 7)->willReturnSelf();
        $query->expects($this->once())
            ->method('whereRaw')
            ->with('(domain_id = ? OR is_global = 1)', [10])
            ->willReturnSelf();
        $query->method('first')->willReturn([
            'board_id' => 7,
            'domain_id' => 20,
            'board_slug' => 'global-notice',
            'board_name' => 'Global Notice',
            'is_global' => 1,
            'is_active' => 1,
        ]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('board_configs')->willReturn($query);

        $board = (new BoardConfigRepository($db))->findAccessibleById(10, 7);

        $this->assertInstanceOf(BoardConfig::class, $board);
        $this->assertTrue($board->isGlobal());
    }
}
