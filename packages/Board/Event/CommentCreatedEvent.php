<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Entity\Member\Member;

/**
 * 댓글 작성 완료 이벤트 (readonly)
 *
 * 발행 시점: board_comments 테이블 INSERT 후
 * 용도: 포인트 지급, 작성자에게 알림
 */
class CommentCreatedEvent extends AbstractEvent
{
    public const NAME = 'board.comment.created';

    public function __construct(
        private readonly BoardComment $comment,
        private readonly BoardArticle $article,
        private readonly ?Member $author = null
    ) {}

    public function getComment(): BoardComment
    {
        return $this->comment;
    }

    public function getCommentId(): int
    {
        return $this->comment->getCommentId();
    }

    public function getArticle(): BoardArticle
    {
        return $this->article;
    }

    public function getArticleId(): int
    {
        return $this->article->getArticleId();
    }

    public function getAuthor(): ?Member
    {
        return $this->author;
    }

    /**
     * 게시글 작성자 ID (알림 발송용)
     */
    public function getArticleAuthorId(): ?int
    {
        return $this->article->getMemberId();
    }

    public function getMemberId(): ?int
    {
        return $this->comment->getMemberId();
    }

    /**
     * 대댓글인지 확인
     */
    public function isReply(): bool
    {
        return $this->comment->isReply();
    }

    /**
     * 부모 댓글 ID (대댓글인 경우)
     */
    public function getParentId(): ?int
    {
        return $this->comment->getParentId();
    }
}
