<?php
declare(strict_types=1);

namespace Mublo\Contract\Manual;

/**
 * 매뉴얼 페이지 트리 노드 — 자식과 본문을 포함하는 프론트 열람용 읽기 전용 DTO.
 *
 * 트리 전체를 한 페이지에 렌더하므로 각 노드가 본문(content)을 함께 담는다.
 */
final readonly class ManualPageNode
{
    /**
     * @param list<ManualPageNode> $children
     */
    public function __construct(
        public int $pageId,
        public ?int $parentId,
        public string $title,
        public string $slug,
        public int $depth,
        public int $sortOrder,
        public ?string $content,
        public array $children,
    ) {
    }
}
