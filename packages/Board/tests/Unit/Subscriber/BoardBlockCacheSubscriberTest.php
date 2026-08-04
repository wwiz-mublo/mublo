<?php

namespace Tests\Board\Unit\Subscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Event\CommentUpdatedEvent;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
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

    public function testCommentChangesInvalidateCommentBlocksThroughStableContract(): void
    {
        $repository = new BoardConfigRepository($this->createMock(Database::class));
        $invalidator = $this->createMock(BlockContentCacheInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateByContentType')
            ->with(13, 'boardcomment');

        $comment = BoardComment::fromArray([
            'comment_id' => 1,
            'domain_id' => 13,
            'board_id' => 2,
            'article_id' => 3,
        ]);
        $article = BoardArticle::fromArray([
            'article_id' => 3,
            'domain_id' => 13,
            'board_id' => 2,
        ]);

        (new BoardBlockCacheSubscriber($repository, $invalidator))->onCommentChanged(
            new CommentCreatedEvent($comment, $article)
        );
    }
}
