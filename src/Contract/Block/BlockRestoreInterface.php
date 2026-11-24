<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;

/**
 * 기록해 둔 블록 행을 되살리는 계약(사이트 편집 복원·테마 복원).
 *
 * {@see BlockMutationInterface}의 편집 쓰기는 AI·앱 편집 정책(직접 스크립트·include 금지)을 따르지만,
 * 복원은 몰이 스스로 기록한 원래 내용을 되돌리는 일이라 코어 리비전 복구와 같은 정책을 쓴다
 * (관리자가 만든 스크립트·include·비활성 확장 블록도 원래대로 되살린다).
 */
interface BlockRestoreInterface
{
    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $columns
     */
    public function restoreRow(int $domainId, array $row, array $columns, ?SiteMutationContext $mutationContext = null): Result;

    /**
     * 남아 있는 행의 내용을 기록한 값으로 되돌린다.
     *
     * @param array<string, mixed> $rowPatch
     * @param list<array<string, mixed>> $columns
     */
    public function restoreRowContent(
        int $domainId,
        int $rowId,
        array $rowPatch,
        array $columns,
        int $expectedRevision,
        ?SiteMutationContext $mutationContext = null
    ): Result;
}
