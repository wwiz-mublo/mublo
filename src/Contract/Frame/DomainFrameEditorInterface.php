<?php
declare(strict_types=1);

namespace Mublo\Contract\Frame;

use Mublo\Core\Result\Result;

/** 확장이 도메인 프레임 초안을 시드하고 게시 상태를 읽는 좁은 계약. */
interface DomainFrameEditorInterface
{
    public function hasDraft(int $domainId, string $part): bool;

    public function saveDraft(
        int $domainId,
        string $part,
        string $html,
        string $css = '',
        string $js = '',
        ?string $seededFromSkin = null,
        ?int $updatedBy = null
    ): Result;

    /** @return string[] */
    public function getPublishedParts(array $themeConfig): array;
}
