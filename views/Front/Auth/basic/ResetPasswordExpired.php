<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * Auth ResetPasswordExpired View
 *
 * 비밀번호 재설정 토큰 만료/무효 안내
 *
 * @var string $message 안내 메시지
 */
$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/auth/basic/css/auth.css');

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

    <div class="expired-wrapper">
        <div class="icon">&#10060;</div>
        <div class="auth-card__header">
            <h1 class="auth-card__title">링크가 유효하지 않습니다</h1>
            <p class="auth-card__desc"><?= htmlspecialchars($message) ?></p>
        </div>
        <a href="/find-account" class="btn">비밀번호 재설정 다시 요청</a>
    </div>
</div>
