<?php
declare(strict_types=1);

namespace Mublo\Contract\Manual;

/**
 * 매뉴얼 페이지 단건 — slug 조회 결과의 읽기 전용 DTO.
 */
final readonly class ManualPageDetail
{
    public function __construct(
        public int $pageId,
        public int $bookId,
        public string $title,
        public string $slug,
        public ?string $content,
    ) {
    }
}
