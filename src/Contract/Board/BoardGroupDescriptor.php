<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

/** Board 패키지 Entity를 노출하지 않는 게시판 그룹 읽기 모델. */
final readonly class BoardGroupDescriptor
{
    /** @param array<string, mixed> $values 정규화된 게시판 그룹 설정 */
    public function __construct(
        public int $groupId,
        public string $slug,
        public string $name,
        public bool $active,
        public array $values
    ) {
    }
}
