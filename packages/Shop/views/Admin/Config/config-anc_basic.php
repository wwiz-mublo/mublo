<?php
/**
 * 쇼핑몰 설정 - 기본 설정
 *
 * @var array $config 쇼핑몰 설정
 */
?>
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-shop text-pastel-blue"></i>
        <span>기본 설정</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="shop_title" class="form-label">쇼핑몰 제목</label>
                <input type="text" name="formData[title]" id="shop_title" class="form-control"
                       value="<?= htmlspecialchars($config['title'] ?? '') ?>" placeholder="쇼핑몰 이름">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="default_shipping_template" class="form-label">기본 배송 템플릿 <span class="text-danger">*</span></label>
                <select name="formData[default_shipping_template_id]" id="default_shipping_template" class="form-select" required>
                    <option value=""></option>
                    <?php foreach ($shippingTemplates ?? [] as $tmpl): ?>
                    <option value="<?= (int) $tmpl['shipping_id'] ?>"
                        <?= ((int)($config['default_shipping_template_id'] ?? 0)) === (int) $tmpl['shipping_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tmpl['name'] ?? '템플릿 #' . $tmpl['shipping_id']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">상품에 배송 템플릿이 지정되지 않았을 때 적용되는 기본 배송 정책 (필수)</div>
            </div>
        </div>
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="auto_confirm_days" class="form-label">자동 구매확정</label>
                <div class="input-group">
                    <input type="number" name="formData[auto_confirm_days]" id="auto_confirm_days" class="form-control"
                           value="<?= (int)($config['auto_confirm_days'] ?? 8) ?>" min="0">
                    <span class="input-group-text">일</span>
                </div>
                <div class="form-text">발송 후 경과 시 자동 구매확정 (0 = 사용 안 함)</div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="cart_keep_days" class="form-label">장바구니 보관 기간 (회원)</label>
                <div class="input-group">
                    <input type="number" name="formData[cart_keep_days]" id="cart_keep_days" class="form-control"
                           value="<?= (int)($config['cart_keep_days'] ?? 15) ?>" min="1">
                    <span class="input-group-text">일</span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="guest_cart_keep_days" class="form-label">장바구니 보관 기간 (비회원)</label>
                <div class="input-group">
                    <input type="number" name="formData[guest_cart_keep_days]" id="guest_cart_keep_days" class="form-control"
                           value="<?= (int)($config['guest_cart_keep_days'] ?? 7) ?>" min="1">
                    <span class="input-group-text">일</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($contentSkins)): ?>
<?php
// 기능(폴더명) → 표시 라벨
$contentSkinLabels = [
    'Product' => '상품', 'Cart' => '장바구니', 'Order' => '주문',
    'Wishlist' => '찜한상품', 'Review' => '구매후기', 'Inquiry' => '상품문의',
    'Coupon' => '쿠폰', 'Exhibition' => '기획전', 'Mypage' => '마이페이지', 'Ui' => '공용 UI',
];
?>
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-palette text-pastel-purple"></i>
        <span>프론트 스킨</span>
    </div>
    <div class="card-body">
        <div class="alert alert-secondary small mb-3">
            기능별 프론트 페이지에 적용할 스킨입니다. 선택한 스킨에 해당 화면이 없으면 <code>basic</code>으로 자동 대체됩니다.
        </div>
        <div class="row gy-3">
            <?php foreach ($contentSkins as $feature => $skins): ?>
            <?php $fkey = strtolower($feature); $cur = $currentContentSkins[$fkey] ?? 'basic'; ?>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="shop_skin_<?= htmlspecialchars($fkey) ?>" class="form-label">
                    <?= htmlspecialchars($contentSkinLabels[$feature] ?? $feature) ?>
                    <small class="text-muted">(<?= htmlspecialchars($feature) ?>)</small>
                </label>
                <select name="skins[<?= htmlspecialchars($fkey) ?>]" id="shop_skin_<?= htmlspecialchars($fkey) ?>" class="form-select">
                    <?php foreach ($skins as $skin): ?>
                    <option value="<?= htmlspecialchars($skin) ?>"<?= $cur === $skin ? ' selected' : '' ?>>
                        <?= htmlspecialchars($skin) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
