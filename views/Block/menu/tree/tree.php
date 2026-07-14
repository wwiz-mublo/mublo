<?php
/**
 * Block Skin: menu/tree
 *
 * 좌측 LNB 트리 — 2단 레이아웃 서브페이지의 사이드 내비.
 * scope=current 와 함께 쓰면 1차 제목 + 2·3차 트리가 나오고(전형적 LNB),
 * scope=all 이면 전체 메뉴 트리가 나온다.
 *
 * 현재 트레일(is_active_trail)의 가지만 펼친다.
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 * @var array $menuTree 트리 항목들 (is_active/is_active_trail 주석 포함)
 * @var int $maxDepth 최대 깊이
 * @var string|null $sectionLabel scope=current 일 때 1차 라벨
 * @var string|null $sectionUrl scope=current 일 때 1차 URL
 */

$menuTree = $menuTree ?? [];
$maxDepth = (int) ($maxDepth ?? 2);
$sectionLabel = $sectionLabel ?? null;
$sectionUrl = $sectionUrl ?? null;
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($assets) {
    $assets->addCss('/serve/block/menu/tree/style.css');
    $assets->addJs('/serve/block/menu/tree/script.js');
}

$buildTree = function (array $items, int $currentDepth) use (&$buildTree, $maxDepth, $e): string {
    $html = '';

    foreach ($items as $item) {
        $hasChildren = !empty($item['children']) && $currentDepth < $maxDepth;
        $inTrail = !empty($item['is_active_trail']);

        $liClasses = ['bmtr-item'];
        if ($hasChildren) {
            $liClasses[] = 'bmtr-item--branch';
        }
        if ($inTrail) {
            $liClasses[] = 'is-open';
        }

        $childHtml = $hasChildren
            ? '<ul class="bmtr-list bmtr-list--depth' . ($currentDepth + 1) . '">'
                . $buildTree($item['children'], $currentDepth + 1)
                . '</ul>'
            : '';

        // URL 없음(NULL/빈값/'#') = 클릭 불가 라벨. <a> 대신 <span> 으로 렌더한다.
        $url = $item['url'] ?? '';
        $activeClass = !empty($item['is_active']) ? ' is-active' : '';
        $linkHtml = ($url !== '' && $url !== '#')
            ? '<a href="' . $e($url) . '" target="' . $e($item['target'] ?? '_self') . '"'
                . ' class="bmtr-link' . $activeClass . '">' . $e($item['label'] ?? '') . '</a>'
            : '<span class="bmtr-link bmtr-link--nolink' . $activeClass . '">'
                . $e($item['label'] ?? '') . '</span>';

        $html .= '<li class="' . implode(' ', $liClasses) . '">'
            . $linkHtml
            . $childHtml
            . '</li>';
    }

    return $html;
};
?>

<div class="block-menu block-menu--tree" data-menu-tree>
    <?php include $titlePartial; ?>

    <nav class="bmtr-nav block-body" aria-label="섹션 메뉴">
        <?php if ($sectionLabel !== null && $sectionLabel !== ''): ?>
        <div class="bmtr-head">
            <?php if ($sectionUrl): ?>
            <a href="<?= $e($sectionUrl) ?>"><?= $e($sectionLabel) ?></a>
            <?php else: ?>
            <span><?= $e($sectionLabel) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <ul class="bmtr-list bmtr-list--depth1">
            <?= $buildTree($menuTree, 1) ?>
        </ul>
    </nav>
</div>
