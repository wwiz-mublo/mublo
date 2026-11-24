<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockMutationInterface;
use Mublo\Contract\Block\BlockRowSnapshot;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;

final class BlockMutationGateway implements BlockMutationInterface
{
    private const ROW_FIELDS = [
        'page_id', 'position', 'position_menu', 'section_id', 'admin_title',
        'width_type', 'column_count', 'column_margin', 'column_width_unit',
        'pc_height', 'mobile_height', 'pc_margin', 'mobile_margin',
        'pc_padding', 'mobile_padding', 'background_config', 'is_active',
        'dismissible', 'dismiss_hours',
    ];

    public function __construct(
        private BlockRowService $rows,
        private BlockRowRepository $rowRepository,
        private BlockColumnRepository $columnRepository,
        private BlockColumnContentService $columnContents
    ) {
    }

    public function getRow(int $domainId, int $rowId): ?BlockRowSnapshot
    {
        $row = $this->rowRepository->find($rowId);
        return $row !== null && $row->getDomainId() === $domainId
            ? $this->snapshot($row)
            : null;
    }

    public function listPositionRows(int $domainId, string $position, ?string $menuCode = null): array
    {
        return array_map(
            fn (BlockRow $row): BlockRowSnapshot => $this->snapshot($row),
            $this->rowRepository->findByPositionScope($domainId, $position, $menuCode)
        );
    }

    public function listPageRows(int $domainId, int $pageId): array
    {
        $rows = array_filter(
            $this->rowRepository->findAllByPage($pageId),
            static fn (BlockRow $row): bool => $row->getDomainId() === $domainId
        );
        return array_map(
            fn (BlockRow $row): BlockRowSnapshot => $this->snapshot($row),
            array_values($rows)
        );
    }

    public function createRow(
        int $domainId,
        array $row,
        array $columns,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        $row = $this->sanitizeRow($row);
        return $this->rows->createRow(
            $domainId,
            $row,
            $columns,
            BlockColumnWriteContext::interactive($domainId),
            $mutationContext
        );
    }

    public function updateRow(
        int $domainId,
        int $rowId,
        array $rowPatch,
        ?array $columns,
        int $expectedRevision,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        $current = $this->rowRepository->find($rowId);
        if ($current === null || $current->getDomainId() !== $domainId) {
            return Result::failure('행을 찾을 수 없습니다.');
        }
        // BlockRowService의 폼 정규화 기본값이 누락 필드를 덮지 않도록 현재
        // aggregate에 patch를 합친 완전한 행 payload로 전달한다.
        $rowPatch = array_replace(
            $this->sanitizeRow($current->toArray()),
            $this->sanitizeRow($rowPatch)
        );
        if ($columns !== null) {
            // 빈 배열도 명시적 전체 제거로 구분되게 한다.
            $rowPatch['columns'] = true;
        }
        return $this->rows->updateRow(
            $rowId,
            $rowPatch,
            $columns ?? [],
            $domainId,
            BlockColumnWriteContext::interactive($domainId),
            $expectedRevision,
            null,
            $mutationContext
        );
    }

    public function setRowActive(
        int $domainId,
        int $rowId,
        bool $active,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        return $this->rows->setActive($rowId, $active, $domainId, $mutationContext);
    }

    public function moveRow(
        int $domainId,
        int $rowId,
        ?string $targetPosition,
        ?int $targetPageId,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        return $this->rows->moveRow(
            $rowId,
            $targetPosition,
            $targetPageId,
            $domainId,
            $mutationContext
        );
    }

    public function reorderRows(
        int $domainId,
        array $orderedRowIds,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        $normalized = array_values(array_map('intval', $orderedRowIds));
        if ($normalized === [] || count(array_unique($normalized)) !== count($normalized)) {
            return Result::failure('같은 슬롯의 모든 행을 중복 없이 지정해야 합니다.');
        }
        $first = $this->rowRepository->find($normalized[0]);
        if ($first === null || $first->getDomainId() !== $domainId) {
            return Result::failure('정렬할 행을 찾을 수 없습니다.');
        }
        $slotRows = $first->getPageId() !== null
            ? array_values(array_filter(
                $this->rowRepository->findAllByPage($first->getPageId()),
                static fn (BlockRow $row): bool => $row->getDomainId() === $domainId
            ))
            : $this->rowRepository->findByPositionScope(
                $domainId,
                $first->getPosition()?->value ?? '',
                $first->getPositionMenu()
            );
        $current = array_map(static fn (BlockRow $row): int => $row->getRowId(), $slotRows);
        $expected = $current;
        $actual = $normalized;
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            return Result::failure('같은 슬롯의 모든 행을 중복 없이 지정해야 합니다.');
        }
        if ($normalized === $current) {
            return Result::success('블록이 이미 요청한 순서입니다.');
        }
        return $this->rows->updateOrder($normalized, $domainId, $mutationContext);
    }

    public function removeRow(
        int $domainId,
        int $rowId,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        return $this->rows->deleteRow($rowId, $domainId, null, $mutationContext);
    }

    private function snapshot(BlockRow $row): BlockRowSnapshot
    {
        $columns = $this->columnRepository->findAllByRowForDomain(
            $row->getRowId(),
            $row->getDomainId()
        );
        return new BlockRowSnapshot(
            $row->getRowId(),
            $row->getDomainId(),
            $row->getRevisionNo(),
            $row->toArray(),
            $this->columnContents->columnsToPortableArrays($columns)
        );
    }

    /** @param array<string, mixed> $row */
    private function sanitizeRow(array $row): array
    {
        return array_intersect_key($row, array_fill_keys(self::ROW_FIELDS, true));
    }
}
