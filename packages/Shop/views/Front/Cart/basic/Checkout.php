<?php
/**
 * 체크아웃 (프론트)
 *
 * @var array $cartItems  주문 상품 목록
 * @var array $totals     합계 (totalPrice, shippingFee, totalPoint, totalQuantity, grandTotal)
 * @var array $gateways   PG 목록 [key => meta]
 * @var array $member     회원 정보 배열 (비회원이면 null)
 * @var bool $isGuest     비회원 주문 모드 여부
 * @var string $checkoutMode 'cart' | 'direct'
 * @var array $addresses  저장된 배송지 목록
 * @var array|null $defaultAddress 기본 배송지
 * @var array $orderFields 주문 추가 필드 목록
 */

$cartItems = $cartItems ?? [];
$totals = $totals ?? ['totalPrice' => 0, 'shippingFee' => 0, 'totalPoint' => 0, 'totalQuantity' => 0, 'grandTotal' => 0];
$pointUsage = $pointUsage ?? ['enabled' => false, 'unit' => 100, 'min' => 0, 'max' => 0, 'balance' => 0];
$gateways = $gateways ?? [];
$member = is_array($member ?? null) ? $member : [];
$isGuest = $isGuest ?? false;
$addresses = $addresses ?? [];
$defaultAddress = $defaultAddress ?? null;
$prefill = is_array($defaultAddress) ? $defaultAddress : [];
$orderFields = $orderFields ?? [];

$this->assets->addCss('/serve/package/Shop/views/Front/Cart/basic/_assets/css/cart-checkout.css');
?>

<div class="shop-checkout">
    <h2 class="shop-checkout__title">주문/결제</h2>

    <div class="shop-checkout__layout">
        <div class="shop-checkout__main">

            <!-- ── 주문인 정보 (회원·비회원 공통) ── -->
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">주문인 정보</div>
                <div class="shop-checkout__section-body">
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">주문인<span class="required">*</span></span>
                        <div class="shop-checkout__input-wrap">
                            <input type="text" id="ordererName" class="shop-checkout__input shop-checkout__input--short" value="<?= htmlspecialchars((string) ($member['nickname'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">연락처<span class="required">*</span></span>
                        <div class="shop-checkout__input-wrap">
                            <input type="tel" id="ordererPhone" class="shop-checkout__input shop-checkout__input--short mask-hp">
                        </div>
                    </div>
                    <?php if ($isGuest): ?>
                    <div class="shop-checkout__address-footer">
                        <div class="shop-checkout__field-help">비회원 주문은 입력하신 주문인·연락처로 로그인 없이 주문을 조회할 수 있습니다.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── 배송지 정보 ── -->
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">
                    <span>배송지 정보</span>
                    <label class="shop-checkout__save-label">
                        <input type="checkbox" id="chkSameAsOrderer"> 주문인과 동일
                    </label>
                </div>
                <div class="shop-checkout__section-body">
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">수령인<span class="required">*</span></span>
                        <div class="shop-checkout__input-wrap">
                            <input type="text" id="recipientName" class="shop-checkout__input shop-checkout__input--short" value="<?= htmlspecialchars((string) ($prefill['recipient_name'] ?? $member['nickname'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">연락처<span class="required">*</span></span>
                        <div class="shop-checkout__input-wrap">
                            <input type="tel" id="recipientPhone" class="shop-checkout__input shop-checkout__input--short mask-hp" value="<?= htmlspecialchars((string) ($prefill['recipient_phone'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">우편번호<span class="required">*</span></span>
                        <div class="shop-checkout__input-wrap">
                            <div class="shop-checkout__zip-wrap">
                                <input type="text" id="shippingZip" class="shop-checkout__input shop-checkout__input--readonly" readonly value="<?= htmlspecialchars((string) ($prefill['zip_code'] ?? '')) ?>">
                                <button type="button" class="shop-checkout__zip-btn" id="btnZipMain">검색</button>
                            </div>
                        </div>
                    </div>
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">주소<span class="required">*</span></span>
                        <div class="shop-checkout__input-wrap">
                            <input type="text" id="shippingAddress1" class="shop-checkout__input shop-checkout__input--readonly shop-checkout__input--mb" readonly placeholder="기본주소" value="<?= htmlspecialchars((string) ($prefill['address1'] ?? '')) ?>">
                            <input type="text" id="shippingAddress2" class="shop-checkout__input" placeholder="상세주소 입력" value="<?= htmlspecialchars((string) ($prefill['address2'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="shop-checkout__form-row">
                        <span class="shop-checkout__label">배송 메모</span>
                        <div class="shop-checkout__input-wrap">
                            <input type="text" id="orderMemo" class="shop-checkout__input" placeholder="배송 시 요청사항">
                        </div>
                    </div>
                    <?php if (!$isGuest): ?>
                    <div class="shop-checkout__address-footer">
                        <label class="shop-checkout__save-label">
                            <input type="checkbox" id="chkSetDefault"> 기본 배송지로 설정
                        </label>
                        <button type="button" class="shop-checkout__address-manage-btn shop-checkout__address-manage-btn--end" id="btnOpenAddressModal">배송지 선택</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── 주문 상품 ── -->
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">주문 상품 (<?= count($cartItems) ?>건)</div>
                <div class="shop-checkout__section-body shop-checkout__section-body--compact">
                    <?php foreach ($cartItems as $item):
                        $product = $item['product'] ?? [];
                        $productName = (string) (is_array($product) ? ($product['goods_name'] ?? '') : ($item['goods_name'] ?? '상품'));
                        $imgData = $item['product_image'] ?? null;
                        $productImage = is_array($imgData) ? (string) ($imgData['image_url'] ?? '') : (string) ($imgData ?? '');
                        $optionLabel = (string) ($item['option_label'] ?? $item['option_code'] ?? '');
                    ?>
                    <div class="shop-checkout__item">
                        <?php if ($productImage): ?>
                            <div class="shop-checkout__item-image"><img src="<?= htmlspecialchars($productImage) ?>" alt=""></div>
                        <?php else: ?>
                            <div class="shop-checkout__item-image--empty">&#128230;</div>
                        <?php endif; ?>
                        <div class="shop-checkout__item-info">
                            <div class="shop-checkout__item-name"><?= htmlspecialchars($productName) ?></div>
                            <?php if ($optionLabel): ?>
                                <div class="shop-checkout__item-option"><?= htmlspecialchars($optionLabel) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="shop-checkout__item-qty"><?= (int) $item['quantity'] ?>개</div>
                        <div class="shop-checkout__item-price"><?= number_format((int) $item['total_price']) ?>원</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── 쿠폰 적용 ── -->
            <?php if (!$isGuest): ?>
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">
                    쿠폰
                    <span id="couponApplied" class="shop-checkout__coupon-badge" style="display:none"></span>
                </div>
                <div class="shop-checkout__section-body shop-checkout__section-body--tight">
                    <div id="couponArea">
                        <button type="button" class="shop-checkout__coupon-select-btn" id="btnSelectCoupon">쿠폰 선택</button>
                    </div>
                    <!-- 적용된 쿠폰 칩 목록 (복수) -->
                    <div id="couponSelectedList"></div>
                </div>
            </div>

            <!-- 쿠폰 선택 모달 -->
            <div class="shop-checkout__coupon-modal-overlay" id="couponModalOverlay">
                <div class="shop-checkout__coupon-modal">
                    <div class="shop-checkout__coupon-modal-header">
                        <h3>쿠폰 선택</h3>
                        <button type="button" class="shop-checkout__coupon-modal-close" id="couponModalClose" aria-label="닫기"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="shop-checkout__coupon-modal-body" id="couponModalBody">
                        <div class="shop-checkout__modal-loading">적용 가능한 쿠폰을 불러오는 중...</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── 포인트 사용 ── -->
            <?php if (!$isGuest && !empty($pointUsage['enabled'])): ?>
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">
                    포인트 사용
                    <span class="shop-checkout__point-balance">보유 <?= number_format((int) $pointUsage['balance']) ?>P</span>
                </div>
                <div class="shop-checkout__section-body shop-checkout__section-body--tight">
                    <div class="shop-checkout__point-input-row">
                        <input type="number" id="pointUseInput" class="shop-checkout__input"
                               min="0" step="<?= (int) $pointUsage['unit'] ?>" value="0" placeholder="0" inputmode="numeric">
                        <span class="shop-checkout__point-unit">P</span>
                        <button type="button" class="shop-checkout__coupon-select-btn shop-checkout__coupon-select-btn--inline" id="pointUseAllBtn">전액사용</button>
                    </div>
                    <div id="pointUseMsg" class="shop-checkout__point-msg"></div>
                    <div class="shop-checkout__field-help">
                        <?= number_format((int) $pointUsage['unit']) ?>P 단위로 사용 가능<?php
                            if (!empty($pointUsage['min'])) echo ' · 최소 ' . number_format((int) $pointUsage['min']) . 'P';
                            if (!empty($pointUsage['max'])) echo ' · 최대 ' . number_format((int) $pointUsage['max']) . 'P';
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── 추가 정보 (주문 커스텀 필드) ── -->
            <?php if (!empty($orderFields)): ?>
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">추가 정보</div>
                <div class="shop-checkout__section-body">
                    <?php foreach ($orderFields as $field): ?>
                        <?= \Mublo\Service\CustomField\CustomFieldRenderer::render($field, null, [
                            'namePrefix' => 'orderFields',
                            'idPrefix' => 'of_',
                        ]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── 결제 수단 ── -->
            <?php $hasBank = !empty($bankEnabled) && !empty($bankAccounts); ?>
            <div class="shop-checkout__section">
                <div class="shop-checkout__section-header">결제 수단</div>
                <div class="shop-checkout__section-body">
                    <?php if (empty($gateways) && !$hasBank): ?>
                        <div class="shop-checkout__no-gateway">등록된 결제 수단이 없습니다.</div>
                    <?php else: ?>
                        <div class="shop-checkout__gateway-list">
                            <?php $first = true; foreach ($gateways as $key => $gw): ?>
                                <input type="radio" name="payment_gateway" id="pg_<?= htmlspecialchars($key) ?>"
                                       value="<?= htmlspecialchars($key) ?>" class="shop-checkout__gateway-item"
                                       <?= $first ? 'checked' : '' ?>>
                                <label for="pg_<?= htmlspecialchars($key) ?>" class="shop-checkout__gateway-label">
                                    <?= htmlspecialchars($gw['label'] ?? $key) ?>
                                </label>
                            <?php $first = false; endforeach; ?>

                            <?php if ($hasBank): ?>
                                <input type="radio" name="payment_gateway" id="pg_bank"
                                       value="bank" class="shop-checkout__gateway-item"
                                       <?= $first ? 'checked' : '' ?>>
                                <label for="pg_bank" class="shop-checkout__gateway-label">무통장입금</label>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasBank): ?>
                        <!-- 무통장입금 선택 시 입금 계좌 안내 -->
                        <div class="shop-checkout__bank-accounts" id="bankAccountWrap" style="display:none">
                            <label for="bankAccountSelect" class="shop-checkout__bank-label">입금하실 계좌를 선택하세요</label>
                            <select name="bank_account" id="bankAccountSelect" class="shop-checkout__bank-select">
                                <?php foreach ($bankAccounts as $i => $acc): ?>
                                <option value="<?= $i ?>"><?= htmlspecialchars(trim(($acc['bank'] ?? '') . ' ' . ($acc['account'] ?? '') . ' (' . ($acc['holder'] ?? '') . ')')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── 사이드바 ── -->
        <div class="shop-checkout__sidebar">
            <div class="shop-checkout__summary">
                <div class="shop-checkout__summary-header">결제 금액</div>
                <div class="shop-checkout__summary-body">
                    <div class="shop-checkout__summary-row"><span>상품금액</span><span><?= number_format((int) ($totals['totalPrice'] ?? 0)) ?>원</span></div>
                    <div class="shop-checkout__summary-row"><span>배송비</span><span id="summShippingFee"><?php if (!empty($totals['unresolved'])): ?><span class="text-danger">미설정</span><?php else: ?><?= number_format((int) ($totals['shippingFee'] ?? 0)) ?>원<?php endif; ?></span></div>
                    <div class="shop-checkout__summary-row" id="summExtraShipRow" style="display:none"><span>추가배송비</span><span id="summExtraShipAmount"></span></div>
                    <div class="shop-checkout__summary-row" id="summCouponRow" style="display:none"><span>쿠폰 할인</span><span class="shop-checkout__summary-amount--minus" id="summCouponAmount"></span></div>
                    <div class="shop-checkout__summary-row" id="summPointRow" style="display:none"><span>포인트 사용</span><span class="shop-checkout__summary-amount--minus" id="summPointAmount"></span></div>
                    <?php if (($totals['totalPoint'] ?? 0) > 0): ?>
                    <div class="shop-checkout__summary-row"><span>포인트 적립 예정</span><span>+<?= number_format((int) $totals['totalPoint']) ?>P</span></div>
                    <?php endif; ?>
                    <div class="shop-checkout__summary-divider"></div>
                    <div class="shop-checkout__summary-total"><span>총 결제금액</span><span class="shop-checkout__summary-total-price" id="summGrandTotal"><?= number_format((int) ($totals['grandTotal'] ?? 0)) ?>원</span></div>
                </div>
                <?php if (!empty($checkoutPolicies)): ?>
                <div class="shop-checkout__agreements" id="checkoutAgreements">
                    <label class="shop-checkout__agree-all">
                        <input type="checkbox" id="agreeAll">
                        <strong>주문 내용을 확인했으며 아래 약관에 모두 동의합니다.</strong>
                    </label>
                    <?php foreach ($checkoutPolicies as $policy): ?>
                    <div class="shop-checkout__agree-item">
                        <label class="shop-checkout__agree-label">
                            <input type="checkbox" class="shop-checkout__agree-check"
                                   data-policy-id="<?= (int) $policy['policy_id'] ?>">
                            <span><?= htmlspecialchars($policy['title']) ?> <span class="shop-checkout__agree-required">(필수)</span></span>
                        </label>
                        <?php if (!empty($policy['content'])): ?>
                        <button type="button" class="shop-checkout__agree-toggle" data-target="policy-<?= (int) $policy['policy_id'] ?>">약관 보기</button>
                        <div class="shop-checkout__agree-content" id="policy-<?= (int) $policy['policy_id'] ?>" style="display:none"><?= $policy['content'] ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="shop-checkout__summary-footer">
                    <?php if (!empty($totals['unresolved'])): ?>
                        <button type="button" class="shop-checkout__pay-btn" id="btnPay" disabled title="배송 정책이 설정되지 않아 결제할 수 없습니다.">결제하기</button>
                        <p class="text-danger text-center mt-2 small">배송 정책이 설정되지 않았습니다. 관리자에게 문의해주세요.</p>
                    <?php else: ?>
                        <button type="button" class="shop-checkout__pay-btn" id="btnPay">결제하기</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$isGuest): ?>
<!-- ── 배송지 관리 모달 ── -->
<div class="shop-checkout__address-modal-overlay" id="addressModalOverlay">
    <div class="shop-checkout__address-modal">
        <div class="shop-checkout__address-modal-header">
            <h3>배송지 관리</h3>
            <button type="button" class="shop-checkout__address-modal-close" id="addressModalClose" aria-label="닫기"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="shop-checkout__address-modal-body">
            <button type="button" class="shop-checkout__address-modal-add-btn" id="addressFormToggle">+ 새 배송지 추가</button>

            <!-- 추가/수정 폼 (토글) -->
            <div class="shop-checkout__address-modal-form" id="addressForm" style="display:none">
                <div class="shop-checkout__address-modal-form-title" id="addressFormTitle">새 배송지 추가</div>
                <input type="hidden" id="mAddressId" value="0">
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label">배송지명</span>
                    <input type="text" id="mAddressName" class="shop-checkout__address-modal-form-input shop-checkout__address-modal-form-input--short" placeholder="자택, 직장 등">
                </div>
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label">수령인 *</span>
                    <input type="text" id="mRecipient" class="shop-checkout__address-modal-form-input shop-checkout__address-modal-form-input--short">
                </div>
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label">연락처</span>
                    <input type="tel" id="mPhone" class="shop-checkout__address-modal-form-input shop-checkout__address-modal-form-input--short mask-hp" placeholder="010-0000-0000">
                </div>
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label">우편번호 *</span>
                    <div class="shop-checkout__address-modal-form-zip">
                        <input type="text" id="mZip" class="shop-checkout__address-modal-form-input" readonly>
                        <button type="button" class="shop-checkout__address-modal-form-zip-btn" id="btnZipModal">검색</button>
                    </div>
                </div>
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label">주소 *</span>
                    <input type="text" id="mAddress1" class="shop-checkout__address-modal-form-input" readonly placeholder="기본주소">
                </div>
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label">상세주소</span>
                    <input type="text" id="mAddress2" class="shop-checkout__address-modal-form-input" placeholder="상세주소">
                </div>
                <div class="shop-checkout__address-modal-form-row">
                    <span class="shop-checkout__address-modal-form-label"></span>
                    <label class="shop-checkout__save-label">
                        <input type="checkbox" id="mIsDefault"> 기본 배송지로 설정
                    </label>
                </div>
                <div class="shop-checkout__address-modal-form-btns">
                    <button type="button" class="shop-checkout__address-modal-form-save" id="addressFormSave">저장</button>
                    <button type="button" class="shop-checkout__address-modal-form-cancel" id="addressFormCancel">취소</button>
                </div>
            </div>

            <!-- 저장된 배송지 목록 -->
            <div class="shop-checkout__address-modal-list" id="addressList"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$checkoutScripts = $checkoutScripts ?? [];
foreach ($checkoutScripts as $pgScript): ?>
<script><?= $pgScript ?></script>
<?php endforeach; ?>
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script type="application/json" id="checkout-data"><?= json_encode([
    'isGuest'      => (bool) $isGuest,
    'checkoutMode' => (string) $checkoutMode,
    'cartItemIds'  => array_column($cartItems, 'cart_item_id'),
    'totals'       => [
        'grandTotal'   => (int) ($totals['grandTotal'] ?? 0),
        'productTotal' => (int) ($totals['totalPrice'] ?? 0),
        'shippingFee'  => (int) ($totals['shippingFee'] ?? 0),
    ],
    'pointUsage'   => [
        'enabled' => !$isGuest && !empty($pointUsage['enabled']),
        'unit'    => max(1, (int) ($pointUsage['unit'] ?? 1)),
        'min'     => (int) ($pointUsage['min'] ?? 0),
        'max'     => (int) ($pointUsage['max'] ?? 0),
        'balance' => (int) ($pointUsage['balance'] ?? 0),
    ],
], JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= asset('/serve/package/Shop/views/Front/Cart/basic/_assets/js/checkout.js') ?>"></script>
<?php if (!empty($orderFields)): ?>
<?= \Mublo\Service\CustomField\CustomFieldRenderer::renderFileScript('/shop/checkout/upload-file') ?>
<?php endif; ?>
