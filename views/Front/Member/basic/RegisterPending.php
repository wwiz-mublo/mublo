<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * Member Register - 가입 신청 완료 (관리자 승인 대기)
 *
 * 회원가입 승인 대기 기본 스킨
 */
$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/member/basic/css/register.css');

$brandIco  = trim($siteImages['favicon'] ?? '')
    ?: trim($siteImages['app_icon'] ?? '')
    ?: asset('/favicon.ico');
$brandName = $siteConfig['site_title'] ?? 'MUBLO';
?>

<div class="auth-shell auth-shell--wide">
    <a class="auth-brand" href="/">
        <?php if (!empty($brandIco)): ?>
            <img class="auth-brand__ico" src="<?= htmlspecialchars($brandIco) ?>" alt="">
        <?php endif; ?>
        <span class="auth-brand__name"><?= htmlspecialchars($brandName) ?></span>
    </a>

    <div class="member-complete-wrapper">
        <div class="complete-icon">&#9993;</div>
        <div class="auth-card__header">
            <h1 class="auth-card__title">가입 신청이 완료되었습니다</h1>
        </div>
        <p class="complete-message">
            가입 신청을 받았습니다.<br>
            관리자 승인 후 로그인이 가능합니다.
        </p>
        <div class="member-complete-actions">
            <a href="/" class="btn">메인으로</a>
        </div>
    </div>
</div>
