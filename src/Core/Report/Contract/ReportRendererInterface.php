<?php

namespace Mublo\Core\Report\Contract;

use Mublo\Core\Report\Document\ReportDocument;

interface ReportRendererInterface
{
    public function supports(string $format): bool;

    public function mimeType(): string;

    public function extension(): string;

    public function renderToFile(ReportDocument $document, string $filePath): void;
}

