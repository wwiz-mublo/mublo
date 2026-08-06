<?php
/**
 * Mypage 공통 레이아웃
 *
 * 사이드바 + 콘텐츠 영역.
 * 각 Mypage View에서 ob_start() 후 $content를 설정하고 include 합니다.
 *
 * @var array[] $mypageMenus     사이드바 메뉴 목록 (buildMenus()가 반환)
 * @var string  $currentSection  현재 활성 섹션 키
 * @var string  $content         렌더링할 콘텐츠 HTML
 * @var array|\Mublo\Contract\Auth\AuthenticatedUser|null $user 로그인 사용자
 */
$this->assets->addCss('/serve/front/view/mypage/basic/css/mypage.css');

$mpUser = $user ?? null;
$mpName = '';
$mpAvatar = '';
$mpLevelName = '일반회원';

if ($mpUser instanceof \Mublo\Contract\Auth\AuthenticatedUser) {
    $mpName = $mpUser->displayName();
    $mpAvatar = (string) ($mpUser->avatar ?? '');
    $mpLevelName = $mpUser->super ? '전체관리자' : ($mpUser->admin ? '관리자' : '일반회원');
} elseif (is_array($mpUser)) {
    $mpName = (string) ($mpUser['nickname'] ?? $mpUser['user_id'] ?? '');
    $mpAvatar = (string) ($mpUser['avatar'] ?? '');
    $mpLevelName = (string) ($mpUser['level_name'] ?? '일반회원');
}
$mpHasUser = $mpName !== '';
?>

<div class="mypage-wrapper">
    <!-- 사이드바 -->
    <aside class="mypage-sidebar">
        <?php if ($mpHasUser): ?>
            <div class="mypage-user-card">
                <div class="mypage-user-card__avatar">
                    <?php if ($mpAvatar !== ''): ?>
                        <img src="<?= htmlspecialchars($mpAvatar) ?>" alt="">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="mypage-user-card__info">
                    <div class="user-name"><?= htmlspecialchars($mpName) ?></div>
                    <div class="user-level"><?= htmlspecialchars($mpLevelName) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // 모바일 드롭다운 트리거에 표시할 현재 활성 메뉴 라벨
        $mpActiveLabel = '메뉴';
        $mpActiveIcon  = 'bi-list';
        foreach ($mypageMenus as $m) {
            if (!empty($m['active'])) {
                $mpActiveLabel = $m['label'];
                $mpActiveIcon  = $m['icon'] ?? 'bi-grid';
                break;
            }
        }
        ?>
        <button type="button" class="mypage-nav-toggle" aria-expanded="false" aria-controls="mypageNav">
            <span class="mypage-nav-toggle__label">
                <i class="bi <?= htmlspecialchars($mpActiveIcon) ?>" aria-hidden="true"></i>
                <?= htmlspecialchars($mpActiveLabel) ?>
            </span>
            <i class="bi bi-chevron-down mypage-nav-toggle__chevron" aria-hidden="true"></i>
        </button>

        <nav class="mypage-nav" id="mypageNav">
            <?php
            foreach ($mypageMenus as $i => $menu):
                // 탈퇴 메뉴 앞에 구분선
                if ($menu['section'] === 'withdraw' && $i > 0):
            ?>
                <!-- <div class="mypage-nav-divider"></div> -->
            <?php endif; ?>
            <a href="<?= htmlspecialchars($menu['url']) ?>"
               class="mypage-nav-item<?= $menu['active'] ? ' active' : '' ?>">
                <i class="bi <?= htmlspecialchars($menu['icon'] ?? 'bi-grid') ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($menu['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <!-- 콘텐츠 -->
    <main class="mypage-content">
        <?= $content ?>
    </main>
</div>

<script>
// 모바일 사이드 메뉴 드롭다운 토글 (데스크탑은 CSS로 트리거 숨김·항상 펼침)
(function () {
    var toggle = document.querySelector('.mypage-nav-toggle');
    var nav = document.getElementById('mypageNav');
    if (!toggle || !nav) return;
    function setOpen(open) {
        nav.classList.toggle('is-open', open);
        toggle.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(!nav.classList.contains('is-open'));
    });
    document.addEventListener('click', function (e) {
        if (!nav.contains(e.target) && !toggle.contains(e.target)) setOpen(false);
    });
})();
</script>
