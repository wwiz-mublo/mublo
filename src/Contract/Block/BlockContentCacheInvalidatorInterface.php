<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/** 확장 콘텐츠 변경을 관련 블록 행 캐시 무효화로 연결하는 안정 Contract. */
interface BlockContentCacheInvalidatorInterface
{
    public function invalidateByContentType(int $domainId, string $contentType): void;

    public function invalidateByContentItem(
        int $domainId,
        string $contentType,
        int|string $itemId
    ): void;

    public function invalidateDomain(int $domainId): void;
}
