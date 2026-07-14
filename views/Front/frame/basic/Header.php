<?php
/**
 * Mublo Front Header
 *
 * 구조: 로고(이미지/텍스트) + GNB + 검색(PC) + 유틸리티(PC) + 모바일 토글
 * 모바일 패널: 검색 + GNB(세로) + 유틸리티
 *
 * 검색·로그인 영역·유틸리티 메뉴는 공용 컴포넌트(views/Components/frame/*)로
 * 렌더한다 — 프레임 템플릿 슬롯과 공유. 메인 메뉴는 기존 menu 컴포넌트.
 *
 * @var array $mublo Front View 데이터 계약
 */

$siteConfig = $mublo['site']['config'];
$siteImages = $mublo['site']['images'];
$navigation = $mublo['navigation'];
$viewer = $mublo['viewer'];
$siteName = htmlspecialchars(trim($siteConfig['site_title'] ?? '') ?: 'MUBLO');
$pcLogo   = $siteImages['logo_pc'] ?? '';
$moLogo   = $siteImages['logo_mobile'] ?? '';
$menus    = $navigation['menuTree'] ?? [];
$isLogin  = !empty($viewer['authenticated']);
$activeMenuCode = $navigation['currentMenuCode'] ?? '';

// visibility 필터링 (guest/member/all)
$visibilityFilter = function ($item) use ($isLogin) {
    $vis = $item['visibility'] ?? 'all';
    if ($vis === 'guest') return !$isLogin;
    if ($vis === 'member') return $isLogin;
    return true;
};

// 메인 메뉴 트리 visibility 필터링 (재귀)
$filterMenuTree = function (array $items) use (&$filterMenuTree, $visibilityFilter): array {
    $filtered = [];
    foreach ($items as $item) {
        if (!$visibilityFilter($item)) {
            continue;
        }
        if (!empty($item['children'])) {
            $item['children'] = $filterMenuTree($item['children']);
        }
        $filtered[] = $item;
    }
    return $filtered;
};
$menus = $filterMenuTree($menus);

$utilityData = [
    'utilityMenus' => $navigation['utilityMenus'] ?? [],
    'viewer' => $viewer,
];
?>

<header class="mublo-header">
    <div class="mublo-container">
        <div class="mublo-header__inner">

            <!-- 로고 -->
            <div class="mublo-header__logo">
                <a href="/" class="logo-link">
                    <?php if ($pcLogo && $moLogo): ?>
                    <img src="<?= htmlspecialchars($pcLogo) ?>" alt="<?= $siteName ?>" class="logo-img logo-pc">
                    <img src="<?= htmlspecialchars($moLogo) ?>" alt="<?= $siteName ?>" class="logo-img logo-mobile">
                    <?php elseif ($pcLogo): ?>
                    <img src="<?= htmlspecialchars($pcLogo) ?>" alt="<?= $siteName ?>" class="logo-img">
                    <?php elseif ($moLogo): ?>
                    <img src="<?= htmlspecialchars($moLogo) ?>" alt="<?= $siteName ?>" class="logo-img">
                    <?php else: ?>
                    <span class="logo-text--main"><?= $siteName ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- GNB (PC) -->
            <nav class="mublo-header__nav" id="main-nav">
                <div class="nav-wrapper">
                    <?= $this->menu($menus, $activeMenuCode) ?>
                </div>
            </nav>

            <!-- 검색 (PC) -->
<?= $this->component('frame/search') ?>

            <!-- 유틸리티 메뉴 (PC) -->
<?= $this->component('frame/login_area', ['viewer' => $viewer]) ?>

<?= $this->component('frame/menu_utility', $utilityData) ?>

            <!-- 모바일 토글 -->
            <button class="mublo-header__toggle" id="mubloPanelToggle" aria-label="메뉴 열기">
                <span class="line"></span>
                <span class="line"></span>
                <span class="line"></span>
            </button>

        </div>
    </div>
</header>

<?= $this->component('frame/mobile_panel', $utilityData + ['menus' => $menus, 'activeMenuCode' => $activeMenuCode]) ?>
