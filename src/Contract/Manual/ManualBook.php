<?php

namespace Mublo\Contract\Manual;

/**
 * 매뉴얼 책 요약 — 프론트에 노출하는 읽기 전용 DTO.
 */
final readonly class ManualBook
{
    public function __construct(
        public int $bookId,
        public string $title,
        public string $slug,
        public ?string $description,
        public int $sortOrder,
    ) {
    }
}
