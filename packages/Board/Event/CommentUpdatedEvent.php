<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardComment;

/**
 * 댓글 수정 완료 이벤트 (readonly)
 *
 * 발행 시점: board_comments 테이블 UPDATE 후
 * 용도: 캐시 무효화, 수정 이력 기록
 */
class CommentUpdatedEvent extends AbstractEvent
{
    public const NAME = 'board.comment.updated';

    public function __construct(
        private readonly BoardComment $comment,
        private readonly array $oldData,
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

    public function getOldData(): array
    {
        return $this->oldData;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }
}
