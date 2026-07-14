<?php
$csrfToken = $mublo['security']['csrfToken'];
$siteImages = $mublo['site']['images'];
$siteConfig = $mublo['site']['config'];
/**
 * 비회원 주문 조회 (프론트)
 *
 * 인증 전용 게이트 — 주문인명 + 연락처가 일치하면 컨트롤러가 세션에 소유 증명을 기록하고
 * /shop/order/{no}(단건) 또는 /shop/orders(다건)로 리다이렉트한다. (표시는 기존 라우팅 재사용)
 *
 * 로그인 페이지와 동일한 풀페이지 auth 레이아웃/디자인(shadcn login-03 룩)을 재사용한다.
 *
 * @var string|null $error        오류 메시지
 * @var string      $ordererName  직전 입력값(이름)
 * @var string      $ordererPhone 직전 입력값(연락처)
 * @var string|null $csrfToken    CSRF 토큰 (전역 주입)
 * @var array       $siteImages   사이트 이미지 URL (전역 주입)
 * @var array       $siteConfig   사이트 설정 (전역 주입)
 */
$error        = $error ?? null;
$ordererName  = $ordererName ?? '';
$ordererPhone = $ordererPhone ?? '';

$this->layout(['header' => false, 'footer' => false]);
$this->assets->addCss('/serve/front/view/auth/basic/css/auth.css');

$brandIco  = trim($siteImages['favicon'] ?? '')
    ?: trim($siteImages['app_icon'] ?? '')
    ?: asset('/favicon.ico');
$brandName = $siteConfig['site_title'] ?? 'MUBLO';
?>

<div class="auth-shell">
    <a class="auth-brand" href="/">
        <?php if (!empty($brandIco)): ?>
            <img class="auth-brand__ico" src="<?= e($brandIco) ?>" alt="">
        <?php endif; ?>
        <span class="auth-brand__name"><?= e($brandName) ?></span>
    </a>

    <div class="login-card">
        <div class="auth-card__header">
            <h1 class="auth-card__title">비회원 주문 조회</h1>
            <p class="auth-card__desc">주문 시 입력하신 주문인명과 연락처로<br>주문을 조회할 수 있습니다.</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/shop/order/lookup">
            <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">

            <div class="field">
                <label for="lookupName">주문인명</label>
                <input type="text" id="lookupName" name="orderer_name" class="form-control"
                       value="<?= e($ordererName) ?>" placeholder="주문 시 입력한 이름" required>
            </div>

            <div class="field">
                <label for="lookupPhone">연락처</label>
                <input type="tel" id="lookupPhone" name="orderer_phone" class="form-control mask-hp"
                       value="<?= e($ordererPhone) ?>" placeholder="주문 시 입력한 연락처" required>
            </div>

            <button type="submit" class="btn">주문 조회</button>
        </form>

        <div class="auth-card__footer">
            회원이신가요? <a href="/login">로그인하고 조회</a>
        </div>
    </div>
</div>
