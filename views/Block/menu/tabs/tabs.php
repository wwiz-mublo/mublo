<?php
/**
 * Block Skin: menu/tabs
 *
 * 가로 탭 내비 — 서브페이지 비주얼 헤더 아래에 두는 2차 메뉴 탭 줄.
 * scope=current 와 함께 쓰는 것을 전제로 설계했지만(1차 하위가 탭으로),
 * scope=all 이면 1차 메뉴가 탭으로 나온다.
 *
 * 현재 항목(is_active_trail)에 하위가 있고 maxDepth 가 허용하면
 * 탭 아래 보조 줄(3차)을 렌더한다.
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 * @var array $menuTree 탭으로 나열할 항목들 (is_active/is_active_trail 주석 포함)
 * @var int $maxDepth 최대 깊이
 * @var string|null $sectionLabel scope=current 일 때 1차 라벨
 * @var string|null $activeCode
 */

$menuTree = $menuTree ?? [];
$maxDepth = (int) ($maxDepth ?? 2);
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// URL 없음(NULL/빈값/'#') = 클릭 불가 라벨. <a> 대신 <span> 으로 렌더한다.
$hasUrl = static fn(array $item): bool => ($item['url'] ?? '') !== '' && ($item['url'] ?? '') !== '#';

if ($assets) {
    $assets->addCss('/serve/block/menu/tabs/style.css');
    $assets->addJs('/serve/block/menu/tabs/script.js');
}

// 현재 트레일에 있는 항목의 하위(보조 줄) 추출
$subRow = [];
if ($maxDepth >= 2) {
    foreach ($menuTree as $item) {
        if (!empty($item['is_active_trail']) && !empty($item['children'])) {
            $subRow = $item['children'];
            break;
        }
    }
}
?>

<div class="block-menu block-menu--tabs" data-menu-tabs>
    <?php include $titlePartial; ?>

    <nav class="bmt-nav block-body" aria-label="섹션 메뉴">
        <ul class="bmt-row bmt-row--main">
            <?php foreach ($menuTree as $item): ?>
            <li>
                <?php if ($hasUrl($item)): ?>
                <a href="<?= $e($item['url']) ?>" target="<?= $e($item['target'] ?? '_self') ?>"
                   class="bmt-link<?= !empty($item['is_active_trail']) ? ' is-active' : '' ?>">
                    <?= $e($item['label'] ?? '') ?>
                </a>
                <?php else: ?>
                <span class="bmt-link bmt-link--nolink<?= !empty($item['is_active_trail']) ? ' is-active' : '' ?>">
                    <?= $e($item['label'] ?? '') ?>
                </span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($subRow !== []): ?>
        <ul class="bmt-row bmt-row--sub">
            <?php foreach ($subRow as $item): ?>
            <li>
                <?php if ($hasUrl($item)): ?>
                <a href="<?= $e($item['url']) ?>" target="<?= $e($item['target'] ?? '_self') ?>"
                   class="bmt-link bmt-link--sub<?= !empty($item['is_active_trail']) ? ' is-active' : '' ?>">
                    <?= $e($item['label'] ?? '') ?>
                </a>
                <?php else: ?>
                <span class="bmt-link bmt-link--sub bmt-link--nolink<?= !empty($item['is_active_trail']) ? ' is-active' : '' ?>">
                    <?= $e($item['label'] ?? '') ?>
                </span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </nav>
</div>
