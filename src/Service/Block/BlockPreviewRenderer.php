<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockPreviewRendererInterface;
use Mublo\Entity\Block\BlockRow;

final readonly class BlockPreviewRenderer implements BlockPreviewRendererInterface
{
    public function __construct(private BlockRenderService $renderer)
    {
    }

    public function renderRow(BlockRow $row, array $columns): string
    {
        return $this->renderer->renderRowFromEntities($row, $columns);
    }
}
