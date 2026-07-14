<?php

namespace Mublo\Packages\Board\Api\DTO;

/**
 * Extension API용 readonly 게시글 값 객체.
 *
 * 내부 Entity를 노출하지 않아 Board의 영속 구조를 독립적으로 변경할 수 있다.
 */
final readonly class ArticleSnapshot
{
    public function __construct(
        private int $articleId,
        private int $domainId,
        private int $boardId,
        private string $title
    ) {
    }

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
