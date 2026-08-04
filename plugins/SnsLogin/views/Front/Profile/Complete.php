<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * SNS 신규 가입 - 프로필 완성 페이지
 *
 * @var array  $pending  SNS 사용자 정보 (provider, email, nickname 등)
 * @var string $error    오류 코드
 * @var array  $fields   관리자 설정 추가 필드
 * @var array  $siteImages 사이트 이미지 URLs
 * @var array  $siteConfig 사이트 설정
 */
$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/auth/basic/css/auth.css');
$this->assets->addJs('/serve/plugin/SnsLogin/views/Front/Profile/Complete.js');

$errorMessages = [
    'nickname_required' => '닉네임을 입력해주세요.',
    'nickname_taken'    => '이미 사용 중인 닉네임입니다. 다른 닉네임을 입력해주세요.',
];
$errorMessage = $errorMessages[$error ?? ''] ?? '';
$providerLabels = ['naver' => '네이버', 'kakao' => '카카오', 'google' => 'Google'];
$providerLabel = $providerLabels[$pending['provider'] ?? ''] ?? ($pending['provider'] ?? 'SNS');

$brandIco  = trim($siteImages['favicon'] ?? '')
    ?: trim($siteImages['app_icon'] ?? '')
    ?: asset('/favicon.ico');
$brandName = $siteConfig['site_title'] ?? 'MUBLO';
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
        <h1 class="auth-card__title"><?= htmlspecialchars($providerLabel) ?> 로그인</h1>
        <p class="auth-card__desc">처음 로그인하셨습니다. 사용할 닉네임을 설정해주세요.</p>
    </div>

    <?php if ($errorMessage): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <?php if (!empty($pending['email'])): ?>
    <div class="field">
        <label>이메일</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars($pending['email']) ?>" readonly disabled>
    </div>
    <?php endif; ?>

    <form id="profileForm" name="frm">
        <div class="field">
            <label for="nickname">닉네임 <span class="required">*</span></label>
            <input
                type="text"
                id="nickname"
                name="formData[nickname]"
                class="form-control"
                value="<?= htmlspecialchars($pending['nickname'] ?? '') ?>"
                placeholder="사용할 닉네임을 입력하세요"
                maxlength="20"
                required
                autofocus
            >
        </div>

        <!-- 관리자 설정 추가 필드 -->
        <?php if (!empty($fields)): ?>
            <?php foreach ($fields as $field): ?>
                <?= \Mublo\Service\CustomField\CustomFieldRenderer::render($field, null, [
                    'namePrefix' => 'formData[fields]',
                    'idPrefix'   => 'field_',
                ]) ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <button type="button" class="btn mublo-submit"
                data-target="/sns-login/profile/complete"
                data-callback="profileComplete"
                data-loading="처리 중...">
            시작하기
        </button>
    </form>

    <div class="auth-card__footer">
        <a href="/login">다른 방법으로 로그인</a>
    </div>
    </div>
</div>

<?= \Mublo\Service\CustomField\CustomFieldRenderer::renderFileScript('/member/upload-field-file') ?>
