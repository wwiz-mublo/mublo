<?php
declare(strict_types=1);

namespace Mublo\Core\Report\Contract;

use Mublo\Core\Report\Document\ReportDocument;

interface ReportDefinitionInterface
{
    public function name(): string;

    public function build(array $filters): ReportDocument;
}

