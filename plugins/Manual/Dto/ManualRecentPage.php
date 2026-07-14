<?php

namespace Mublo\Plugin\Manual\Dto;

/**
 * 최근 수정 매뉴얼 페이지 블록용 읽기 전용 모델.
 */
final readonly class ManualRecentPage
{
    public function __construct(
        public int $pageId,
        public string $pageTitle,
        public string $pageSlug,
        public string $bookTitle,
        public string $bookSlug,
        public ?string $content,
        public string $updatedAt,
    ) {
    }
}
