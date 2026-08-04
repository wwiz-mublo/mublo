<?php
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
$csrfToken = $mublo['security']['csrfToken'];
/**
 * Member Register - Step 1: 약관 동의
 *
 * 회원가입 약관 동의 기본 스킨
 *
 * @var \Mublo\Entity\Member\Policy[] $policies 활성 약관 목록
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

    <div class="member-agree-wrapper">
    <div class="auth-card__header">
        <h1 class="auth-card__title">회원가입</h1>
    </div>
    <div class="step-indicator">
        <span class="step-indicator__item is-current"><span class="step-indicator__num">1</span>약관동의</span>
        <span class="step-indicator__sep"></span>
        <span class="step-indicator__item"><span class="step-indicator__num">2</span>정보입력</span>
        <span class="step-indicator__sep"></span>
        <span class="step-indicator__item"><span class="step-indicator__num">3</span>가입완료</span>
    </div>

    <form id="agree-form" method="POST" action="/member/register/agree">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
        <!-- 전체 동의 -->
        <div class="agree-all">
            <label>
                <input type="checkbox" id="agree-all-check">
                전체 동의
            </label>
        </div>

        <!-- 개별 약관 -->
        <?php foreach ($policies as $policy): ?>
            <div class="policy-item">
                <div class="policy-header">
                    <label>
                        <input
                            type="checkbox"
                            name="agreements[<?= $policy->getPolicyId() ?>]"
                            value="1"
                            class="policy-check"
                            data-required="<?= $policy->isRequired() ? '1' : '0' ?>"
                        >
                        <?= htmlspecialchars($policy->getPolicyTitle()) ?>
                        <?php if ($policy->isRequired()): ?>
                            <span class="badge badge-required">필수</span>
                        <?php else: ?>
                            <span class="badge badge-optional">선택</span>
                        <?php endif; ?>
                    </label>
                    <button type="button" class="policy-toggle" data-target="policy-content-<?= $policy->getPolicyId() ?>">내용보기</button>
                </div>
                <div class="policy-content" id="policy-content-<?= $policy->getPolicyId() ?>">
                    <?= $renderedContents[$policy->getPolicyId()] ?? $policy->getPolicyContent() ?>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn" id="btn-next" disabled>다음 단계</button>
    </form>

    <?php if (!empty($loginFormExtras)): ?>
        <?php foreach ($loginFormExtras as $html): ?>
            <?= $html ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="auth-card__footer">
        이미 회원이신가요? <a href="/login">로그인</a>
    </div>
    </div>
</div>

<script>
(function() {
    const allCheck = document.getElementById('agree-all-check');
    const policyChecks = document.querySelectorAll('.policy-check');
    const btnNext = document.getElementById('btn-next');
    const toggleBtns = document.querySelectorAll('.policy-toggle');

    // 전체 동의
    allCheck.addEventListener('change', function() {
        policyChecks.forEach(function(cb) {
            cb.checked = allCheck.checked;
        });
        updateNextButton();
    });

    // 개별 체크박스 변경
    policyChecks.forEach(function(cb) {
        cb.addEventListener('change', function() {
            // 전체 동의 체크박스 동기화
            allCheck.checked = Array.from(policyChecks).every(function(c) { return c.checked; });
            updateNextButton();
        });
    });

    // 내용 토글
    toggleBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = btn.getAttribute('data-target');
            var content = document.getElementById(targetId);
            if (content) {
                content.classList.toggle('show');
                btn.textContent = content.classList.contains('show') ? '접기' : '내용보기';
            }
        });
    });

    // 다음 버튼 활성화 (필수 약관 모두 동의 시)
    function updateNextButton() {
        var requiredChecks = document.querySelectorAll('.policy-check[data-required="1"]');
        var allRequired = Array.from(requiredChecks).every(function(cb) { return cb.checked; });
        btnNext.disabled = !allRequired;
    }

    updateNextButton();
})();
</script>
