<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

final readonly class BlockColumnNormalizationResult
{
    /**
     * @param array<int, array<string, mixed>> $normalizedColumns
     * @param array<int, array{column_index: int, field: string, code: string, message: string}> $errors
     * @param array<int, array{column_index: int, field: string, code: string, message: string}> $warnings
     */
    public function __construct(
        private array $normalizedColumns,
        private array $errors = [],
        private array $warnings = []
    ) {
    }

    public function isOk(): bool
    {
        return $this->errors === [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getNormalizedColumns(): array
    {
        return $this->normalizedColumns;
    }

    /** @return array<int, array{column_index: int, field: string, code: string, message: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<int, array{column_index: int, field: string, code: string, message: string}> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getFirstErrorMessage(string $fallback = '칸 데이터가 올바르지 않습니다.'): string
    {
        return $this->errors[0]['message'] ?? $fallback;
    }
}
