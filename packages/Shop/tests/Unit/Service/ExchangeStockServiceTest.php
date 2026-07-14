<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\ClaimRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\ExchangeStockService;
use Tests\Shop\TestCase;

final class ExchangeStockServiceTest extends TestCase
{
    public function testReplacementStockIsReservedOnlyOnce(): void
    {
        $claims = $this->createMock(ClaimRepository::class);
        $products = $this->createMock(ProductRepository::class);
        $options = $this->createMock(ProductOptionRepository::class);
        $products->method('findInDomain')->with(1, 10)->willReturn($this->makeProduct(['goods_id' => 10]));
        $products->expects($this->once())->method('getStock')->with(10)->willReturn(5);
        $products->expects($this->once())->method('adjustStock')->with(10, -2)->willReturn(1);
        $claims->expects($this->once())->method('updateExchangeItem')->with(
            7,
            $this->callback(static fn(array $data): bool => $data['stock_reservation_status'] === 'RESERVED')
        )->willReturn(true);
        $service = new ExchangeStockService($claims, $products, $options);

        $claim = [
            'return_id' => 7,
            'domain_id' => 1,
            'stock_reservation_status' => 'NONE',
            'exchange_quantity' => 2,
            'target_option_mode' => 'NONE',
            'target_goods_id' => 10,
        ];
        $this->assertTrue($service->reserveReplacement($claim));

        // 재시도 시 저장된 예약 상태가 멱등 키가 되어 재고를 다시 차감하지 않는다.
        $claim['stock_reservation_status'] = 'RESERVED';
        $this->assertTrue($service->reserveReplacement($claim));
    }

    public function testUnmanagedStockDoesNotReceiveArtificialQuantity(): void
    {
        $claims = $this->createMock(ClaimRepository::class);
        $products = $this->createMock(ProductRepository::class);
        $options = $this->createMock(ProductOptionRepository::class);
        $products->method('findInDomain')->with(1, 11)->willReturn($this->makeProduct(['goods_id' => 11, 'stock_quantity' => null]));
        $products->method('getStock')->willReturn(null);
        $products->expects($this->never())->method('adjustStock');
        $claims->method('updateExchangeItem')->willReturn(true);
        $service = new ExchangeStockService($claims, $products, $options);

        $this->assertTrue($service->reserveReplacement([
            'return_id' => 8,
            'domain_id' => 1,
            'stock_reservation_status' => 'NONE',
            'exchange_quantity' => 1,
            'target_option_mode' => 'NONE',
            'target_goods_id' => 11,
        ]));
    }

    private function makeProduct(array $overrides = []): \Mublo\Packages\Shop\Entity\Product
    {
        return \Mublo\Packages\Shop\Entity\Product::fromArray($this->makeProductData($overrides));
    }
}
