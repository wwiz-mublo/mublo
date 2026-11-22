<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

/** 사이트 편집기와 확장이 게시판 그룹을 안전하게 조회하는 안정 계약. */
interface BoardGroupQueryInterface
{
    /** @return list<BoardGroupDescriptor> */
    public function listGroups(int $domainId): array;

    public function getGroup(int $domainId, int $groupId): ?BoardGroupDescriptor;

    public function findGroupBySlug(int $domainId, string $slug): ?BoardGroupDescriptor;
}
