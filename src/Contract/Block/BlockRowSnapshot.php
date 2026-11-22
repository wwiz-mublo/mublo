<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/**
 * 행과 그 칸·스택 콘텐츠를 함께 운반하는 안정 읽기 모델.
 *
 * 배열은 코어의 portable snapshot 형식이며 column_id/content_id를 유지한다.
 * 따라서 읽은 값을 수정해 다시 저장해도 기존 칸·콘텐츠 ID가 불필요하게
 * 재발급되지 않는다.
 */
final readonly class BlockRowSnapshot
{
    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $columns
     */
    public function __construct(
        public int $rowId,
        public int $domainId,
        public int $revision,
        public array $row,
        public array $columns
    ) {
    }
}
