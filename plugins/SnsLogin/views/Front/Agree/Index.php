<?php
$siteImages = $mublo['site']['images'] ?? [];
$siteConfig = $mublo['site']['config'] ?? [];
$csrfToken  = $mublo['security']['csrfToken'] ?? '';
/**
 * SNS 가입 - 약관 동의
 *
 * 코어 가입 1단계(views/Front/Member/basic/Agree.php)와 같은 스킨을 쓴다. 같은 사이트의
 * 같은 약관이므로 가입 방식에 따라 화면이 달라 보일 이유가 없고, 클래스를 새로 만들면
 * 스킨을 고칠 때 한쪽만 따라가지 못한다.
 *
 * @var string $provider 가입에 사용한 제공자 이름
 * @var \Mublo\Contract\Member\PolicyDocument[] $documents 표시할 약관
 * @var array<int, string> $renderedContents 치환 변수가 적용된 약관 내용
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

        <form id="agree-form">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="agree-all">
                <label>
                    <input type="checkbox" id="agree-all-check">
                    전체 동의
                </label>
            </div>

            <?php foreach ($documents as $document): ?>
                <div class="policy-item">
                    <div class="policy-header">
                        <label>
                            <input
                                type="checkbox"
                                name="agreements[<?= (int) $document->policyId ?>]"
                                value="1"
                                class="policy-check"
                                data-required="<?= $document->required ? '1' : '0' ?>"
                            >
                            <?= htmlspecialchars($document->title) ?>
                            <?php if ($document->required): ?>
                                <span class="badge badge-required">필수</span>
                            <?php else: ?>
                                <span class="badge badge-optional">선택</span>
                            <?php endif; ?>
                        </label>
                        <button type="button" class="policy-toggle" data-target="policy-content-<?= (int) $document->policyId ?>">내용보기</button>
                    </div>
                    <div class="policy-content" id="policy-content-<?= (int) $document->policyId ?>">
                        <?= $renderedContents[$document->policyId] ?? '' ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="button" class="btn" id="btn-next" disabled>동의하고 가입</button>
        </form>

        <div class="auth-card__footer">
            <a href="/login">로그인으로 돌아가기</a>
        </div>
    </div>
</div>

<script>
(function() {
    const allCheck = document.getElementById('agree-all-check');
    const policyChecks = document.querySelectorAll('.policy-check');
    const btnNext = document.getElementById('btn-next');
    const toggleBtns = document.querySelectorAll('.policy-toggle');

    allCheck.addEventListener('change', function() {
        policyChecks.forEach(function(cb) {
            cb.checked = allCheck.checked;
        });
        updateNextButton();
    });

    policyChecks.forEach(function(cb) {
        cb.addEventListener('change', function() {
            allCheck.checked = Array.from(policyChecks).every(function(c) { return c.checked; });
            updateNextButton();
        });
    });

    toggleBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var content = document.getElementById(btn.getAttribute('data-target'));
            if (content) {
                content.classList.toggle('show');
                btn.textContent = content.classList.contains('show') ? '접기' : '내용보기';
            }
        });
    });

    // 필수 약관을 모두 체크해야 진행할 수 있다. 최종 판정은 서버가 다시 한다 —
    // 화면을 우회한 요청도 같은 기준으로 막아야 하기 때문이다.
    function updateNextButton() {
        var requiredChecks = document.querySelectorAll('.policy-check[data-required="1"]');
        btnNext.disabled = !Array.from(requiredChecks).every(function(cb) { return cb.checked; });
    }

    btnNext.addEventListener('click', function() {
        btnNext.disabled = true;

        MubloRequest.sendRequest({
            method: 'POST',
            url: '/sns-login/agree',
            payloadType: 'form',
            data: new FormData(document.getElementById('agree-form')),
        }).then(function(response) {
            // MubloRequest 는 공통 응답 규격을 그대로 넘긴다 — 주소는 data 안에 있다.
            // 한 단계를 빠뜨리면 주소를 못 찾아 홈으로 떨어지고, 자동 가입이 꺼진
            // 사이트에서는 프로필 입력 단계로 가지 못해 가입이 중간에 끊긴다.
            window.location.href = response?.data?.redirect || '/';
        }).catch(function() {
            btnNext.disabled = false;
        });
    });

    updateNextButton();
})();
</script>
