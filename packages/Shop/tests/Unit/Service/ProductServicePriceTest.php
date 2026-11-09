<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Event\ProductChangedEvent;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductServicePriceTest extends TestCase
{
    private ProductRepository $products;
    private ProductService $service;
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->products = $this->createMock(ProductRepository::class);
        $events = new EventDispatcher(rethrowListenerFailures: true);
        $events->addListener(ProductChangedEvent::class, function (ProductChangedEvent $event): void {
            $this->dispatched[] = $event;
        });
        $this->service = new ProductService(
            $this->products,
            $this->createMock(ProductOptionRepository::class),
            $this->createMock(CategoryRepository::class),
            $this->createMock(PriceCalculator::class),
            $events
        );
    }

    public function testUpdatesBothPricesInCurrentDomain(): void
    {
        $this->products->method('findInDomain')->with(10, 7)->willReturn($this->product());
        $this->products->expects($this->once())->method('updateInDomain')
            ->with(10, 7, ['display_price' => 9000, 'origin_price' => 12000])->willReturn(1);

        $result = $this->service->changePrice(7, '9,000', 12000, 10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['goods_id' => 7, 'display_price' => 9000, 'origin_price' => 12000], $result->getData());
        $this->assertCount(1, $this->dispatched);
        $this->assertSame([7], $this->dispatched[0]->getGoodsIds());
        $this->assertTrue($this->dispatched[0]->affectsAutoBlocks());
    }

    public function testOmittedOriginPriceKeepsStoredValue(): void
    {
        $this->products->method('findInDomain')->willReturn($this->product(origin: 15000));
        $this->products->expects($this->once())->method('updateInDomain')->with(10, 7, ['display_price' => 0]);

        $result = $this->service->changePrice(7, '0', null, 10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(15000, $result->get('origin_price'));
    }

    #[DataProvider('invalidPrices')]
    public function testRejectsInvalidPricesBeforeAccessingStorage(mixed $display, mixed $origin): void
    {
        $this->products->expects($this->never())->method('findInDomain');
        $this->products->expects($this->never())->method('updateInDomain');
        $this->assertTrue($this->service->changePrice(7, $display, $origin, 10)->isFailure());
        $this->assertSame([], $this->dispatched);
    }

    public static function invalidPrices(): array
    {
        return [
            [null, 0], ['', 0], ['-1', 0], [-1, 0], ['1.5', 0], [1.5, 0], ['abc', 0], [true, 0], [[1000], 0],
            ['99999999999', 0], [2147483648, 0], [1000, '-5'], [1000, 'x'], [1000, ''],
        ];
    }

    public function testMissingOrForeignProductIsRejected(): void
    {
        $this->products->method('findInDomain')->willReturn(null);
        $this->products->expects($this->never())->method('updateInDomain');
        $this->assertTrue($this->service->changePrice(7, 1000, 0, 10)->isFailure());
        $this->assertSame([], $this->dispatched);
    }

    public function testStorageFailureReturnsErrorWithoutEvent(): void
    {
        $this->products->method('findInDomain')->willReturn($this->product());
        $this->products->method('updateInDomain')->willThrowException(new \RuntimeException('DB failure'));
        $this->assertTrue($this->service->changePrice(7, 1000, 0, 10)->isFailure());
        $this->assertSame([], $this->dispatched);
    }

    private function product(int $origin = 0): Product
    {
        return Product::fromArray(['goods_id' => 7, 'domain_id' => 10, 'goods_name' => '테스트', 'origin_price' => $origin, 'display_price' => 10000]);
    }
}
