<?php
declare(strict_types=1);

namespace Mublo\Service\Board;

use Mublo\Contract\Board\BoardGroupDescriptor;
use Mublo\Contract\Board\BoardGroupReadOverlayInterface;
use Mublo\Core\Result\Result;

/** 요청 메모리 안에서만 유지되는 게시판 그룹 overlay. */
final class BoardGroupReadOverlay implements BoardGroupReadOverlayInterface
{
    /** @var array<int, list<BoardGroupDescriptor>> */
    private array $domains = [];

    public function replaceGroups(int $domainId, array $groups): Result
    {
        if ($domainId <= 0) {
            return Result::failure('미리보기 도메인 ID가 올바르지 않습니다.');
        }
        $normalized = [];
        $ids = [];
        $slugs = [];
        foreach (array_values($groups) as $index => $group) {
            if (!$group instanceof BoardGroupDescriptor) {
                return Result::failure('게시판 그룹 미리보기 항목 형식이 올바르지 않습니다.');
            }
            $slug = trim($group->slug);
            $name = trim($group->name);
            if ($group->groupId === 0 || $slug === '' || $name === '') {
                return Result::failure('게시판 그룹 ID, 슬러그, 이름은 필수입니다.');
            }
            if (preg_match('/^[a-z0-9_-]+$/', $slug) !== 1) {
                return Result::failure('게시판 그룹 미리보기 슬러그 형식이 올바르지 않습니다.');
            }
            if (isset($ids[$group->groupId]) || isset($slugs[$slug])) {
                return Result::failure('게시판 그룹 미리보기 ID 또는 슬러그가 중복되었습니다.');
            }
            $ids[$group->groupId] = true;
            $slugs[$slug] = true;
            $values = $group->values;
            $values['group_id'] = $group->groupId;
            $values['domain_id'] = $domainId;
            $values['group_slug'] = $slug;
            $values['group_name'] = $name;
            $values['is_active'] = $group->active ? 1 : 0;
            // 목록 순서가 표시 순서다
            $values['sort_order'] = $index + 1;
            $normalized[] = new BoardGroupDescriptor($group->groupId, $slug, $name, $group->active, $values);
        }
        $this->domains[$domainId] = $normalized;
        return Result::success();
    }

    public function groups(int $domainId, bool $activeOnly = false): ?array
    {
        if (!isset($this->domains[$domainId])) {
            return null;
        }
        return $activeOnly
            ? array_values(array_filter($this->domains[$domainId], static fn(BoardGroupDescriptor $group): bool => $group->active))
            : $this->domains[$domainId];
    }

    public function findBySlug(int $domainId, string $slug): ?BoardGroupDescriptor
    {
        foreach ($this->domains[$domainId] ?? [] as $group) {
            if ($group->slug === $slug) {
                return $group;
            }
        }
        return null;
    }

    public function findById(int $domainId, int $groupId): ?BoardGroupDescriptor
    {
        foreach ($this->domains[$domainId] ?? [] as $group) {
            if ($group->groupId === $groupId) {
                return $group;
            }
        }
        return null;
    }

    public function clear(): void
    {
        $this->domains = [];
    }
}
