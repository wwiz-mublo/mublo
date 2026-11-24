<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockImageCleanupInterface;

/** 확장이 보존을 끝낸 블록 이미지를 관리자 저장과 같은 참조·보존 규칙으로 정리한다. */
final class BlockImageCleanupGateway implements BlockImageCleanupInterface
{
    public function __construct(private readonly BlockImageProcessor $images)
    {
    }

    public function cleanupUnreferenced(int $domainId, array $imageUrls): int
    {
        if ($domainId <= 0) {
            return 0;
        }
        $prefix = '/storage/D' . $domainId . '/';
        $owned = array_values(array_filter(
            $imageUrls,
            static fn(mixed $url): bool => is_string($url) && str_starts_with($url, $prefix) && !str_contains($url, '..')
        ));
        return $owned === [] ? 0 : $this->images->cleanupUnreferenced($owned);
    }
}
