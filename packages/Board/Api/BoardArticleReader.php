<?php

namespace Mublo\Packages\Board\Api;

use Mublo\Packages\Board\Api\DTO\ArticleSnapshot;
use Mublo\Packages\Board\Contract\Extension\BoardArticleReaderInterface;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardConfigService;

/** @internal BoardProvider가 공개 Contract 뒤에 바인딩하는 구현체 */
final class BoardArticleReader implements BoardArticleReaderInterface
{
    public function __construct(
        private BoardArticleService $articles,
        private BoardConfigService $boards
    ) {
    }

    public function findAccessibleById(int $articleId, int $domainId): ?ArticleSnapshot
    {
        $article = $this->articles->findById($articleId);
        if ($article === null) {
            return null;
        }

        $board = $this->boards->getBoard($article->getBoardId());
        if ($board === null
            || ($article->getDomainId() !== $domainId && !$board->isGlobal())) {
            return null;
        }

        return new ArticleSnapshot(
            $article->getArticleId(),
            $article->getDomainId(),
            $article->getBoardId(),
            $article->getTitle()
        );
    }
}
