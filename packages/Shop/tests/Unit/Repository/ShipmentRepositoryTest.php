<?php

namespace Tests\Shop\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Repository\ShipmentRepository;
use Tests\Shop\TestCase;

final class ShipmentRepositoryTest extends TestCase
{
    public function testShippedOrderLookupIgnoresClaimShipments(): void
    {
        // 목록의 '운송장 등록됨' 표시는 실제 출고 기준이다.
        // 회수 운송장이 붙었다고 배송이 나간 것은 아니다.
        $queries = [];
        $repository = new ShipmentRepository($this->recordingDatabase($queries));

        $repository->getOrderNosWithShipments(['ORD1']);

        $this->assertStringContainsString('claim_id IS NULL', $queries[0][0]);
    }

    public function testOrderShipmentsIncludeClaimsOnlyWhenAsked(): void
    {
        // 기본은 실제 출고 운송장만. 주문 상세는 회수·재출고까지 한자리에서
        // 보여야 하므로 명시적으로 요청한다.
        $queries = [];
        $repository = new ShipmentRepository($this->recordingDatabase($queries));

        $repository->getByOrderNo('ORD1');
        $repository->getByOrderNo('ORD1', true);

        $this->assertStringContainsString('s.claim_id IS NULL', $queries[0][0]);
        $this->assertStringNotContainsString('claim_id IS NULL', $queries[1][0]);
    }

    /** 실행된 SQL 을 그대로 모아두는 Database 대역 */
    private function recordingDatabase(array &$queries): Database
    {
        $database = $this->createMock(Database::class);
        $database->method('select')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$queries): array {
                $queries[] = [$sql, $params];
                return [];
            }
        );
        return $database;
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
