<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/** 저장하지 않은 행 aggregate를 운영 화면과 같은 행 렌더러로 미리 본다. */
interface BlockMutationPreviewInterface
{
    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $columns
     */
    public function renderNewRow(int $domainId, array $row, array $columns): ?string;

    /**
     * columns가 null이면 저장된 칸을 사용한다.
     *
     * @param array<string, mixed> $rowPatch
     * @param list<array<string, mixed>>|null $columns
     */
    public function renderExistingRow(
        int $domainId,
        int $rowId,
        array $rowPatch,
        ?array $columns = null
    ): ?string;
}
