<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockRow;

/** 저장되지 않은 Block Entity 구성 한 행을 실제 런타임과 동일하게 렌더링한다. */
interface BlockPreviewRendererInterface
{
    /** @param BlockColumn[] $columns */
    public function renderRow(BlockRow $row, array $columns): string;
}
