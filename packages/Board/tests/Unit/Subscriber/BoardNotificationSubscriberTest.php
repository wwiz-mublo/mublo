<?php

namespace Tests\Board\Unit\Subscriber;

use Mublo\Contract\Notification\MemberNotification;
use Mublo\Contract\Notification\MemberNotificationPublisherInterface;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Subscriber\BoardNotificationSubscriber;
use Tests\Board\TestCase;

final class BoardNotificationSubscriberTest extends TestCase
{
    public function testPublishesArticleCommentToRecipientsCurrentDomain(): void
    {
        $publisher = $this->createMock(MemberNotificationPublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (MemberNotification $notification): bool {
                $this->assertSame(9, $notification->domainId);
                $this->assertSame(42, $notification->memberId);
                $this->assertSame(7, $notification->actorMemberId);
                $this->assertSame('board.comment.created', $notification->type);
                $this->assertSame('내 글에 새 댓글이 달렸습니다.', $notification->title);
                $this->assertSame('강조된 댓글 내용', $notification->body);
                $this->assertSame('/board/global-board/view/15#comment-70', $notification->targetUrl);
                $this->assertSame('board-comment:70:article', $notification->deduplicationKey);
                return true;
            }))
            ->willReturn(1);

        $comments = $this->createMock(BoardCommentRepository::class);
        $comments->expects($this->never())->method('find');
        $subscriber = new BoardNotificationSubscriber(
            $publisher,
            $this->boardRepository(),
            $comments,
            $this->memberRepository([42 => 9]),
        );

        $subscriber->onCommentCreated($this->event(
            articleAuthorId: 42,
            actorId: 7,
            content: '<b>강조된</b>   댓글 내용',
        ));
    }

    public function testReplyNotifiesArticleAndParentCommentAuthorsWithoutDuplicates(): void
    {
        $published = [];
        $publisher = $this->createMock(MemberNotificationPublisherInterface::class);
        $publisher->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(function (MemberNotification $notification) use (&$published): int {
                $published[$notification->memberId] = $notification;
                return count($published);
            });
        $comments = $this->createMock(BoardCommentRepository::class);
        $comments->method('find')->with(88)->willReturn(BoardComment::fromArray($this->makeCommentData([
            'comment_id' => 88,
            'member_id' => 43,
        ])));
        $subscriber = new BoardNotificationSubscriber(
            $publisher,
            $this->boardRepository(),
            $comments,
            $this->memberRepository([42 => 1, 43 => 3]),
        );

        $subscriber->onCommentCreated($this->event(
            articleAuthorId: 42,
            actorId: 7,
            parentId: 88,
        ));

        $this->assertSame('board.comment.created', $published[42]->type);
        $this->assertSame(1, $published[42]->domainId);
        $this->assertSame('board.comment.replied', $published[43]->type);
        $this->assertSame(3, $published[43]->domainId);
    }

    public function testDoesNotNotifyCommentAuthorAboutOwnComment(): void
    {
        $publisher = $this->createMock(MemberNotificationPublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $subscriber = new BoardNotificationSubscriber(
            $publisher,
            $this->boardRepository(),
            $this->createMock(BoardCommentRepository::class),
            $this->createMock(MemberQueryInterface::class),
        );

        $subscriber->onCommentCreated($this->event(articleAuthorId: 7, actorId: 7));
    }

    private function event(
        int $articleAuthorId,
        ?int $actorId,
        ?int $parentId = null,
        string $content = '댓글 내용',
    ): CommentCreatedEvent {
        $article = BoardArticle::fromArray($this->makeArticleData([
            'article_id' => 15,
            'domain_id' => 1,
            'board_id' => 5,
            'member_id' => $articleAuthorId,
        ]));
        $comment = BoardComment::fromArray($this->makeCommentData([
            'comment_id' => 70,
            'domain_id' => 2,
            'board_id' => 5,
            'article_id' => 15,
            'parent_id' => $parentId,
            'member_id' => $actorId,
            'content' => $content,
        ]));

        return new CommentCreatedEvent($comment, $article);
    }

    private function boardRepository(): BoardConfigRepository
    {
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->method('find')->with(5)->willReturn(BoardConfig::fromArray([
            'board_id' => 5,
            'domain_id' => 1,
            'board_slug' => 'global-board',
            'board_name' => '전역 게시판',
            'is_global' => true,
        ]));
        return $repository;
    }

    /** @param array<int, int> $domainsByMember */
    private function memberRepository(array $domainsByMember): MemberQueryInterface
    {
        $repository = $this->createMock(MemberQueryInterface::class);
        $repository->method('findProfile')->willReturnCallback(
            fn (int $memberId) => isset($domainsByMember[$memberId])
                ? new MemberProfile(
                    memberId: $memberId,
                    domainId: $domainsByMember[$memberId],
                    userId: 'member' . $memberId,
                    nickname: null,
                    levelValue: 1,
                    active: true,
                )
                : null
        );
        return $repository;
    }
}
