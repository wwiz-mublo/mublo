<?php

namespace Mublo\Packages\Board\Contract\Extension;

/**
 * Board 종속 Plugin의 단일 공개 진입점.
 */
interface BoardExtensionApiInterface
{
    public function articles(): BoardArticleReaderInterface;

    public function commands(): BoardArticleCommandInterface;
}
