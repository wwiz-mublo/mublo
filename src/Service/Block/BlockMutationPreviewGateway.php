<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockMutationPreviewInterface;

final class BlockMutationPreviewGateway implements BlockMutationPreviewInterface
{
    private const ROW_FIELDS = [
        'page_id', 'position', 'position_menu', 'section_id', 'admin_title',
        'width_type', 'column_count', 'column_margin', 'column_width_unit',
        'pc_height', 'mobile_height', 'pc_margin', 'mobile_margin',
        'pc_padding', 'mobile_padding', 'background_config', 'is_active',
        'dismissible', 'dismiss_hours',
    ];

    public function __construct(private BlockPreviewService $previews)
    {
    }

    public function renderNewRow(int $domainId, array $row, array $columns): ?string
    {
        $row = $this->sanitizeRow($row);
        $row['domain_id'] = $domainId;
        $token = $this->previews->createPreview(
            $row,
            $columns,
            BlockColumnWriteContext::preview($domainId)
        );
        if ($token === null) {
            return null;
        }
        try {
            return $this->previews->renderPreview($token);
        } finally {
            $this->previews->deletePreview($token);
        }
    }

    public function renderExistingRow(
        int $domainId,
        int $rowId,
        array $rowPatch,
        ?array $columns = null
    ): ?string {
        $rowPatch = $this->sanitizeRow($rowPatch);
        return $this->previews->renderExistingRowPreview(
            $rowId,
            $domainId,
            $rowPatch,
            $columns ?? [],
            BlockColumnWriteContext::preview($domainId),
            $columns !== null
        );
    }

    /** @param array<string, mixed> $row */
    private function sanitizeRow(array $row): array
    {
        return array_intersect_key($row, array_fill_keys(self::ROW_FIELDS, true));
    }
}
