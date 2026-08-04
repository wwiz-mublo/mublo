<?php

namespace Tests\Unit\Repository\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Repository\Block\BlockKitApplicationRepository;
use PHPUnit\Framework\TestCase;

class BlockKitApplicationRepositoryTest extends TestCase
{
    public function testClearSnapshotIsDomainScopedAndConditional(): void
    {
        $whereCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->exactly(2))->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->expects($this->once())
            ->method('whereNotNull')
            ->with('site_config_snapshot')
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('update')
            ->with(['site_config_snapshot' => null])
            ->willReturn(1);

        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('table')
            ->with('block_kit_applications')
            ->willReturn($query);

        $affected = (new BlockKitApplicationRepository($db))->claimSnapshotForRollback(9, 17);

        $this->assertSame(1, $affected);
        $this->assertSame([
            ['application_id', '=', 17],
            ['domain_id', '=', 9],
        ], $whereCalls);
    }
}
