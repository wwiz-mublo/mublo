<?php
$csrfToken = $mublo['security']['csrfToken'];
/**
 * Mypage - 회원정보수정
 *
 * @var array  $user             로그인한 사용자 정보
 * @var array  $fieldDefinitions 추가 필드 정의
 * @var array  $fieldValues      필드 값 (field_id => field_value)
 * @var array[] $mypageMenus     사이드바 메뉴 목록
 * @var string $currentSection   현재 활성 섹션
 */
?>

<?php ob_start(); ?>
<div class="mypage-profile">
    <div class="mypage-header">
        <h1 class="mypage-header__title">회원정보</h1>
        <p class="mypage-header__desc">회원 정보와 비밀번호를 관리합니다.</p>
    </div>

    <div id="profile-message" class="alert" style="display: none;"></div>

    <!-- 기본 정보 -->
    <div class="profile-info">
        <p><strong>아이디</strong> <?= htmlspecialchars($user['user_id'] ?? '') ?></p>
        <p><strong>닉네임</strong> <?= htmlspecialchars($user['nickname'] ?? '') ?></p>
        <p><strong>회원등급</strong> <?= htmlspecialchars($user['level_name'] ?? '일반회원') ?></p>
        <p><strong>가입일</strong> <?= htmlspecialchars(substr($user['created_at'] ?? '', 0, 10)) ?></p>
        <?php if (!empty($user['last_login_at'])): ?>
            <p><strong>최근 로그인</strong> <?= htmlspecialchars(substr($user['last_login_at'], 0, 16)) ?></p>
        <?php endif; ?>
    </div>

    <form id="profile-form" method="POST" action="/mypage/profile">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

        <h3 class="section-title">기본 정보</h3>
        <div class="profile-form-group">
            <label for="nickname">닉네임</label>
            <div class="profile-input-group">
                <input type="text" id="nickname" name="nickname" class="profile-form-control"
                       value="<?= htmlspecialchars($user['nickname'] ?? '') ?>" placeholder="2~20자"
                       autocomplete="off">
                <button type="button" class="btn-check" id="check-nickname">중복확인</button>
            </div>
            <div id="nickname-message" class="profile-form-help"></div>
        </div>

        <?php
            $pw = $passwordPolicy ?? ['min' => 6, 'lower' => false, 'number' => false, 'special' => false];
            $pwHintParts = ["최소 {$pw['min']}자"];
            if (!empty($pw['lower']))   { $pwHintParts[] = '영문 소문자'; }
            if (!empty($pw['number']))  { $pwHintParts[] = '숫자'; }
            if (!empty($pw['special'])) { $pwHintParts[] = '특수문자'; }
            $pwHint = implode(' · ', $pwHintParts);
        ?>
        <h3 class="section-title">비밀번호 변경</h3>
        <div class="profile-form-row">
            <div class="profile-form-group">
                <label for="new_password">새 비밀번호</label>
                <input type="password" id="new_password" name="new_password" class="profile-form-control"
                       placeholder="변경할 경우에만 입력" autocomplete="new-password"
                       data-pw-min="<?= (int) $pw['min'] ?>"
                       data-pw-lower="<?= !empty($pw['lower']) ? '1' : '0' ?>"
                       data-pw-number="<?= !empty($pw['number']) ? '1' : '0' ?>"
                       data-pw-special="<?= !empty($pw['special']) ? '1' : '0' ?>">
                <div class="profile-form-help"><?= htmlspecialchars($pwHint) ?></div>
                <div class="profile-form-help pw-policy-message" style="display:none;"></div>
            </div>
            <div class="profile-form-group">
                <label for="new_password_confirm">새 비밀번호 확인</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" class="profile-form-control"
                       placeholder="다시 입력" autocomplete="new-password">
                <div class="profile-form-help pw-confirm-message" style="display:none;"></div>
            </div>
        </div>

        <?php if (!empty($fieldDefinitions)): ?>
            <h3 class="section-title">추가 정보</h3>
            <?php foreach ($fieldDefinitions as $field): ?>
                <?= \Mublo\Service\CustomField\CustomFieldRenderer::render($field, $fieldValues[$field['field_id']] ?? '', [
                    'namePrefix'   => 'fields',
                    'idPrefix'     => 'pf_field_',
                    'showExisting' => true,
                ]) ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="mypage-profile__actions">
            <button type="button" class="btn-primary mublo-submit"
                    data-target="/mypage/profile" data-callback="profileComplete">회원정보 변경</button>
        </div>
    </form>
</div>

<script>
// 닉네임 중복 확인 (본인이 쓰던 닉네임은 서버가 중복에서 제외)
document.getElementById('check-nickname').addEventListener('click', function() {
    var nickname = document.getElementById('nickname').value;
    var messageDiv = document.getElementById('nickname-message');

    if (!nickname) {
        messageDiv.textContent = '닉네임을 입력해주세요.';
        messageDiv.style.color = '#e74c3c';
        return;
    }

    MubloRequest.requestJson('/member/check-nickname', { nickname: nickname })
        .then(function(res) {
            messageDiv.textContent = res.message;
            messageDiv.style.color = '#27ae60';
        })
        .catch(function() {
            messageDiv.textContent = '이미 사용 중인 닉네임입니다.';
            messageDiv.style.color = '#e74c3c';
        });
});

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

window.profileComplete = function(response) {
    var msg = document.getElementById('profile-message');
    msg.textContent = response.message || '저장되었습니다.';
    msg.className = 'alert alert-success';
    document.getElementById('new_password').value = '';
    document.getElementById('new_password_confirm').value = '';
    msg.style.display = 'block';
    window.scrollTo(0, 0);
    // 닉네임 등 변경분을 화면 전역(표시 영역·사이드바·헤더)에 반영
    setTimeout(function() { window.location.reload(); }, 800);
};
</script>
<?= \Mublo\Service\CustomField\CustomFieldRenderer::renderFileScript('/member/upload-field-file') ?>
<?php $content = ob_get_clean(); ?>

<?php include __DIR__ . '/_layout.php'; ?>
