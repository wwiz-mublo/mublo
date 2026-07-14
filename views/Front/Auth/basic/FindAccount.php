<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * Auth FindAccount View
 *
 * 계정 찾기 (아이디 찾기 + 비밀번호 재설정 요청)
 *
 * @var bool $useEmailAsUserId 이메일=아이디 모드 여부
 * @var bool $hasEmailField 이메일 커스텀 필드 존재 여부 (Mode B)
 */
$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/auth/basic/css/auth.css');
$showFindId = !$useEmailAsUserId;

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

    <div class="find-account-wrapper">
    <div class="auth-card__header">
        <h1 class="auth-card__title">계정 찾기</h1>
        <p class="auth-card__desc">가입 시 등록한 이메일로 계정을 확인하세요</p>
    </div>

    <?php if ($showFindId && !$hasEmailField): ?>
        <div class="alert alert-info">
            이메일 입력 필드가 설정되지 않아 아이디/비밀번호 찾기를 이용할 수 없습니다.
            관리자에게 문의해주세요.
        </div>
    <?php else: ?>

        <!-- 탭 -->
        <div class="tabs">
            <?php if ($showFindId): ?>
                <button type="button" class="tab-btn active" data-tab="find-id">아이디 찾기</button>
            <?php endif; ?>
            <button type="button" class="tab-btn <?= !$showFindId ? 'active' : '' ?>" data-tab="reset-pw">비밀번호 찾기</button>
        </div>

        <!-- 아이디 찾기 (Mode B만) -->
        <?php if ($showFindId): ?>
            <div class="tab-content active" id="tab-find-id">
                <div id="find-id-message" class="alert" style="display: none;"></div>

                <form id="find-id-form" name="frmFindId">
                    <div class="form-group">
                        <label for="find_email">가입 시 등록한 이메일</label>
                        <input
                            type="email"
                            id="find_email"
                            name="formData[email]"
                            class="form-control"
                            placeholder="이메일을 입력하세요"
                            required
                        >
                    </div>

                    <button type="button" class="btn" id="btn-find-id">아이디 찾기</button>
                </form>

                <div id="find-id-result" class="result-box" style="display: none;"></div>
            </div>
        <?php endif; ?>

        <!-- 비밀번호 재설정 요청 -->
        <div class="tab-content <?= !$showFindId ? 'active' : '' ?>" id="tab-reset-pw">
            <div id="reset-pw-message" class="alert" style="display: none;"></div>

            <form id="reset-pw-form" name="frmResetPw">
                <?php if (!$useEmailAsUserId): ?>
                    <div class="form-group">
                        <label for="reset_user_id">아이디</label>
                        <input
                            type="text"
                            id="reset_user_id"
                            name="formData[user_id]"
                            class="form-control"
                            placeholder="아이디를 입력하세요"
                            required
                        >
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="reset_email"><?= $useEmailAsUserId ? '이메일(아이디)' : '가입 시 등록한 이메일' ?></label>
                    <input
                        type="email"
                        id="reset_email"
                        name="formData[email]"
                        class="form-control"
                        placeholder="이메일을 입력하세요"
                        required
                    >
                </div>

                <button type="button" class="btn" id="btn-reset-pw">재설정 링크 발송</button>

                <p class="form-help form-help--note">입력한 이메일로 비밀번호 재설정 링크가 발송됩니다.</p>
            </form>
        </div>

    <?php endif; ?>

    <div class="auth-links">
        <a href="/login">로그인</a> | <a href="/member/register">회원가입</a>
    </div>
    </div>
</div>

<script>
(function() {
    // 탭 전환
    var tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            tabBtns.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');

            document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
            var target = document.getElementById('tab-' + btn.getAttribute('data-tab'));
            if (target) target.classList.add('active');
        });
    });

    // 아이디 찾기
    var btnFindId = document.getElementById('btn-find-id');
    if (btnFindId) {
        btnFindId.addEventListener('click', function() {
            var email = document.getElementById('find_email').value;
            var messageDiv = document.getElementById('find-id-message');
            var resultDiv = document.getElementById('find-id-result');

            if (!email) {
                messageDiv.textContent = '이메일을 입력해주세요.';
                messageDiv.className = 'alert alert-danger';
                messageDiv.style.display = 'block';
                resultDiv.style.display = 'none';
                return;
            }

            messageDiv.style.display = 'none';
            resultDiv.style.display = 'none';

            MubloRequest.sendRequest({
                method: 'POST',
                url: '/find-account/find-userid',
                payloadType: MubloRequest.PayloadType.FORM,
                data: new FormData(document.getElementById('find-id-form'))
            })
            .then(function(data) {
                var userIds = data.data.userIds || [];
                var html = '<p style="margin-bottom:10px;color:#555;">가입된 아이디입니다.</p>';
                userIds.forEach(function(id) {
                    html += '<p class="user-id">' + id + '</p>';
                });
                resultDiv.innerHTML = html;
                resultDiv.style.display = 'block';
            });
        });
    }

    // 비밀번호 재설정 요청
    var btnResetPw = document.getElementById('btn-reset-pw');
    if (btnResetPw) {
        btnResetPw.addEventListener('click', function() {
            var messageDiv = document.getElementById('reset-pw-message');
            var form = document.getElementById('reset-pw-form');
            messageDiv.style.display = 'none';
            btnResetPw.disabled = true;
            btnResetPw.textContent = '발송 중...';

            MubloRequest.sendRequest({
                method: 'POST',
                url: '/find-account/request-reset',
                payloadType: MubloRequest.PayloadType.FORM,
                data: new FormData(form)
            })
            .then(function(data) {
                messageDiv.textContent = data.message;
                messageDiv.className = 'alert alert-success';
                messageDiv.style.display = 'block';
                form.reset();
            })
            .finally(function() {
                btnResetPw.disabled = false;
                btnResetPw.textContent = '재설정 링크 발송';
            });
        });
    }
})();
</script>
