<?php

namespace Tests\Shop\Integration;

use Mublo\Packages\Shop\Repository\ProductRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 재고 차감과 판매수 집계를 실 DB 로 검증한다.
 *
 * adjustStock() 은 오버셀 방지를 WHERE 절에 두고, 적용 여부를 영향 행수로만
 * 알린다. 조건이 어긋나면 재고가 음수로 내려가거나(과판매) 정상 주문이 조용히
 * 실패한다. 둘 다 SQL 이 결정한다.
 *
 * getSoldCounts() 의 제외 조건도 마찬가지다 — 취소·반품 "완료"만 빼야 하고,
 * 요청 단계나 교환은 판매로 남아야 한다.
 */
class ProductRepositoryTest extends DatabaseTestCase
{
    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('shop_products', '
            goods_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            goods_name VARCHAR(100) NOT NULL DEFAULT "",
            stock_quantity INT NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ');

        $this->createTable('shop_order_details', '
            detail_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            goods_id BIGINT UNSIGNED NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            is_paid TINYINT(1) NOT NULL DEFAULT 0,
            return_type VARCHAR(10) NOT NULL DEFAULT "NONE",
            return_status VARCHAR(10) NOT NULL DEFAULT "NONE"
        ');

        $this->repository = new ProductRepository($this->db);
    }

    private function stockOf(int $goodsId): ?int
    {
        $rows = $this->fetchAll('SELECT stock_quantity FROM shop_products WHERE goods_id = ?', [$goodsId]);

        return $rows[0]['stock_quantity'] === null ? null : (int) $rows[0]['stock_quantity'];
    }

    public function testDecrementWithinStockApplies(): void
    {
        $this->seed('shop_products', [['goods_id' => 1, 'stock_quantity' => 10]]);

        $this->assertSame(1, $this->repository->adjustStock(1, -3));
        $this->assertSame(7, $this->stockOf(1));
    }

    public function testDecrementDownToExactlyZeroApplies(): void
    {
        $this->seed('shop_products', [['goods_id' => 1, 'stock_quantity' => 3]]);

        $this->assertSame(1, $this->repository->adjustStock(1, -3));
        $this->assertSame(0, $this->stockOf(1));
    }

    public function testDecrementBeyondStockIsRejectedAndLeavesStockUntouched(): void
    {
        $this->seed('shop_products', [['goods_id' => 1, 'stock_quantity' => 2]]);

        // 영향 행수 0 이 곧 "차감하지 못했다" 는 신호다. 호출부가 이것으로 오버셀을 막는다.
        $this->assertSame(0, $this->repository->adjustStock(1, -3));
        $this->assertSame(2, $this->stockOf(1));
    }

    public function testUnmanagedStockIsNeverAdjusted(): void
    {
        $this->seed('shop_products', [['goods_id' => 1, 'stock_quantity' => null]]);

        $this->assertSame(0, $this->repository->adjustStock(1, -1));
        $this->assertSame(0, $this->repository->adjustStock(1, 5));
        $this->assertNull($this->stockOf(1));
    }

    public function testIncrementApplies(): void
    {
        $this->seed('shop_products', [['goods_id' => 1, 'stock_quantity' => 4]]);

        $this->assertSame(1, $this->repository->adjustStock(1, 6));
        $this->assertSame(10, $this->stockOf(1));
    }

    public function testSoldCountsSumOnlyPaidRowsThatWereNotCancelledOrReturned(): void
    {
        $this->seed('shop_order_details', [
            ['goods_id' => 1, 'quantity' => 2, 'is_paid' => 1],
            ['goods_id' => 1, 'quantity' => 3, 'is_paid' => 1],
            // 미결제는 판매가 아니다.
            ['goods_id' => 1, 'quantity' => 9, 'is_paid' => 0],
            // 취소·반품이 "완료" 된 것만 빠진다.
            ['goods_id' => 1, 'quantity' => 4, 'is_paid' => 1, 'return_type' => 'CANCEL', 'return_status' => 'COMPLETED'],
            // 요청 단계는 아직 판매다.
            ['goods_id' => 1, 'quantity' => 1, 'is_paid' => 1, 'return_type' => 'RETURN', 'return_status' => 'REQUESTED'],
            // 교환은 판매가 유지된다.
            ['goods_id' => 1, 'quantity' => 5, 'is_paid' => 1, 'return_type' => 'EXCHANGE', 'return_status' => 'COMPLETED'],
            ['goods_id' => 2, 'quantity' => 7, 'is_paid' => 1],
        ]);

        $this->assertSame(
            [1 => 2 + 3 + 1 + 5, 2 => 7],
            $this->repository->getSoldCounts([1, 2])
        );
    }

    public function testSoldCountsOmitsProductsWithoutPaidRows(): void
    {
        $this->seed('shop_order_details', [
            ['goods_id' => 1, 'quantity' => 2, 'is_paid' => 0],
        ]);

        $this->assertSame([], $this->repository->getSoldCounts([1, 2]));
    }

    public function testSoldCountsWithEmptyInputDoesNotQuery(): void
    {
        $this->assertSame([], $this->repository->getSoldCounts([]));
    }
}
