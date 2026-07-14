<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 조회 이벤트
 *
 * 발행 시점: 게시글 조회 시 (view_count 증가 전)
 * 용도: 조회 이력 기록, 추천 알고리즘
 */
class ArticleViewedEvent extends AbstractEvent
{
    public const NAME = 'board.article.viewed';

    public function __construct(
        private readonly BoardArticle $article,
        private readonly ?int $memberId,
        private readonly ?string $ipAddress = null
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

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
}
