<?php

namespace Tests\Banner\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Plugin\Banner\Repository\BannerRepository;
use PHPUnit\Framework\TestCase;

class BannerRepositoryTest extends TestCase
{
    public function testFindByIdsRequiresDomainActiveAndDateWindow(): void
    {
        $whereCalls = [];
        $rawCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->method('whereRaw')->willReturnCallback(
            function (string $sql, array $bindings = []) use (&$rawCalls, $query): QueryBuilder {
                $rawCalls[] = [$sql, $bindings];
                return $query;
            }
        );
        $query->method('orderByRaw')->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('banners')->willReturn($query);

        (new BannerRepository($db))->findByIds(10, [2, 1]);

        $this->assertContains(['domain_id', '=', 10], $whereCalls);
        $this->assertContains(['is_active', '=', 1], $whereCalls);
        $this->assertTrue((bool) array_filter($rawCalls, fn (array $call): bool => str_contains($call[0], 'start_date')));
        $this->assertTrue((bool) array_filter($rawCalls, fn (array $call): bool => str_contains($call[0], 'end_date')));
    }

    public function testFindExtrasMapRequiresDomain(): void
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('select')->willReturnSelf();
        $query->method('whereRaw')->willReturnSelf();
        $query->expects($this->once())->method('where')->with('domain_id', '=', 10)->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('banners')->willReturn($query);

        $this->assertSame([], (new BannerRepository($db))->findExtrasMap(10, [1]));
    }
}
