<?php

namespace Tests\Board\Unit\Subscriber;

use Mublo\Packages\Board\Event\CommentUpdatedEvent;
use Mublo\Packages\Board\Subscriber\BoardBlockCacheSubscriber;
use PHPUnit\Framework\TestCase;

class BoardBlockCacheSubscriberTest extends TestCase
{
    public function testCommentUpdateInvalidatesLatestCommentBlockCache(): void
    {
        $events = BoardBlockCacheSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(CommentUpdatedEvent::class, $events);
        $this->assertSame('onCommentChanged', $events[CommentUpdatedEvent::class]);
    }
}
