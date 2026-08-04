<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * Auth ResetPassword View
 *
 * 비밀번호 재설정 폼 (이메일 링크 도착 후)
 *
 * @var string $token 비밀번호 재설정 토큰
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

    <div class="reset-pw-wrapper">
    <div class="auth-card__header">
        <h1 class="auth-card__title">비밀번호 재설정</h1>
        <p class="auth-card__desc">새로운 비밀번호를 입력해주세요.</p>
    </div>

    <div id="reset-message" class="alert" style="display: none;"></div>

    <form id="reset-form" name="frmResetPassword">
        <input type="hidden" name="formData[token]" value="<?= htmlspecialchars($token) ?>">

        <?php
            $pwMin = (int)($siteConfig['password_min_length'] ?? 6);
            $pwLower = !empty($siteConfig['password_require_lower']);
            $pwNumber = !empty($siteConfig['password_require_number']);
            $pwSpecial = !empty($siteConfig['password_require_special']);
            $pwHintParts = ["최소 {$pwMin}자"];
            if ($pwLower)   { $pwHintParts[] = '영문 소문자'; }
            if ($pwNumber)  { $pwHintParts[] = '숫자'; }
            if ($pwSpecial) { $pwHintParts[] = '특수문자'; }
            $pwHint = implode(' · ', $pwHintParts);
        ?>
        <div class="form-group">
            <label for="new_password">새 비밀번호</label>
            <input
                type="password"
                id="new_password"
                name="formData[new_password]"
                class="form-control"
                placeholder="<?= htmlspecialchars($pwHint) ?>"
                data-pw-min="<?= $pwMin ?>"
                data-pw-lower="<?= $pwLower ? '1' : '0' ?>"
                data-pw-number="<?= $pwNumber ? '1' : '0' ?>"
                data-pw-special="<?= $pwSpecial ? '1' : '0' ?>"
                required
            >
            <div class="form-help pw-policy-message" style="display:none;"></div>
        </div>

        <div class="form-group">
            <label for="new_password_confirm">새 비밀번호 확인</label>
            <input
                type="password"
                id="new_password_confirm"
                name="formData[new_password_confirm]"
                class="form-control"
                placeholder="새 비밀번호를 다시 입력하세요"
                required
            >
            <div class="form-help pw-confirm-message" style="display:none;"></div>
        </div>

        <button type="button" class="btn" id="btn-reset">비밀번호 변경</button>
    </form>
    </div>
</div>

<script>
(function() {
    var btnReset = document.getElementById('btn-reset');

    // 비밀번호 실시간 검증 (판정은 공용 MubloPasswordPolicy, 표시는 스킨 담당)
    document.getElementById('new_password').addEventListener('input', function() {
        var messageDiv = this.parentNode.querySelector('.pw-policy-message');

        if (this.value === '') {
            messageDiv.style.display = 'none';
            return;
        }

        var r = MubloPasswordPolicy.validate(this);
        messageDiv.textContent = r.message;
        messageDiv.style.color = r.valid ? '#27ae60' : '#e74c3c';
        messageDiv.style.display = 'block';

        checkPasswordMatch();
    });

    // 비밀번호 확인 일치 검증 (비밀번호를 나중에 고쳐도 갱신되도록 양쪽 필드에서 호출)
    function checkPasswordMatch() {
        var confirmInput = document.getElementById('new_password_confirm');
        var messageDiv = confirmInput.parentNode.querySelector('.pw-confirm-message');

        if (confirmInput.value === '') {
            messageDiv.style.display = 'none';
            return;
        }

        var ok = document.getElementById('new_password').value === confirmInput.value;
        messageDiv.textContent = ok ? '비밀번호가 일치합니다.' : '비밀번호가 일치하지 않습니다.';
        messageDiv.style.color = ok ? '#27ae60' : '#e74c3c';
        messageDiv.style.display = 'block';
    }
    document.getElementById('new_password_confirm').addEventListener('input', checkPasswordMatch);

    btnReset.addEventListener('click', function() {
        var messageDiv = document.getElementById('reset-message');
        var newPw = document.getElementById('new_password').value;
        var newPwConfirm = document.getElementById('new_password_confirm').value;

        if (!newPw || !newPwConfirm) {
            messageDiv.textContent = '비밀번호를 입력해주세요.';
            messageDiv.className = 'alert alert-danger';
            messageDiv.style.display = 'block';
            return;
        }

        if (newPw !== newPwConfirm) {
            messageDiv.textContent = '비밀번호가 일치하지 않습니다.';
            messageDiv.className = 'alert alert-danger';
            messageDiv.style.display = 'block';
            return;
        }

        messageDiv.style.display = 'none';
        btnReset.disabled = true;
        btnReset.textContent = '변경 중...';

        var form = document.getElementById('reset-form');

        MubloRequest.sendRequest({
            method: 'POST',
            url: '/find-account/reset-password',
            payloadType: MubloRequest.PayloadType.FORM,
            data: new FormData(form)
        })
        .then(function(data) {
            messageDiv.textContent = data.message;
            messageDiv.className = 'alert alert-success';
            messageDiv.style.display = 'block';
            form.reset();
            btnReset.style.display = 'none';
            setTimeout(function() {
                location.href = '/login';
            }, 2000);
        })
        .catch(function() {
            btnReset.disabled = false;
            btnReset.textContent = '비밀번호 변경';
        });
    });
})();
</script>
