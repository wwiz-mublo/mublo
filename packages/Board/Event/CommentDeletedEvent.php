<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardComment;

/**
 * 댓글 삭제 완료 이벤트 (readonly)
 *
 * 발행 시점: board_comments 상태 변경/DELETE 후
 * 용도: 포인트 회수
 */
class CommentDeletedEvent extends AbstractEvent
{
    public const NAME = 'board.comment.deleted';

    public function __construct(
        private readonly BoardComment $comment,
        private readonly ?int $memberId
    ) {}

    public function getComment(): BoardComment
    {
        return $this->comment;
    }

    public function getCommentId(): int
    {
        return $this->comment->getCommentId();
    }

    public function getArticleId(): int
    {
        return $this->comment->getArticleId();
    }

    public function getBoardId(): int
    {
        return $this->comment->getBoardId();
    }

    public function getCommentAuthorId(): ?int
    {
        return $this->comment->getMemberId();
    }

    /**
     * 삭제한 회원 ID
     */
    public function getMemberId(): ?int
    {
        return $this->memberId;
    }
}
