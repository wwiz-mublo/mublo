<?php
/**
 * Block Skin: menu/basic
 *
 * 메뉴 기본 스킨
 *
 * MubloItemLayout 비적용: 단일 콘텐츠 블록
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var array $menuTree 메뉴 트리 배열
 * @var string $orientation horizontal|vertical
 * @var int $maxDepth 최대 깊이
 */

/**
 * 메뉴 HTML 빌드 (재귀)
 */
function buildBlockMenuHtml(array $items, int $maxDepth, int $currentDepth): string
{
    $html = '';

    foreach ($items as $item) {
        $title = htmlspecialchars($item['label'] ?? '');
        // URL 없음(NULL/빈값/'#') = 클릭 불가 라벨. 하위만 여는 부모 메뉴 용도.
        $rawUrl = $item['url'] ?? null;
        $hasUrl = $rawUrl !== null && $rawUrl !== '' && $rawUrl !== '#';
        $url = htmlspecialchars((string) $rawUrl);
        $target = $item['target'] ?? '_self';
        $hasChildren = !empty($item['children']) && $currentDepth < $maxDepth;

        $liClass = $hasChildren ? 'block-menu__item--has-children' : '';
        $childHtml = '';

        if ($hasChildren) {
            $nextDepth = $currentDepth + 1;
            $childHtml = '<ul class="block-menu__list block-menu__list--depth' . $nextDepth . '">'
                . buildBlockMenuHtml($item['children'], $maxDepth, $nextDepth)
                . '</ul>';
        }

        $linkHtml = $hasUrl
            ? '<a href="' . $url . '" target="' . $target . '" class="block-menu__link">' . $title . '</a>'
            : '<span class="block-menu__link block-menu__link--nolink">' . $title . '</span>';

        $html .= <<<HTML
<li class="block-menu__item {$liClass}">
    {$linkHtml}
    {$childHtml}
</li>
HTML;
    }

    return $html;
}

$orientationClass = ($orientation ?? 'horizontal') === 'vertical'
    ? 'block-menu--vertical'
    : 'block-menu--horizontal';
?>
<div class="block-menu block-menu--basic <?= $orientationClass ?>">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <nav class="block-menu__nav block-body">
        <ul class="block-menu__list block-menu__list--depth1">
            <?= buildBlockMenuHtml($menuTree ?? [], $maxDepth ?? 2, 1) ?>
        </ul>
    </nav>
</div>
