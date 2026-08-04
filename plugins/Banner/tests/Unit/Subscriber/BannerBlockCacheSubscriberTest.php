<?php

namespace Tests\Banner\Unit\Subscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Plugin\Banner\Event\BannerContentChangedEvent;
use Mublo\Plugin\Banner\Subscriber\BannerBlockCacheSubscriber;
use PHPUnit\Framework\TestCase;

final class BannerBlockCacheSubscriberTest extends TestCase
{
    public function testInvalidatesBannerBlocksThroughStableContract(): void
    {
        $invalidator = $this->createMock(BlockContentCacheInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateByContentType')
            ->with(7, 'banner');

        (new BannerBlockCacheSubscriber($invalidator))->onChanged(new BannerContentChangedEvent(7));
    }
}
