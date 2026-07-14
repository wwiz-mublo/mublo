<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 삭제 완료 이벤트 (readonly)
 *
 * 발행 시점: board_articles 상태 변경/DELETE 후
 * 용도: 포인트 회수, 관련 데이터 정리
 */
class ArticleDeletedEvent extends AbstractEvent
{
    public const NAME = 'board.article.deleted';

    public function __construct(
        private readonly BoardArticle $article,
        private readonly ?int $memberId
    ) {}

    public function getArticle(): BoardArticle
    {
        return $this->article;
    }

    public function getArticleId(): int
    {
        return $this->article->getArticleId();
    }

    public function getBoardId(): int
    {
        return $this->article->getBoardId();
    }

    public function getDomainId(): int
    {
        return $this->article->getDomainId();
    }

    public function getArticleAuthorId(): ?int
    {
        return $this->article->getMemberId();
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }
}
