<?php
/**
 * 할인쿠폰 (프론트)
 *
 * 내 쿠폰함(사용가능/사용완료/기간만료) + 다운로드 가능 쿠폰 + 프로모션 코드 등록
 * 모든 데이터는 API로 로드 (SPA 방식)
 */
$this->assets->addCss('/serve/package/Shop/views/Front/Coupon/basic/_assets/css/coupon-index.css');
?>


<div class="shop-coupon">
    <h2 class="shop-coupon__title">할인쿠폰</h2>

    <!-- 프로모션 코드 입력 -->
    <div class="shop-coupon__promo">
        <input type="text" class="shop-coupon__promo-input" id="promoCodeInput" placeholder="프로모션 코드를 입력하세요" maxlength="30">
        <button type="button" class="shop-coupon__promo-btn" id="btnRegisterPromo">등록</button>
    </div>

    <!-- 탭 -->
    <div class="shop-coupon__tabs">
        <button type="button" class="shop-coupon__tab shop-coupon__tab--active" data-tab="available">사용가능</button>
        <button type="button" class="shop-coupon__tab" data-tab="used">사용완료</button>
        <button type="button" class="shop-coupon__tab" data-tab="expired">기간만료</button>
        <button type="button" class="shop-coupon__tab" data-tab="download">다운로드</button>
    </div>

    <!-- 내 쿠폰 목록 (사용가능/사용완료/기간만료 공용) -->
    <div id="tabMy">
        <div class="shop-coupon__loading" id="myLoading">불러오는 중...</div>
        <div id="myList"></div>
    </div>

    <!-- 다운로드 가능 쿠폰 -->
    <div id="tabDownload" style="display:none">
        <div class="shop-coupon__loading" id="dlLoading">불러오는 중...</div>
        <div id="dlList"></div>
    </div>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Coupon/basic/_assets/js/coupon-index.js') ?>"></script>
