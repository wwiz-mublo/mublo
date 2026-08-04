<?php
/**
 * 푸터 메뉴 컴포넌트 (이용약관·개인정보처리방침 등 하단 링크)
 *
 * 파일 스킨(frame/{skin}/Footer.php)과 프레임 템플릿 슬롯({{menu_footer}})이 공유한다.
 * visibility(guest/member/all) 필터링을 이 컴포넌트가 소유한다.
 *
 * @var array $footerMenus   푸터 메뉴 목록 [{label, url, target, visibility}]
 * @var array $viewer $mublo.viewer 계약
 */
$isLogin = !empty(($viewer ?? [])['authenticated']);
$footerMenus = array_filter($footerMenus ?? [], function ($item) use ($isLogin) {
    $vis = $item['visibility'] ?? 'all';
    if ($vis === 'guest') return !$isLogin;
    if ($vis === 'member') return $isLogin;
    return true;
});
?>
        <?php if (!empty($footerMenus)): ?>
        <div class="footer-top">
            <nav class="footer-nav" aria-label="푸터 메뉴">
                <?php foreach ($footerMenus as $menu): ?>
                <a href="<?= htmlspecialchars($menu['url'] ?? '#') ?>"
                   <?= !empty($menu['target']) && $menu['target'] === '_blank' ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <?= htmlspecialchars($menu['label'] ?? '') ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php endif; ?>
