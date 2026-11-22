<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

/** operation의 공개 result와 복원 가능한 자원 변경 목록을 분리해 운반한다. */
final readonly class SiteEditorOperationResult
{
    /**
     * @param array<string, mixed> $result 도구 result_schema로 외부에 공개할 값
     * @param list<array<string, mixed>> $resourceChanges before/after/revision/fingerprint/restore_guard
     * @param list<string> $warnings
     */
    public function __construct(
        public array $result,
        public array $resourceChanges = [],
        public array $warnings = []
    ) {
        foreach ($resourceChanges as $change) {
            if (!is_array($change)
                || !is_string($change['resource_id'] ?? null)
                || !is_string($change['resource_type'] ?? null)) {
                throw new \InvalidArgumentException('Invalid site editor resource change.');
            }
        }
        foreach ($warnings as $warning) {
            if (!is_string($warning) || $warning === '') {
                throw new \InvalidArgumentException('Invalid site editor operation warning.');
            }
        }
    }
}
