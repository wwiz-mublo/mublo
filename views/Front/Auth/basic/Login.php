<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * Auth Login View
 *
 * 로그인 기본 스킨 (shadcn login-03 룩)
 *
 * @var string $redirect 로그인 후 리다이렉트 경로
 * @var bool $useEmailAsUserId 이메일=아이디 모드 여부
 */
$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/auth/basic/css/auth.css');

$brandIco   = trim($siteImages['favicon'] ?? '')
    ?: trim($siteImages['app_icon'] ?? '')
    ?: asset('/favicon.ico');
$brandName  = $siteConfig['site_title'] ?? 'MUBLO';
$isEmailId  = $useEmailAsUserId ?? false;
?>

<div class="auth-shell">
    <a class="auth-brand" href="/">
        <?php if (!empty($brandIco)): ?>
            <img class="auth-brand__ico" src="<?= htmlspecialchars($brandIco) ?>" alt="">
        <?php endif; ?>
        <span class="auth-brand__name"><?= htmlspecialchars($brandName) ?></span>
    </a>

    <div class="login-card">
        <div class="auth-card__header">
            <h1 class="auth-card__title">로그인</h1>
            <p class="auth-card__desc">계정에 로그인하세요</p>
        </div>

        <div id="login-error" class="alert alert-danger" style="display: none;"></div>

        <form id="login-form" name="frm" autocomplete="off">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '/') ?>">

            <div class="field">
                <div class="field__label-row">
                    <label for="user_id"><?= $isEmailId ? '이메일' : '아이디' ?></label>
                </div>
                <input
                    type="<?= $isEmailId ? 'email' : 'text' ?>"
                    id="user_id"
                    name="user_id"
                    class="form-control"
                    placeholder="<?= $isEmailId ? '이메일을 입력하세요' : '아이디를 입력하세요' ?>"
                    autocomplete="off"
                    required
                >
            </div>

            <div class="field">
                <div class="field__label-row">
                    <label for="password">비밀번호</label>
                    <a class="field__link" href="/find-account">비밀번호 찾기</a>
                </div>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    placeholder="비밀번호를 입력하세요"
                    required
                >
            </div>

            <button type="button" class="btn mublo-submit" data-target="/login" data-callback="loginComplete">로그인</button>
        </form>

        <?php if (!empty($loginFormExtras)): ?>
            <?php foreach ($loginFormExtras as $html): ?>
                <?= $html ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="auth-card__footer">
            계정이 없으신가요? <a href="/member/register">회원가입</a>
        </div>
    </div>

    <p class="auth-terms">로그인 시 <a href="/terms">이용약관</a> 및 <a href="/privacy">개인정보처리방침</a>에 동의하게 됩니다.</p>
</div>

<script>
// 로그인 완료 콜백 (MubloRequest의 executeCallback이 window[name] 폴백으로 호출)
window.loginComplete = function(response) {
    var errorDiv = document.getElementById('login-error');
    if (response.result === 'success') {
        var redirect = (response.data && response.data.redirect) || '/';
        location.href = redirect;
    } else {
        errorDiv.textContent = response.message || '로그인에 실패했습니다.';
        errorDiv.style.display = 'block';
    }
};

// Enter 키로 로그인
document.getElementById('login-form').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        var btn = this.querySelector('.mublo-submit');
        if (btn) btn.click();
    }
});
</script>
