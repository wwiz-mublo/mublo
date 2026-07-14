<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 수정 완료 이벤트 (readonly)
 *
 * 발행 시점: DB 업데이트 후
 * 용도: 수정 이력 기록, 검색 재인덱싱
 */
class ArticleUpdatedEvent extends AbstractEvent
{
    public const NAME = 'board.article.updated';

    public function __construct(
        private readonly BoardArticle $article,
        private readonly array $oldData,
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

    public function getOldData(): array
    {
        return $this->oldData;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }
}
