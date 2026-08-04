<?php
/**
 * SNS 로그인 설정
 *
 * @var array  $config          현재 설정
 * @var string $callbackBaseUrl 콜백 URL 베이스
 * @var array  $levelOptions    [level_value => level_name] 일반 회원 레벨 목록
 */
$providers = [
    'naver' => [
        'label'        => '네이버',
        'icon'         => 'bi-chat-fill',
        'color'        => '#03C75A',
        'docs'         => 'https://developers.naver.com',
        'fields'       => [
            'client_id'     => ['label' => 'Client ID',     'type' => 'text',     'placeholder' => 'Client ID'],
            'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'Client Secret', 'help' => '로그인과 회원 탈퇴 시 네이버 토큰 발급·폐기에 사용합니다.'],
        ],
    ],
    'kakao' => [
        'label'        => '카카오',
        'icon'         => 'bi-chat-square-fill',
        'color'        => '#FEE500',
        'docs'         => 'https://developers.kakao.com',
        'fields'       => [
            'client_id'     => ['label' => 'REST API 키', 'type' => 'text', 'placeholder' => 'REST API 키', 'help' => '카카오 REST 로그인에 사용하는 필수 키입니다.'],
            'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'placeholder' => '카카오 로그인 Client Secret', 'help' => '카카오디벨로퍼스에서 ‘카카오 로그인’ Client Secret이 ON일 때만 입력합니다. 비즈니스 인증 Secret은 입력하지 않습니다.'],
            'admin_key'     => ['label' => 'Admin 키', 'type' => 'password', 'placeholder' => 'Admin 키', 'help' => '카카오 가입자의 회원 탈퇴 및 서버 측 연결 해제에 필요합니다.'],
            'javascript_key'=> ['label' => 'JavaScript 키', 'type' => 'text', 'placeholder' => 'JavaScript 키', 'help' => 'JavaScript SDK 기능을 사용할 때만 입력합니다. 현재 REST 로그인에는 사용하지 않습니다.'],
        ],
    ],
    'google' => [
        'label'        => 'Google',
        'icon'         => 'bi-google',
        'color'        => '#4285F4',
        'docs'         => 'https://console.cloud.google.com',
        'fields'       => [
            'client_id'     => ['label' => 'Client ID',     'type' => 'text',     'placeholder' => 'Client ID'],
            'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'Client Secret', 'help' => '로그인 토큰 발급에 사용하는 필수 비밀값입니다. 회원 탈퇴 시에는 저장된 Google 토큰을 폐기합니다.'],
        ],
    ],
];
$currentLevel = (int)($config['register_level'] ?? 1);
?>
<style>
/* type=password 를 쓰면 크롬 비밀번호 관리자가 저장/자동완성을 제안하므로,
   text + text-security 마스킹으로 대체한다. */
#frm .is-masked:not(:placeholder-shown) {
    -webkit-text-security: disc;
    text-security: disc;
}
</style>

<form name="frm" id="frm" autocomplete="off">
<div class="page-container form-container">

    <div class="page-title">
        <div class="page-title-text">
            <h3>SNS 로그인 설정</h3>
            <p>소셜 로그인 제공자별 API 키를 설정합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/sns-login/accounts" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list-ul"></i> 연동 내역
            </a>
            <button type="button"
                    class="btn btn-sm btn-primary mublo-submit"
                    data-target="/admin/sns-login/settings"
                    data-callback="snsSettingsSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <div class="page-block">

        <!-- 공통 설정 -->
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-sliders text-pastel-blue"></i>
                <span>공통 설정</span>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="formData[auto_register]" value="1"
                                   id="autoRegister"
                                   <?= !empty($config['auto_register']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="autoRegister">신규 회원 자동 가입</label>
                        </div>
                        <div class="form-text">OFF 시 SNS 최초 로그인 후 닉네임 입력 페이지로 이동합니다.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">자동 가입 회원 레벨</label>
                        <select name="formData[register_level]" class="form-select">
                            <?php foreach ($levelOptions as $val => $name): ?>
                            <option value="<?= (int)$val ?>" <?= $currentLevel === (int)$val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if (empty($levelOptions)): ?>
                            <option value="1">레벨 1</option>
                            <?php endif; ?>
                        </select>
                        <div class="form-text">SNS 가입 시 부여할 레벨</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 제공자 카드 (3열) -->
        <div class="row g-4">
            <?php foreach ($providers as $name => $info):
                $pc          = $config[$name] ?? [];
                $isEnabled   = !empty($pc['enabled']);
                $callbackUrl = $callbackBaseUrl . '/sns-login/callback/' . $name;
            ?>
            <div class="col-12 col-lg-4">
                <div class="card h-100" style="border-top: 3px solid <?= $info['color'] ?>;">
                    <div class="card-hero">
                        <i class="<?= $info['icon'] ?>" style="color:<?= $info['color'] ?>; font-size:1.1rem;"></i>
                        <span><?= $info['label'] ?></span>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input" type="checkbox"
                                   name="formData[<?= $name ?>][enabled]" value="1"
                                   id="<?= $name ?>Enabled"
                                   <?= $isEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $name ?>Enabled">
                                <small class="text-muted">사용</small>
                            </label>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <?php foreach ($info['fields'] as $fieldKey => $fieldInfo):
                            $isSecret = ($fieldInfo['type'] ?? 'text') === 'password';
                            $inputId  = 'f_' . $name . '_' . $fieldKey;
                        ?>
                        <div>
                            <label class="form-label" for="<?= $inputId ?>"><?= $fieldInfo['label'] ?></label>
                            <?php if ($isSecret): ?>
                            <div class="input-group input-group-sm">
                                <input type="text" id="<?= $inputId ?>"
                                       name="formData[<?= $name ?>][<?= $fieldKey ?>]"
                                       class="form-control is-masked"
                                       value="<?= htmlspecialchars($pc[$fieldKey] ?? '') ?>"
                                       placeholder="<?= htmlspecialchars($fieldInfo['placeholder']) ?>"
                                       autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other">
                                <button type="button" class="btn btn-outline-secondary"
                                        data-toggle-secret="<?= $inputId ?>" title="표시/숨김">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php else: ?>
                            <input type="text" id="<?= $inputId ?>"
                                   name="formData[<?= $name ?>][<?= $fieldKey ?>]"
                                   class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($pc[$fieldKey] ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($fieldInfo['placeholder']) ?>"
                                   autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other">
                            <?php endif; ?>
                            <?php if (!empty($fieldInfo['help'])): ?>
                            <div class="form-text"><?= htmlspecialchars($fieldInfo['help']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <div>
                            <label class="form-label">
                                Callback URL
                                <span class="text-muted fw-normal" style="font-size:.8em;">(개발자 센터 등록 필요)</span>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control"
                                       id="callbackUrl_<?= $name ?>"
                                       value="<?= htmlspecialchars($callbackUrl) ?>"
                                       readonly autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="copyCallback('<?= $name ?>')">
                                    <i class="bi bi-clipboard" id="clipIcon_<?= $name ?>"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mt-auto pt-1">
                            <a href="<?= $info['docs'] ?>" target="_blank" rel="noopener"
                               class="text-decoration-none" style="font-size:.85rem;">
                                <i class="bi bi-box-arrow-up-right"></i> <?= $info['label'] ?> 개발자 센터
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>
</form>

<script>
function copyCallback(name) {
    var input = document.getElementById('callbackUrl_' + name);
    var icon  = document.getElementById('clipIcon_' + name);
    navigator.clipboard.writeText(input.value).then(function() {
        icon.className = 'bi bi-clipboard-check text-success';
        setTimeout(function() { icon.className = 'bi bi-clipboard'; }, 2000);
    });
}

document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-toggle-secret]');
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-toggle-secret'));
    var icon  = btn.querySelector('i');
    if (!input) return;
    var masked = input.classList.toggle('is-masked');
    if (icon) icon.className = masked ? 'bi bi-eye' : 'bi bi-eye-slash';
});

MubloRequest.registerCallback('snsSettingsSaved', function(response) {
    alert(response.message || '저장되었습니다.');
    if (response.result === 'success') {
        location.reload();
    }
});
</script>
