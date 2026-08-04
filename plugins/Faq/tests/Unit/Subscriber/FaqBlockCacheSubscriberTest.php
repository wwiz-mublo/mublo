<?php

namespace Tests\Faq\Unit\Subscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Plugin\Faq\Event\FaqContentChangedEvent;
use Mublo\Plugin\Faq\Subscriber\FaqBlockCacheSubscriber;
use PHPUnit\Framework\TestCase;

final class FaqBlockCacheSubscriberTest extends TestCase
{
    public function testInvalidatesFaqBlocksThroughStableContract(): void
    {
        $invalidator = $this->createMock(BlockContentCacheInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateByContentType')
            ->with(7, 'faq');

        (new FaqBlockCacheSubscriber($invalidator))->onChanged(new FaqContentChangedEvent(7));
    }
}
