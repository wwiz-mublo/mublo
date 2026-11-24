<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

/** change set의 자원 snapshot을 제품 내부 구현으로 안전하게 복원하는 경계. */
interface SiteEditorResourceRestoreHandlerInterface
{
    public function supportsResource(string $resourceType): bool;

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function validateRestore(array $snapshot, SiteEditContext $context): array;

    /** @param array<string, mixed> $snapshot */
    public function restore(array $snapshot, SiteEditContext $context): SiteEditorOperationResult;
}
