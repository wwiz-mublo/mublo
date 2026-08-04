<?php

namespace Tests\Manual\Unit\Block;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Plugin\Manual\Event\ManualContentChangedEvent;
use Mublo\Plugin\Manual\Subscriber\ManualBlockCacheSubscriber;
use PHPUnit\Framework\TestCase;

final class ManualBlockCacheSubscriberTest extends TestCase
{
    public function testInvalidatesEveryManualBlockTypeThroughStableContract(): void
    {
        $invalidator = $this->createMock(BlockContentCacheInvalidatorInterface::class);
        $types = [];
        $invalidator->expects($this->exactly(4))
            ->method('invalidateByContentType')
            ->willReturnCallback(static function (int $domainId, string $contentType) use (&$types): void {
                self::assertSame(7, $domainId);
                $types[] = $contentType;
            });

        (new ManualBlockCacheSubscriber($invalidator))->onContentChanged(
            new ManualContentChangedEvent(7, 'page_updated')
        );

        $this->assertSame(['manual_books', 'manual_toc', 'manual_page', 'manual_recent'], $types);
    }
}
