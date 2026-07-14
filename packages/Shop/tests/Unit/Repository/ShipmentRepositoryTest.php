<?php

namespace Tests\Shop\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Repository\ShipmentRepository;
use Tests\Shop\TestCase;

final class ShipmentRepositoryTest extends TestCase
{
    public function testOrderShipmentQueriesExcludeClaimShipments(): void
    {
        $queries = [];
        $database = $this->createMock(Database::class);
        $database->method('select')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$queries): array {
                $queries[] = [$sql, $params];
                return [];
            }
        );
        $repository = new ShipmentRepository($database);

        $repository->getOrderNosWithShipments(['ORD1']);
        $repository->getByOrderNo('ORD1');

        $this->assertCount(2, $queries);
        $this->assertStringContainsString('claim_id IS NULL', $queries[0][0]);
        $this->assertStringContainsString('s.claim_id IS NULL', $queries[1][0]);
    }

    public function testClaimShipmentQueryIsExplicitlyScopedToClaim(): void
    {
        $capturedSql = '';
        $capturedParams = [];
        $database = $this->createMock(Database::class);
        $database->method('select')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            }
        );

        (new ShipmentRepository($database))->getByClaimId(33);

        $this->assertStringContainsString('WHERE s.claim_id = ?', $capturedSql);
        $this->assertSame([33], $capturedParams);
    }
}
