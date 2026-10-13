<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Event\ProductChangedEvent;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductServiceActiveTest extends TestCase
{
    private ProductRepository $products;
    private EventDispatcher $events;
    private ProductService $service;
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->products = $this->createMock(ProductRepository::class);
        $this->events = new EventDispatcher(rethrowListenerFailures: true);
        $this->events->addListener(ProductChangedEvent::class, function (ProductChangedEvent $event): void {
            $this->dispatched[] = $event;
        });
        $this->service = new ProductService(
            $this->products,
            $this->createMock(ProductOptionRepository::class),
            $this->createMock(CategoryRepository::class),
            $this->createMock(PriceCalculator::class),
            $this->events
        );
    }

    #[DataProvider('states')]
    public function testChangesStateAndInvalidatesBothKindsOfDisplay(bool $active): void
    {
        $this->products->method('filterByDomain')->with([7], 10)->willReturn([7]);
        $this->products->expects($this->once())->method('updateActiveMultiple')->with(10, [7], $active)->willReturn(1);

        $result = $this->service->changeActiveMultiple([7, '7'], $active, 10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['changed' => 1, 'is_active' => $active], $result->getData());
        $this->assertCount(1, $this->dispatched);
        $event = $this->dispatched[0];
        $this->assertSame(10, $event->getDomainId());
        $this->assertSame([7], $event->getGoodsIds());
        $this->assertSame(ProductChangedEvent::UPDATED, $event->getChangeType());
        $this->assertTrue($event->affectsAutoBlocks());
    }

    public static function states(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIdsBeforeAccessingStorage(array $ids): void
    {
        $this->products->expects($this->never())->method('filterByDomain');
        $this->products->expects($this->never())->method('updateActiveMultiple');
        $this->assertTrue($this->service->changeActiveMultiple($ids, false, 10)->isFailure());
        $this->assertSame([], $this->dispatched);
    }

    public static function invalidIds(): array
    {
        return [[[]], [[0]], [[-1]], [['7oops']], [[true]], [[7.5]], [[[7]]], [[7, null]]];
    }

    public function testForeignOrMissingProductRejectsEntireRequest(): void
    {
        $this->products->method('filterByDomain')->with([7, 8], 10)->willReturn([7]);
        $this->products->expects($this->never())->method('updateActiveMultiple');
        $this->assertTrue($this->service->changeActiveMultiple([7, 8], false, 10)->isFailure());
        $this->assertSame([], $this->dispatched);
    }

    public function testSameStateDoesNotEmitEvent(): void
    {
        $this->products->method('filterByDomain')->willReturn([7]);
        $this->products->method('updateActiveMultiple')->willReturn(0);
        $result = $this->service->changeActiveMultiple([7], false, 10);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->get('changed'));
        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('storageFailures')]
    public function testStorageFailureReturnsErrorWithoutEvent(string $method): void
    {
        if ($method !== 'filterByDomain') {
            $this->products->method('filterByDomain')->willReturn([7]);
        }
        $this->products->method($method)->willThrowException(new \RuntimeException('DB failure'));
        $this->assertTrue($this->service->changeActiveMultiple([7], false, 10)->isFailure());
        $this->assertSame([], $this->dispatched);
    }

    public static function storageFailures(): array
    {
        return [['filterByDomain'], ['updateActiveMultiple']];
    }

    public function testListenerFailureDoesNotReportSavedStateAsFailure(): void
    {
        $this->products->method('filterByDomain')->willReturn([7]);
        $this->products->method('updateActiveMultiple')->willReturn(1);
        $this->events->addListener(ProductChangedEvent::class, static function (): void {
            throw new \RuntimeException('Cache failure');
        });
        $this->assertTrue($this->service->changeActiveMultiple([7], false, 10)->isSuccess());
    }
}
