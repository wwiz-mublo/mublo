<?php

namespace Mublo\Packages\Shop\Tests\Unit\EventSubscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Packages\Shop\Event\ProductChangedEvent;
use Mublo\Packages\Shop\EventSubscriber\BlockCacheInvalidateSubscriber;
use PHPUnit\Framework\TestCase;

final class BlockCacheInvalidateSubscriberTest extends TestCase
{
    public function testInvalidatesManualAndAutomaticProductBlocksThroughStableContract(): void
    {
        $invalidator = $this->createMock(BlockContentCacheInvalidatorInterface::class);
        $invalidator->expects($this->exactly(2))
            ->method('invalidateByContentItem')
            ->willReturnCallback(function (int $domainId, string $contentType, int $itemId): void {
                $this->assertSame(19, $domainId);
                $this->assertSame('product', $contentType);
                $this->assertContains($itemId, [7, 8]);
            });
        $invalidator->expects($this->once())
            ->method('invalidateByContentType')
            ->with(19, 'product_auto');

        (new BlockCacheInvalidateSubscriber($invalidator))->onProductChanged(
            new ProductChangedEvent(19, [7, 8], ProductChangedEvent::CREATED)
        );
    }

    public function testSkipsAutomaticBlocksForProductUpdates(): void
    {
        $invalidator = $this->createMock(BlockContentCacheInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateByContentItem')
            ->with(19, 'product', 7);
        $invalidator->expects($this->never())->method('invalidateByContentType');

        (new BlockCacheInvalidateSubscriber($invalidator))->onProductChanged(
            new ProductChangedEvent(19, [7], ProductChangedEvent::UPDATED)
        );
    }
}
