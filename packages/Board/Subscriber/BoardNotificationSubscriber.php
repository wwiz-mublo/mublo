<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Contract\Notification\MemberNotification;
use Mublo\Contract\Notification\MemberNotificationPublisherInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;

/**
 * Board 이벤트를 Core 회원 알림 센터의 공개 계약에 연결한다.
 */
final class BoardNotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemberNotificationPublisherInterface $publisher,
        private BoardConfigRepository $boards,
        private BoardCommentRepository $comments,
        private MemberQueryInterface $members,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [CommentCreatedEvent::class => 'onCommentCreated'];
    }

    public function onCommentCreated(CommentCreatedEvent $event): void
    {
        $article = $event->getArticle();
        $comment = $event->getComment();
        $actorId = $event->getMemberId();
        $board = $this->boards->find($article->getBoardId());
        if (!$board instanceof BoardConfig) {
            return;
        }

        /** @var array<int, string> $recipients */
        $recipients = [];
        $articleAuthorId = $event->getArticleAuthorId();
        if ($articleAuthorId !== null) {
            $recipients[$articleAuthorId] = 'article';
        }

        if ($event->getParentId() !== null) {
            $parent = $this->comments->find($event->getParentId());
            if ($parent instanceof BoardComment && $parent->getMemberId() !== null) {
                $recipients[$parent->getMemberId()] ??= 'reply';
            }
        }

        foreach ($recipients as $recipientId => $relation) {
            if ($recipientId === $actorId) {
                continue;
            }

            $recipient = $this->members->findProfile($recipientId);
            if ($recipient === null) {
                continue;
            }

            $this->publisher->publish(new MemberNotification(
                domainId: $recipient->domainId,
                memberId: $recipientId,
                type: $relation === 'reply' ? 'board.comment.replied' : 'board.comment.created',
                title: $relation === 'reply'
                    ? '내 댓글에 새 답글이 달렸습니다.'
                    : '내 글에 새 댓글이 달렸습니다.',
                body: $this->makePreview($comment->getContent()),
                targetUrl: '/board/' . rawurlencode($board->getBoardSlug())
                    . '/view/' . $article->getArticleId()
                    . '#comment-' . $comment->getCommentId(),
                source: 'package:Board',
                sourceId: (string) $comment->getCommentId(),
                actorMemberId: $actorId,
                deduplicationKey: 'board-comment:' . $comment->getCommentId() . ':' . $relation,
                metadata: [
                    'board_id' => $article->getBoardId(),
                    'article_id' => $article->getArticleId(),
                    'comment_id' => $comment->getCommentId(),
                    'relation' => $relation,
                ],
            ));
        }
    }

    private function makePreview(string $content): string
    {
        $plain = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? '';
        $plain = trim($plain);

        return mb_strlen($plain) > 160 ? mb_substr($plain, 0, 157) . '...' : $plain;
    }
}
