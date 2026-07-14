<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * Member Register - Step 3: 가입 완료
 *
 * 회원가입 완료 기본 스킨
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
        <div class="complete-icon complete-icon--success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
        <div class="auth-card__header">
            <h1 class="auth-card__title">회원가입이 완료되었습니다</h1>
        </div>
        <p class="complete-message">
            회원가입을 축하합니다!<br>
            로그인 후 서비스를 이용하실 수 있습니다.
        </p>
        <div class="member-complete-actions">
            <a href="/login" class="btn">로그인</a>
            <a href="/" class="btn btn-outline">메인으로</a>
        </div>
    </div>
</div>
