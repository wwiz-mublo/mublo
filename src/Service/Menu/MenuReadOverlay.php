<?php
declare(strict_types=1);

namespace Mublo\Service\Menu;

use Mublo\Contract\Menu\MenuReadOverlayInterface;
use Mublo\Contract\Menu\MenuTreeEntry;
use Mublo\Core\Result\Result;

/** 요청 메모리 안에서만 유지되는 메뉴 최종 트리 overlay. */
final class MenuReadOverlay implements MenuReadOverlayInterface
{
    private const MAX_TREE_DEPTH = 10;

    /** @var array<int, list<MenuTreeEntry>> */
    private array $trees = [];

    public function replaceTree(int $domainId, array $entries): Result
    {
        if ($domainId <= 0) {
            return Result::failure('미리보기 도메인 ID가 올바르지 않습니다.');
        }

        $normalized = [];
        $byPath = [];
        $seenNodeIds = [];
        foreach (array_values($entries) as $index => $entry) {
            if (!$entry instanceof MenuTreeEntry) {
                return Result::failure('메뉴 미리보기 항목 형식이 올바르지 않습니다.');
            }
            $nodeId = $entry->nodeId !== 0 ? $entry->nodeId : -($index + 1);
            if (isset($seenNodeIds[$nodeId])) {
                return Result::failure('메뉴 미리보기 노드 ID가 중복되었습니다.');
            }
            if (isset($byPath[$entry->pathCode])) {
                return Result::failure('메뉴 미리보기 경로가 중복되었습니다.');
            }
            if ($entry->menuCode === '' || $entry->pathCode === '' || $entry->label === '') {
                return Result::failure('메뉴 코드, 경로, 이름은 비워 둘 수 없습니다.');
            }
            if ($entry->depth < 1 || $entry->depth > self::MAX_TREE_DEPTH) {
                return Result::failure('메뉴 미리보기 깊이가 허용 범위를 벗어났습니다.');
            }
            $normalizedEntry = new MenuTreeEntry(
                $nodeId,
                $entry->menuCode,
                $entry->pathCode,
                $entry->pathName,
                $entry->parentCode,
                $entry->depth,
                $entry->sortOrder,
                $entry->label,
                $entry->url,
                $entry->icon,
                $entry->target,
                $entry->visibility,
                $entry->minLevel,
                $entry->requiredPermission,
                $entry->showOnPc,
                $entry->showOnMobile,
                $entry->isActive,
                $entry->providerType,
                $entry->providerName,
                $entry->pairCode
            );
            $normalized[] = $normalizedEntry;
            $byPath[$entry->pathCode] = $normalizedEntry;
            $seenNodeIds[$nodeId] = true;
        }

        foreach ($normalized as $entry) {
            if ($entry->parentCode === null) {
                if ($entry->depth !== 1 || $entry->pathCode !== $entry->menuCode || $entry->pathName !== $entry->label) {
                    return Result::failure('루트 메뉴 미리보기 경로가 올바르지 않습니다.');
                }
                continue;
            }
            $parent = $byPath[$entry->parentCode] ?? null;
            if ($parent === null
                || $entry->depth !== $parent->depth + 1
                || $entry->pathCode !== $parent->pathCode . '>' . $entry->menuCode
                || $entry->pathName !== $parent->pathName . ' > ' . $entry->label
            ) {
                return Result::failure('하위 메뉴 미리보기 경로가 올바르지 않습니다.');
            }
        }

        usort($normalized, static fn(MenuTreeEntry $a, MenuTreeEntry $b): int =>
            [$a->depth, $a->sortOrder, $a->nodeId] <=> [$b->depth, $b->sortOrder, $b->nodeId]
        );
        $this->trees[$domainId] = $normalized;
        return Result::success('메뉴 트리 미리보기를 등록했습니다.');
    }

    public function tree(int $domainId, bool $activeOnly = true): ?array
    {
        if (!array_key_exists($domainId, $this->trees)) {
            return null;
        }
        if (!$activeOnly) {
            return $this->trees[$domainId];
        }
        return array_values(array_filter(
            $this->trees[$domainId],
            static fn(MenuTreeEntry $entry): bool => $entry->isActive
        ));
    }

    public function clear(): void
    {
        $this->trees = [];
    }
}
