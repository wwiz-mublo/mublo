<?php

namespace Tests\Board\Unit\Event;

use Mublo\Contract\Member\MemberIdentity;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Packages\Board\Event\ArticleCreatedEvent;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Event\FileDownloadedEvent;
use PHPUnit\Framework\TestCase;

final class MemberIdentityEventTest extends TestCase
{
    public function testCreatedEventsExposeStableMemberIdentity(): void
    {
        $identity = new MemberIdentity(7, 3, 'member7', '회원 7', 'a3f9c2e81b47d06f5a92c1');
        $article = BoardArticle::fromArray(['article_id' => 1, 'domain_id' => 3, 'board_id' => 2]);
        $comment = BoardComment::fromArray([
            'comment_id' => 4,
            'domain_id' => 3,
            'board_id' => 2,
            'article_id' => 1,
        ]);

        $articleEvent = new ArticleCreatedEvent($article, $identity);
        $commentEvent = new CommentCreatedEvent($comment, $article, $identity);

        $this->assertSame($identity, $articleEvent->getAuthorIdentity());
        $this->assertSame($identity, $articleEvent->getAuthor());
        $this->assertSame($identity, $commentEvent->getAuthorIdentity());
        $this->assertSame(7, $commentEvent->getAuthor()?->getMemberId());
        $this->assertSame('a3f9c2e81b47d06f5a92c1', $commentEvent->getAuthor()?->getPublicId());
    }

    public function testDownloadEventKeepsIdConvenienceAccessor(): void
    {
        $identity = new MemberIdentity(9, 4, 'member9', '회원 9', 'b3f9c2e81b47d06f5a92c2');
        $attachment = BoardAttachment::fromArray([
            'attachment_id' => 5,
            'domain_id' => 4,
            'board_id' => 2,
            'article_id' => 1,
        ]);
        $event = new FileDownloadedEvent($attachment, $identity);

        $this->assertSame($identity, $event->getDownloaderIdentity());
        $this->assertSame($identity, $event->getDownloader());
        $this->assertSame(9, $event->getDownloaderId());
        $this->assertSame('b3f9c2e81b47d06f5a92c2', $event->getDownloader()?->getPublicId());
    }
}
