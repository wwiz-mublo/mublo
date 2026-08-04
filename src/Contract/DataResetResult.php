<?php
declare(strict_types=1);

namespace Mublo\Contract;

/**
 * 하나의 데이터 초기화 작업 결과입니다.
 */
final readonly class DataResetResult
{
    public function __construct(
        public int $tablesCleared = 0,
        public int $filesDeleted = 0,
        public string $details = ''
    ) {
    }

    /** @return array{tables_cleared: int, files_deleted: int, details: string} */
    public function toArray(): array
    {
        return [
            'tables_cleared' => $this->tablesCleared,
            'files_deleted' => $this->filesDeleted,
            'details' => $this->details,
        ];
    }
}
