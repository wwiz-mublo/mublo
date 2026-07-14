<?php

namespace Mublo\Packages\Board\Contract\Extension;

use Mublo\Core\Context\Context;
use Mublo\Core\Result\Result;

/**
 * Board 확장이 게시글 변경을 요청할 때 사용하는 안정 API.
 */
interface BoardArticleCommandInterface
{
    public function delete(int $articleId, Context $context): Result;
}
