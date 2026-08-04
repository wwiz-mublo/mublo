<?php
declare(strict_types=1);
namespace Mublo\Core\Block\Renderer;

use Mublo\Contract\Block\BlockColumnView;
use Mublo\Repository\Menu\MenuTreeRepository;

/**
 * MenuRenderer
 *
 * 메뉴 콘텐츠 렌더러
 *
 * content_config:
 * - depth: 최대 깊이 (기본 2)
 * - orientation: horizontal|vertical
 * - scope: 'all'(기본 — 전체 트리) | 'current'(현재 URL 이 속한 1차 메뉴의
 *   하위 트리만 — 서브페이지의 탭/LNB 내비용). 'current' 인데 현재 위치를
 *   못 찾거나 하위 메뉴가 없으면 **프론트에서는 아무것도 출력하지 않는다**
 *   (빈 탭바 방지 — 메뉴가 얕은 사이트에 킷을 적용해도 조용히 접힌다.
 *   블록 에디터 미리보기에서만 자리 표시).
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumnView 엔티티
 * - $menuTree: 메뉴 트리 배열 (scope=current 면 해당 1차의 children).
 *   각 항목에 is_active(현재 페이지)·is_active_trail(현재 페이지의 조상) 주석
 * - $orientation: horizontal|vertical
 * - $maxDepth: 최대 깊이
 * - $scope: 'all'|'current'
 * - $sectionLabel / $sectionUrl: scope=current 일 때 1차 메뉴의 라벨/URL (아니면 null)
 * - $activeCode / $currentPath: 현재 항목 menu_code(없으면 null) / 현재 경로
 *
 * ## 캐시 제약 (scope=current · active 판정)
 *
 * scope=current 는 출력 트리가, scope=all 도 active 표시가 현재 URL에 의존한다.
 * 따라서 menu 콘텐츠 타입은 BlockRegistry에서 행 HTML 캐시를 비활성화한다.
 * 메뉴 데이터 조회 자체는 아래 요청 내 메모이제이션으로 도메인당 한 번만 수행한다.
 */
class MenuRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private MenuTreeRepository $treeRepository;

    public function __construct(MenuTreeRepository $treeRepository)
    {
        $this->treeRepository = $treeRepository;
    }

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'menu';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumnView $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $skin = $column->getContentSkin() ?: 'basic';
        $depth = (int) ($config['depth'] ?? 2);
        $orientation = $config['orientation'] ?? 'horizontal';
        $scope = ($config['scope'] ?? 'all') === 'current' ? 'current' : 'all';

        // 메뉴 데이터 조회
        $menuItems = $this->getMenuItems($column->getDomainId());

        if (empty($menuItems)) {
            return $this->renderEmptyContent('메뉴가 없습니다.');
        }

        // 트리 구조로 변환 + 현재 위치 주석(is_active / is_active_trail)
        $menuTree = $this->buildTree($menuItems);
        $currentPath = $this->currentPath();
        $activeCode = $this->findActiveCode($menuTree, $currentPath);
        $this->markActiveTrail($menuTree, $activeCode);

        // 운영자가 아이템 선택(content_items)으로 고른 1차 메뉴만 노출.
        // scope=current 는 이 필터가 적용된 트리 안에서 동작한다.
        $selectedCodes = $this->selectedCodes($column->getContentItems());
        if ($selectedCodes !== []) {
            $menuTree = array_filter(
                $menuTree,
                static fn($item, $code) => in_array((string) $code, $selectedCodes, true),
                ARRAY_FILTER_USE_BOTH
            );

            if (empty($menuTree)) {
                return is_editor_preview()
                    ? '<div class="block-placeholder"><i class="bi bi-list-ul block-placeholder__icon"></i>'
                        . '<span>선택된 메뉴가 현재 메뉴 트리에 없습니다 — 아이템 선택을 확인하세요.</span></div>'
                    : '';
            }
        }

        $sectionLabel = null;
        $sectionUrl = null;

        if ($scope === 'current') {
            $section = $this->findCurrentSection($menuTree, $activeCode);

            // 자동 숨김 — 현재 위치를 못 찾았거나 이 1차에 하위 메뉴가 없으면
            // 프론트에서는 출력하지 않는다(빈 탭바 방지). 편집자는 블록이 있는지
            // 알아야 하므로 에디터 미리보기에서만 자리 표시를 그린다.
            if ($section === null || empty($section['children'])) {
                return is_editor_preview()
                    ? '<div class="block-placeholder"><i class="bi bi-list-ul block-placeholder__icon"></i>'
                        . '<span>현재 위치 하위 메뉴 — 하위 메뉴가 없는 페이지에서는 프론트에 표시되지 않습니다.</span></div>'
                    : '';
            }

            $menuTree = $section['children'];
            $sectionLabel = (string) ($section['label'] ?? '');
            $sectionUrl = (string) ($section['url'] ?? '');
        }

        return $this->renderSkin($column, $skin, [
            'menuTree' => $menuTree,
            'orientation' => $orientation,
            'maxDepth' => $depth,
            'scope' => $scope,
            'sectionLabel' => $sectionLabel,
            'sectionUrl' => $sectionUrl,
            'activeCode' => $activeCode,
            'currentPath' => $currentPath,
        ]);
    }

    /**
     * 현재 요청 경로 (쿼리스트링 제외, 끝 슬래시 정규화)
     *
     * 렌더러는 요청 객체를 주입받지 않으므로 렌더 시점의 슈퍼글로벌을 읽는다 —
     * 캐시와의 상호작용은 클래스 독블록의 "캐시 제약" 참고.
     */
    private function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return $this->normalizePath(strtok($uri, '?') ?: '/');
    }

    private function normalizePath(string $url): string
    {
        // 절대 URL 로 등록된 메뉴(외부 링크 포함)도 경로만 비교한다
        if (str_contains($url, '://')) {
            $url = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        }

        $url = rtrim($url, '/');

        return $url === '' ? '/' : $url;
    }

    /**
     * 현재 경로와 가장 잘 맞는 메뉴 항목의 menu_code
     *
     * 정확 일치가 최우선, 없으면 경로 경계 기준 최장 접두 일치
     * (/board/notice 는 /board/notice/123 에 일치, /board/no 에는 불일치).
     */
    private function findActiveCode(array $tree, string $currentPath): ?string
    {
        $best = null;
        $bestScore = 0;

        $walk = function (array $items) use (&$walk, &$best, &$bestScore, $currentPath): void {
            foreach ($items as $item) {
                $url = $this->normalizePath((string) ($item['url'] ?? ''));
                $code = (string) ($item['menu_code'] ?? '');

                if ($code !== '' && $url !== '/') {
                    if ($url === $currentPath) {
                        $score = strlen($url) * 2 + 1; // 정확 일치 우선
                    } elseif (str_starts_with($currentPath, $url . '/')) {
                        $score = strlen($url);
                    } else {
                        $score = 0;
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $code;
                    }
                }

                if (!empty($item['children'])) {
                    $walk($item['children']);
                }
            }
        };
        $walk($tree);

        return $best;
    }

    /**
     * 트리에 is_active / is_active_trail 을 주석한다.
     *
     * @return bool 이 서브트리에 현재 항목이 포함되는가
     */
    private function markActiveTrail(array &$items, ?string $activeCode): bool
    {
        $contains = false;

        foreach ($items as &$item) {
            $isActive = $activeCode !== null && ($item['menu_code'] ?? '') === $activeCode;
            $childContains = !empty($item['children'])
                ? $this->markActiveTrail($item['children'], $activeCode)
                : false;

            $item['is_active'] = $isActive;
            $item['is_active_trail'] = $isActive || $childContains;

            $contains = $contains || $item['is_active_trail'];
        }
        unset($item);

        return $contains;
    }

    /**
     * 현재 항목이 속한 1차 메뉴 (scope=current 의 기준점)
     */
    private function findCurrentSection(array $tree, ?string $activeCode): ?array
    {
        if ($activeCode === null) {
            return null;
        }

        foreach ($tree as $item) {
            if (!empty($item['is_active_trail'])) {
                return $item;
            }
        }

        return null;
    }

    /** 요청 스코프 메모: 한 페이지에 메뉴 칸이 여러 개여도 트리 조회는 도메인당 1회 */
    private static array $menuItemsMemo = [];

    /**
     * 메뉴 아이템 조회 (요청 내 메모이제이션)
     */
    private function getMenuItems(int $domainId): array
    {
        if (array_key_exists($domainId, self::$menuItemsMemo)) {
            return self::$menuItemsMemo[$domainId];
        }

        try {
            return self::$menuItemsMemo[$domainId] = $this->treeRepository->findTreeWithItems($domainId, true);
        } catch (\Throwable $e) {
            return self::$menuItemsMemo[$domainId] = [];
        }
    }

    /**
     * 플랫 배열을 트리 구조로 변환
     */
    /**
     * 운영자 선택 아이템(content_items) → 메뉴 코드 목록.
     * 저장 형식이 [{id,label}] 배열 또는 스칼라 목록 양쪽일 수 있어 모두 수용.
     *
     * @return string[]
     */
    private function selectedCodes(?array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $codes = [];
        foreach ($items as $item) {
            $code = is_array($item) ? ($item['id'] ?? '') : $item;
            if ((string) $code !== '') {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    private function buildTree(array $items): array
    {
        $tree = [];
        $itemsByCode = [];

        foreach ($items as $item) {
            $code = $item['menu_code'] ?? '';
            $itemsByCode[$code] = $item;
            $itemsByCode[$code]['children'] = [];
        }

        foreach ($itemsByCode as $code => &$item) {
            $parentCode = $item['parent_code'] ?? null;

            if (empty($parentCode)) {
                $tree[$code] = &$item;
            } else {
                if (isset($itemsByCode[$parentCode])) {
                    $itemsByCode[$parentCode]['children'][$code] = &$item;
                }
            }
        }

        return $tree;
    }
}
