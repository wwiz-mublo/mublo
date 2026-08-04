<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Repository\Block\BlockColumnRepository;

final readonly class BlockContentCacheInvalidator implements BlockContentCacheInvalidatorInterface
{
    public function __construct(
        private BlockColumnRepository $columns,
        private BlockRenderService $renderer
    ) {
    }

    public function invalidateByContentType(int $domainId, string $contentType): void
    {
        if ($domainId <= 0 || trim($contentType) === '') {
            return;
        }

        $this->invalidateColumns($this->columns->findByContentType($domainId, $contentType));
    }

    public function invalidateByContentItem(
        int $domainId,
        string $contentType,
        int|string $itemId
    ): void {
        if ($domainId <= 0 || trim($contentType) === '' || (string) $itemId === '') {
            return;
        }

        $this->invalidateColumns($this->columns->findByContentItem($domainId, $contentType, $itemId));
    }

    public function invalidateDomain(int $domainId): void
    {
        if ($domainId > 0) {
            $this->renderer->invalidateDomainCache($domainId);
        }
    }

    /** @param BlockColumn[] $columns */
    private function invalidateColumns(array $columns): void
    {
        $rowIds = [];
        foreach ($columns as $column) {
            $rowId = $column->getRowId();
            if ($rowId > 0) {
                $rowIds[$rowId] = true;
            }
        }

        foreach (array_keys($rowIds) as $rowId) {
            $this->renderer->invalidateRowCache((int) $rowId);
        }
    }
}
