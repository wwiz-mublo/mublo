<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

/** 게시판 패키지 Entity를 노출하지 않는 사이트 편집용 읽기 모델. */
final readonly class BoardDescriptor
{
    /**
     * @param array<string, mixed> $values 정규화된 게시판 설정
     * @param list<int> $categoryIds 연결된 활성 카테고리 ID
     */
    public function __construct(
        public int $boardId,
        public string $slug,
        public string $name,
        public int $groupId,
        public bool $active,
        public int $articleCount,
        public array $values,
        public array $categoryIds
    ) {
    }

    public function isInUse(): bool
    {
        return $this->articleCount > 0;
    }

    public function canDelete(): bool
    {
        return !$this->isInUse();
    }
}
