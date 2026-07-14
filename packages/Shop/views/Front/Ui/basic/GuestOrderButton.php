
<?php
// 로그인 페이지에서 ?redirect= 로 넘겨받은 원래 체크아웃 URL을 사용해
// 직구매(mode=direct) 같은 컨텍스트는 Subscriber가 검증해 전달한다.
$redirectUrl = $redirectUrl ?? '/shop/checkout';
$separator = str_contains($redirectUrl, '?') ? '&' : '?';
$guestUrl = $redirectUrl . $separator . 'guest=1';
$this->assets->addCss('/serve/package/Shop/views/Front/Ui/basic/_assets/css/guest-order-button.css');
?>
<div class="guest-checkout-section">
    <p class="guest-checkout-desc">회원가입 없이 바로 주문할 수 있습니다.</p>
    <a href="<?= htmlspecialchars($guestUrl) ?>" class="guest-checkout-btn">비회원으로 주문하기</a>
</div>
