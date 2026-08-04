<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Api;

use Mublo\Packages\Board\Contract\Extension\BoardArticleCommandInterface;
use Mublo\Packages\Board\Contract\Extension\BoardArticleReaderInterface;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;

/** @internal 공개 Contract의 기본 조합 구현체 */
final class BoardExtensionApi implements BoardExtensionApiInterface
{
    public function __construct(
        private BoardArticleReaderInterface $articles,
        private BoardArticleCommandInterface $commands
    ) {
    }

    public function articles(): BoardArticleReaderInterface
    {
        return $this->articles;
    }

    public function commands(): BoardArticleCommandInterface
    {
        return $this->commands;
    }
}
