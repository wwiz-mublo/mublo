<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockRowSnapshot;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Entity\Block\BlockRow;

/** portable block snapshot을 저장 없이 렌더 가능한 엔티티로 복원한다. */
final class BlockSnapshotHydrator
{
    /** @return array{BlockRow, list<BlockColumn>} */
    public static function hydrate(BlockRowSnapshot $snapshot): array
    {
        return [
            self::row($snapshot->row, $snapshot->domainId, $snapshot->rowId),
            self::columns($snapshot->columns, $snapshot->domainId, $snapshot->rowId),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function row(array $data, int $domainId, int $rowId = 0): BlockRow
    {
        $data['row_id'] = $rowId !== 0 ? $rowId : (int) ($data['row_id'] ?? 0);
        $data['domain_id'] = $domainId;
        $data['is_active'] = $data['is_active'] ?? 1;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return BlockRow::fromArray($data);
    }

    /**
     * @param list<array<string, mixed>> $columnsData
     * @return list<BlockColumn>
     */
    public static function columns(array $columnsData, int $domainId, int $rowId = 0): array
    {
        $columns = [];
        foreach (array_values($columnsData) as $index => $colData) {
            $columnId = (int) ($colData['column_id'] ?? 0);
            if ($columnId === 0) {
                // 한 draft 안에서도 렌더 key가 겹치지 않게 음수 임시 ID를 쓴다.
                $columnId = -($index + 1);
            }
            $colData['column_id'] = $columnId;
            $colData['row_id'] = $rowId;
            $colData['domain_id'] = $domainId;
            $colData['column_index'] = $colData['column_index'] ?? $index;
            $colData['is_active'] = $colData['is_active'] ?? 1;
            $colData['created_at'] = $colData['created_at'] ?? date('Y-m-d H:i:s');
            $colData['updated_at'] = $colData['updated_at'] ?? date('Y-m-d H:i:s');

            $column = BlockColumn::fromArray($colData);
            if ($column->isStack() && isset($colData['contents']) && is_array($colData['contents'])) {
                $contents = [];
                foreach (array_values($colData['contents']) as $contentIndex => $contentData) {
                    if (!is_array($contentData)) {
                        continue;
                    }
                    $contentData['content_id'] = (int) ($contentData['content_id'] ?? 0) !== 0
                        ? (int) $contentData['content_id']
                        : -($contentIndex + 1);
                    $contentData['column_id'] = $columnId;
                    $contentData['domain_id'] = $domainId;
                    $contents[] = BlockColumnContent::fromArray($contentData);
                }
                $column->setContents($contents);
            }
            $columns[] = $column;
        }
        return $columns;
    }
}
