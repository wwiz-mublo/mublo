<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;

/**
 * 블록 행·칸·콘텐츠 집합의 안전한 공개 변경 계약.
 *
 * 칸과 스택 콘텐츠는 행 aggregate의 columns payload로 동기화한다. 기존
 * column_id/content_id를 싣으면 ID를 보존하고, 누락된 항목은 제거된다.
 * 여러 호출을 하나의 작업으로 묶을 때는 같은 SiteMutationContext와 바깥
 * Database::transaction()을 사용한다.
 */
interface BlockMutationInterface
{
    public function getRow(int $domainId, int $rowId): ?BlockRowSnapshot;

    /** @return list<BlockRowSnapshot> */
    public function listPositionRows(
        int $domainId,
        string $position,
        ?string $menuCode = null
    ): array;

    /** @return list<BlockRowSnapshot> */
    public function listPageRows(int $domainId, int $pageId): array;

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $columns
     */
    public function createRow(
        int $domainId,
        array $row,
        array $columns,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    /**
     * columns가 null이면 기존 칸을 보존하고, 빈 배열이면 모든 칸을 제거한다.
     *
     * @param array<string, mixed> $rowPatch
     * @param list<array<string, mixed>>|null $columns
     */
    public function updateRow(
        int $domainId,
        int $rowId,
        array $rowPatch,
        ?array $columns,
        int $expectedRevision,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    public function setRowActive(
        int $domainId,
        int $rowId,
        bool $active,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    public function moveRow(
        int $domainId,
        int $rowId,
        ?string $targetPosition,
        ?int $targetPageId,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    /**
     * 같은 슬롯의 모든 행 ID를 빠짐없이 지정해야 한다.
     *
     * @param list<int> $orderedRowIds
     */
    public function reorderRows(
        int $domainId,
        array $orderedRowIds,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    public function removeRow(
        int $domainId,
        int $rowId,
        ?SiteMutationContext $mutationContext = null
    ): Result;
}
