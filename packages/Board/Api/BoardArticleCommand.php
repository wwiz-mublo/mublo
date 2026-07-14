<?php

namespace Mublo\Packages\Board\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Contract\Extension\BoardArticleCommandInterface;
use Mublo\Packages\Board\Service\BoardArticleService;

/** @internal BoardProvider가 공개 Contract 뒤에 바인딩하는 구현체 */
final class BoardArticleCommand implements BoardArticleCommandInterface
{
    public function __construct(private BoardArticleService $articles)
    {
    }

    public function delete(int $articleId, Context $context): Result
    {
        return $this->articles->delete($articleId, $context);
    }
}
