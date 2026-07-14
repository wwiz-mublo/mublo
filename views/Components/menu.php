<?php
/**
 * Menu Component
 *
 * 메뉴 트리를 재귀적으로 렌더링하는 공유 컴포넌트.
 * HTML 구조만 출력하며, 스타일링은 스킨 CSS에서 처리.
 *
 * @var array $menus 계층형 메뉴 트리 (buildHierarchy 결과)
 * @var string $activeCode 현재 활성 메뉴 코드 (선택)
 *
 * 메뉴 항목 필드:
 *   - label: string 메뉴명
 *   - url: string|null 링크 URL (NULL/빈값/'#' 이면 클릭 불가 라벨로 렌더)
 *   - target: string '_self'|'_blank'
 *   - depth: int 계층 깊이 (1~)
 *   - menu_code: string 메뉴 고유 코드
 *   - children: array 하위 메뉴
 *
 * 사용법:
 *   <?= $this->component('menu', ['menus' => $menuTree]) ?>
 *   <?= $this->component('menu', ['menus' => $menuTree, 'activeCode' => 'xK9mL3nR']) ?>
 */

$menus = $menus ?? [];
$activeCode = $activeCode ?? '';

if (empty($menus)) {
    return;
}

if (!function_exists('wzFindActivePath')) {
/**
 * 활성 메뉴 코드까지의 경로(루트→활성) 반환
 *
 * @return string[] menu_code 배열 (루트부터 활성 항목까지)
 */
function wzFindActivePath(array $items, string $activeCode): array
{
    foreach ($items as $item) {
        $code = $item['menu_code'] ?? '';
        if ($code === $activeCode) {
            return [$code];
        }
        if (!empty($item['children'])) {
            $path = wzFindActivePath($item['children'], $activeCode);
            if (!empty($path)) {
                return array_merge([$code], $path);
            }
        }
    }
    return [];
}
} // end function_exists wzFindActivePath

if (!function_exists('wzRenderMenuItems')) {
/**
 * 메뉴 항목을 재귀적으로 렌더링
 *
 * @param array  $items         메뉴 항목 배열
 * @param string $activeCode    현재 활성 메뉴 코드
 * @param array  $ancestorCodes 활성 메뉴의 조상 코드 목록
 */
function wzRenderMenuItems(array $items, string $activeCode, array $ancestorCodes = []): string
{
    $html = '';

    foreach ($items as $item) {
        $label = htmlspecialchars($item['label'] ?? '');
        // URL 없음(NULL/빈값/'#') = 클릭 불가 라벨. 하위 메뉴만 여는 부모 메뉴 용도.
        $rawUrl = $item['url'] ?? null;
        $hasUrl = $rawUrl !== null && $rawUrl !== '' && $rawUrl !== '#';
        $url = htmlspecialchars((string) $rawUrl);
        $target = $item['target'] ?? '_self';
        $depth = (int) ($item['depth'] ?? 1);
        $code = $item['menu_code'] ?? '';
        $hasChildren = !empty($item['children']);

        // CSS 클래스 조합
        $liClasses = "mublo-menu__item mublo-menu__item--depth-{$depth}";
        if ($hasChildren) {
            $liClasses .= ' mublo-menu__item--has-children';
        }
        if ($activeCode && $code === $activeCode) {
            // 현재 활성 메뉴
            $liClasses .= ' mublo-menu__item--active';
        } elseif (!empty($ancestorCodes) && in_array($code, $ancestorCodes, true)) {
            // 활성 메뉴의 상위(조상) — 드롭다운 열림, active 하이라이트 등에 활용
            $liClasses .= ' mublo-menu__item--ancestor';
        }

        $linkClasses = "mublo-menu__link mublo-menu__link--depth-{$depth}";
        if (!$hasUrl) {
            $linkClasses .= ' mublo-menu__link--nolink';
        }

        // target 속성
        $targetAttr = ($target === '_blank') ? ' target="_blank" rel="noopener"' : '';

        $html .= '<li class="' . $liClasses . '">';
        if ($hasUrl) {
            $html .= '<a href="' . $url . '" class="' . $linkClasses . '"' . $targetAttr . '>';
            $html .= '<span class="mublo-menu__label">' . $label . '</span>';
            $html .= '</a>';
        } else {
            // 링크 없는 메뉴는 <a> 자체를 만들지 않는다 (href="#" 점프 방지).
            $html .= '<span class="' . $linkClasses . '">';
            $html .= '<span class="mublo-menu__label">' . $label . '</span>';
            $html .= '</span>';
        }

        // 재귀: 하위 메뉴
        if ($hasChildren) {
            $childDepth = $depth + 1;
            $html .= '<ul class="mublo-menu__list mublo-menu__list--depth-' . $childDepth . '">';
            $html .= wzRenderMenuItems($item['children'], $activeCode, $ancestorCodes);
            $html .= '</ul>';
        }

        $html .= '</li>';
    }

    return $html;
}
} // end function_exists wzRenderMenuItems

// 활성 메뉴의 조상 코드 목록 계산 (활성 코드 자신은 제외)
$activePath = $activeCode ? wzFindActivePath($menus, $activeCode) : [];
$ancestorCodes = count($activePath) > 1 ? array_slice($activePath, 0, -1) : [];

$rootDepth = (int) (($menus[0]['depth'] ?? 1));
?>
<ul class="mublo-menu__list mublo-menu__list--depth-<?= $rootDepth ?>">
    <?= wzRenderMenuItems($menus, $activeCode, $ancestorCodes) ?>
</ul>
