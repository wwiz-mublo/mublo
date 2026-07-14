<?php
namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Board\Event\ArticleCreatedEvent;
use Mublo\Packages\Board\Event\ArticleDeletedEvent;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Event\CommentDeletedEvent;
use Mublo\Packages\Board\Event\ArticleViewingEvent;
use Mublo\Packages\Board\Event\FileDownloadingEvent;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Service\BoardPointService;

/**
 * BoardPointSubscriber
 *
 * 게시판 이벤트 발생 시 포인트 적립/회수/소비 처리
 *
 * Board 패키지가 자체적으로 포인트 정책을 관리하며
 * 공개 계약 BalanceGatewayInterface(코어 잔액 엔진)를 통해 잔액을 조정합니다.
 */
class BoardPointSubscriber implements EventSubscriberInterface
{
    /** board_id → group_id 캐시 */
    private array $boardGroupCache = [];

    public function __construct(
        private BoardPointService $pointService,
        private BoardConfigRepository $boardConfigRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ArticleCreatedEvent::class  => 'onArticleCreated',
            ArticleDeletedEvent::class  => 'onArticleDeleted',
            CommentCreatedEvent::class  => 'onCommentCreated',
            CommentDeletedEvent::class  => 'onCommentDeleted',
            ArticleViewingEvent::class  => 'onArticleViewing',
            FileDownloadingEvent::class => 'onFileDownloading',
        ];
    }

    // =========================================================================
    // 적립 이벤트
    // =========================================================================

    public function onArticleCreated(ArticleCreatedEvent $event): void
    {
        $memberId = $event->getMemberId();
        if (!$memberId) return;

        $domainId  = $event->getDomainId();
        $boardId   = $event->getBoardId();
        $articleId = $event->getArticleId();

        $this->pointService->award($domainId, $memberId, 'article_write', [
            'board_id'        => $boardId,
            'group_id'        => $this->resolveGroupId($boardId),
            'reference_type'  => 'article',
            'reference_id'    => (string) $articleId,
            'idempotency_key' => "bp_article_{$domainId}_{$articleId}",
        ]);
    }

    public function onArticleDeleted(ArticleDeletedEvent $event): void
    {
        $authorId = $event->getArticleAuthorId();
        if (!$authorId) return;

        $domainId  = $event->getDomainId();
        $boardId   = $event->getBoardId();
        $articleId = $event->getArticleId();

        $this->pointService->revoke($domainId, $authorId, 'article_write', [
            'board_id'        => $boardId,
            'group_id'        => $this->resolveGroupId($boardId),
            'reference_type'  => 'article',
            'reference_id'    => (string) $articleId,
            'idempotency_key' => "bp_article_revoke_{$domainId}_{$articleId}",
        ]);
    }

    public function onCommentCreated(CommentCreatedEvent $event): void
    {
        $memberId = $event->getMemberId();
        if (!$memberId) return;

        $comment   = $event->getComment();
        $domainId  = $comment->getDomainId();
        $boardId   = $comment->getBoardId();
        $commentId = $event->getCommentId();

        $this->pointService->award($domainId, $memberId, 'comment_write', [
            'board_id'        => $boardId,
            'group_id'        => $this->resolveGroupId($boardId),
            'reference_type'  => 'comment',
            'reference_id'    => (string) $commentId,
            'idempotency_key' => "bp_comment_{$domainId}_{$commentId}",
        ]);
    }

    public function onCommentDeleted(CommentDeletedEvent $event): void
    {
        $authorId = $event->getCommentAuthorId();
        if (!$authorId) return;

        $comment   = $event->getComment();
        $domainId  = $comment->getDomainId();
        $boardId   = $comment->getBoardId();
        $commentId = $event->getCommentId();

        $this->pointService->revoke($domainId, $authorId, 'comment_write', [
            'board_id'        => $boardId,
            'group_id'        => $this->resolveGroupId($boardId),
            'reference_type'  => 'comment',
            'reference_id'    => (string) $commentId,
            'idempotency_key' => "bp_comment_revoke_{$domainId}_{$commentId}",
        ]);
    }

    // =========================================================================
    // 소비 이벤트 (포인트 부족 시 차단)
    // =========================================================================

    public function onArticleViewing(ArticleViewingEvent $event): void
    {
        $memberId = $event->getMemberId();
        if (!$memberId) return;

        // 본인 글 읽기는 무료
        if ($event->getArticle()->getMemberId() === $memberId) return;

        $domainId  = $event->getDomainId();
        $boardId   = $event->getBoardId();
        $articleId = $event->getArticleId();

        $result = $this->pointService->consume($domainId, $memberId, 'article_read', $boardId, [
            'group_id'        => $this->resolveGroupId($boardId),
            'reference_type'  => 'article',
            'reference_id'    => (string) $articleId,
            'idempotency_key' => "bp_read_{$domainId}_{$memberId}_{$articleId}",
        ]);

        if (!$result['success']) {
            $event->setBlocked(true);
            $event->setBlockReason($result['message'] ?? '포인트가 부족합니다.');
        }
    }

    public function onFileDownloading(FileDownloadingEvent $event): void
    {
        $memberId = $event->getMemberId();
        if (!$memberId) return;

        $domainId     = $event->getDomainId();
        $boardId      = $event->getBoardId();
        $attachmentId = $event->getAttachmentId();

        $result = $this->pointService->consume($domainId, $memberId, 'file_download', $boardId, [
            'group_id'        => $this->resolveGroupId($boardId),
            'reference_type'  => 'attachment',
            'reference_id'    => (string) $attachmentId,
            'idempotency_key' => "bp_download_{$domainId}_{$memberId}_{$attachmentId}",
        ]);

        if (!$result['success']) {
            $event->setBlocked(true);
            $event->setBlockReason($result['message'] ?? '포인트가 부족합니다.');
        }
    }

    // =========================================================================
    // 헬퍼
    // =========================================================================

    private function resolveGroupId(int $boardId): ?int
    {
        if (array_key_exists($boardId, $this->boardGroupCache)) {
            return $this->boardGroupCache[$boardId];
        }

        $board = $this->boardConfigRepository->find($boardId);
        $groupId = $board ? $board->getGroupId() : null;
        $this->boardGroupCache[$boardId] = $groupId ?: null;

        return $this->boardGroupCache[$boardId];
    }
}
