<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\ClaimRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\ClaimStateMachine;
use Mublo\Packages\Shop\Service\ExchangeService;
use Mublo\Packages\Shop\Service\ExchangeStockService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Tests\Shop\TestCase;

/**
 * 교환 검수 — 회수품 재고 처리.
 *
 * 배경: 검수 결과(inspection_result)는 승인/거절과 무관하게 restockSource() 로
 * 넘어갔다. 그래서 '검수 거절 + 정상 재판매' 조합이면 회수품 수량이 판매 재고에
 * 더해지는데, 거절 건의 회수품은 REJECTED → RETURNING 경로로 고객에게 반송된다.
 * 없는 물건이 재고로 잡혀 팔리는 상태가 된다.
 *
 * 관리자 화면의 검수 결과 select 는 승인·거절 버튼이 공유하고 첫 항목이
 * '정상 재판매' 라, 셀렉트를 건드리지 않고 거절만 눌러도 이 조합이 만들어졌다.
 */
final class ExchangeInspectionTest extends TestCase
{
    private const CLAIM = [
        'return_id' => 77,
        'domain_id' => 1,
        'order_no' => 'ORD1',
        'order_detail_id' => 3,
        'return_type' => 'EXCHANGE',
        'return_status' => 'INSPECTING',
        'exchange_item_id' => 9,
        'exchange_quantity' => 1,
        'fee_status' => 'WAIVED',
        'source_goods_id' => 10,
        'source_option_mode' => 'NONE',
        'source_option_id' => 0,
        'source_option_code' => '',
        'source_stock_deducted' => 1,
        'target_goods_id' => 10,
        'target_option_mode' => 'NONE',
        'target_option_id' => 0,
        'target_option_code' => '',
        'stock_reservation_status' => 'RESERVED',
        'source_restocked_at' => null,
    ];

    public function testRejectedInspectionCannotClaimTheItemIsSaleable(): void
    {
        $writes = [];
        $service = $this->makeService($this->claimsMock([], $writes), $this->productsMock());

        $result = $service->inspect(1, 77, false, 'SALEABLE', 5);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('정상 재판매', $result->getMessage());
        $this->assertSame([], $writes, '거절이 막혔으면 재고 상태를 건드리지 않아야 합니다.');
    }

    public function testRejectedInspectionDoesNotRestockTheReturnedItem(): void
    {
        $writes = [];
        $service = $this->makeService($this->claimsMock([], $writes), $this->productsMock());

        $result = $service->inspect(1, 77, false, 'DEFECTIVE', 5);

        $this->assertTrue($result->isSuccess());
        // 회수품은 고객에게 반송된다 — 판매 재고로 되돌리면 없는 물건을 파는 셈이다
        $this->assertNull($this->writeFor($writes, 'source_restocked_at'));
        // 대신 예약해 둔 교환품은 풀어줘야 한다
        $this->assertSame('RELEASED', $this->writeFor($writes, 'stock_reservation_status'));
    }

    public function testApprovedSaleableInspectionRestocksTheReturnedItem(): void
    {
        $writes = [];
        $service = $this->makeService($this->claimsMock([], $writes), $this->productsMock());

        $result = $service->inspect(1, 77, true, 'SALEABLE', 5);

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($this->writeFor($writes, 'source_restocked_at'));
        // 승인 건은 교환품 예약을 유지한다 (재출고 때 SHIPPED 로 넘어간다)
        $this->assertNull($this->writeFor($writes, 'stock_reservation_status'));
    }

    public function testApprovedDefectiveInspectionDoesNotRestock(): void
    {
        $writes = [];
        $service = $this->makeService($this->claimsMock([], $writes), $this->productsMock());

        $result = $service->inspect(1, 77, true, 'DEFECTIVE', 5);

        $this->assertTrue($result->isSuccess());
        // 불량품은 승인 건이어도 판매 재고로 보내지 않는다
        $this->assertNull($this->writeFor($writes, 'source_restocked_at'));
    }

    public function testUnpaidExchangeFeeBlocksApprovalBeforeTouchingStock(): void
    {
        $writes = [];
        $products = $this->productsMock();
        // 배송비 검증이 재고 조정보다 먼저다 — 롤백될 UPDATE 를 쏘지 않는다
        $products->expects($this->never())->method('adjustStock');

        $service = $this->makeService($this->claimsMock(['fee_status' => 'UNPAID'], $writes), $products);

        $result = $service->inspect(1, 77, true, 'SALEABLE', 5);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('교환 배송비', $result->getMessage());
        $this->assertSame([], $writes);
    }

    /**
     * updateExchangeItem 호출들에서 해당 키의 값을 찾는다. 없으면 null.
     *
     * @param array<int,array<string,mixed>> $writes
     */
    private function writeFor(array $writes, string $key): mixed
    {
        foreach ($writes as $write) {
            if (array_key_exists($key, $write)) {
                return $write[$key];
            }
        }
        return null;
    }

    private function productsMock(): ProductRepository
    {
        $products = $this->createMock(ProductRepository::class);
        // Product 는 final 이라 목을 못 만든다 — 최소 필드로 실제 엔티티를 쓴다
        $products->method('findInDomain')->willReturn(
            Product::fromArray(['goods_id' => 10, 'domain_id' => 1])
        );
        $products->method('getStock')->willReturn(5);
        $products->method('adjustStock')->willReturn(1);
        return $products;
    }

    /**
     * @param array<string,mixed> $overrides
     * @param array<int,array<string,mixed>> $writes updateExchangeItem 페이로드를 여기에 모은다
     */
    private function claimsMock(array $overrides, array &$writes): ClaimRepository
    {
        $claim = array_merge(self::CLAIM, $overrides);

        $claims = $this->createMock(ClaimRepository::class);
        $claims->method('transaction')->willReturnCallback(
            static fn(callable $callback): mixed => $callback()
        );
        $claims->method('findForUpdate')->willReturn($claim);
        $claims->method('findInDomain')->willReturn($claim);
        $claims->method('compareAndSetStatus')->willReturn(true);
        $claims->method('updateExchangeItem')->willReturnCallback(
            static function (int $returnId, array $data) use (&$writes): bool {
                $writes[] = $data;
                return true;
            }
        );
        $claims->method('addLog')->willReturn(1);
        $claims->method('getActiveByDetailId')->willReturn([]);
        $claims->method('hasCompletedExchange')->willReturn(false);

        return $claims;
    }

    private function makeService(ClaimRepository $claims, ProductRepository $products): ExchangeService
    {
        $options = $this->createMock(ProductOptionRepository::class);

        return new ExchangeService(
            $claims,
            $this->createMock(OrderRepository::class),
            $options,
            $this->createMock(OrderStateResolver::class),
            new ClaimStateMachine(),
            new ExchangeStockService($claims, $products, $options),
            $this->createMock(ShipmentService::class),
            $this->createMock(SensitiveValueCodecInterface::class),
            new EventDispatcher(),
        );
    }
}
