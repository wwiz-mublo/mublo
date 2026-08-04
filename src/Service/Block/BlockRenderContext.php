<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockRenderContextInterface;

final readonly class BlockRenderContext implements BlockRenderContextInterface
{
    public function __construct(private BlockRenderService $renderer)
    {
    }

    public function setVariant(string $variant): void
    {
        $this->renderer->setCacheVariant($variant);
    }
}
