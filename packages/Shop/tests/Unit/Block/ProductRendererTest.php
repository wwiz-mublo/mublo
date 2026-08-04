<?php

namespace Tests\Shop\Unit\Block;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Block\ProductRenderer;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\ShopConfigService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductRendererTest extends TestCase
{
    public function testResolveItemsUsesDomainScopePreservesRequestedOrderAndLoadsOnlyVerifiedImages(): void
    {
        $repository = $this->createMock(ProductRepository::class);
        $config = $this->createMock(ShopConfigService::class);
        $repository->expects($this->once())
            ->method('findByIdsForDomain')
            ->with(10, [2, 999, 1])
            ->willReturn([$this->product(1), $this->product(2)]);
        $repository->expects($this->once())
            ->method('getMainImages')
            ->with([2, 1])
            ->willReturn([]);
        $config->method('getConfig')->with(10)->willReturn(Result::success('', ['config' => []]));

        $renderer = new ProductRenderer($repository, $config);
        $method = new ReflectionMethod($renderer, 'resolveItems');
        $items = $method->invoke($renderer, [
            ['id' => 2, 'label' => 'stale'],
            ['id' => 999, 'label' => 'foreign'],
            ['id' => 1, 'label' => 'stale'],
        ], 10);

        $this->assertSame([2, 1], array_column($items, 'goods_id'));
    }

    private function product(int $goodsId): Product
    {
        return Product::fromArray([
            'goods_id' => $goodsId,
            'domain_id' => 10,
            'goods_name' => 'Product ' . $goodsId,
            'display_price' => 1000,
            'origin_price' => 1000,
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }
}
