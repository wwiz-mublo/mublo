<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\TestCase;

class ProductServiceDomainTest extends TestCase
{
    public function testGetDetailDoesNotLoadRelationsWhenProductIsOutsideCurrentDomain(): void
    {
        [$service, $products, $options] = $this->service();

        $products->expects($this->once())
            ->method('findInDomain')
            ->with(10, 7)
            ->willReturn(null);
        $products->expects($this->never())->method('getImages');
        $products->expects($this->never())->method('getDetails');
        $options->expects($this->never())->method('getByProduct');
        $options->expects($this->never())->method('getCombos');

        $result = $service->getDetail(7, 10);

        $this->assertTrue($result->isFailure());
        $this->assertSame('상품을 찾을 수 없습니다.', $result->getMessage());
    }

    public function testUpdateRejectsProductOutsideCurrentDomainBeforeTransaction(): void
    {
        [$service, $products] = $this->service();

        $products->expects($this->once())
            ->method('findInDomain')
            ->with(10, 7)
            ->willReturn(null);
        $products->expects($this->never())->method('getDb');
        $products->expects($this->never())->method('updateInDomain');

        $result = $service->update(7, ['goods_name' => 'Changed'], 10);

        $this->assertTrue($result->isFailure());
        $this->assertSame('상품을 찾을 수 없습니다.', $result->getMessage());
    }

    public function testDeleteRejectsProductOutsideCurrentDomainBeforeTransaction(): void
    {
        [$service, $products] = $this->service();

        $products->expects($this->once())
            ->method('findInDomain')
            ->with(10, 7)
            ->willReturn(null);
        $products->expects($this->never())->method('getDb');
        $products->expects($this->never())->method('deleteInDomain');

        $result = $service->delete(7, 10);

        $this->assertTrue($result->isFailure());
        $this->assertSame('상품을 찾을 수 없습니다.', $result->getMessage());
    }

    /**
     * @return array{ProductService, ProductRepository, ProductOptionRepository}
     */
    private function service(): array
    {
        $products = $this->createMock(ProductRepository::class);
        $options = $this->createMock(ProductOptionRepository::class);
        $categories = $this->createMock(CategoryRepository::class);
        $prices = $this->createMock(PriceCalculator::class);

        return [
            new ProductService($products, $options, $categories, $prices),
            $products,
            $options,
        ];
    }
}
