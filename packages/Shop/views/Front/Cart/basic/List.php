<?php
/**
 * 장바구니 (프론트)
 * @var array $groups 배송 그룹별 상품 데이터 [groupKey => { template_id, template_name, shipping_fee, goods }]
 * @var array $totals 합계 정보 { itemTotal, shippingTotal, pointTotal, grandTotal }
 * @var array $productData 옵션 모달용 상품 데이터
 */
$this->assets->addCss('/serve/package/Shop/views/Front/Cart/basic/_assets/css/cart-list.css');
?>

<div class="shop-cart">
    <h1 class="shop-cart__title">장바구니</h1>

    <?php if (empty($groups)): ?>
        <div class="shop-cart__empty">
            <div class="shop-cart__empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    <line x1="10" y1="11" x2="16" y2="11"/>
                </svg>
            </div>
            <p class="shop-cart__empty-text">장바구니가 비어있습니다.</p>
            <a href="/shop/products" class="shop-cart__empty-btn">쇼핑 계속하기</a>
        </div>
    <?php else: ?>
        <form id="cartForm">
            <?php foreach ($groups as $groupKey => $group): ?>
                <div class="shop-cart__group" data-group="<?= htmlspecialchars($groupKey) ?>">
                    <div class="shop-cart__group-header">
                        <span class="shop-cart__group-title">
                            <span class="shop-cart__icon shop-cart__icon--truck">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                                    <circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
                                </svg>
                            </span>
                            <?= htmlspecialchars($group['template_name']) ?>
                        </span>
                        <?php if (!empty($group['unresolved'])): ?>
                            <span class="shop-cart__group-shipping shop-cart__group-shipping--unset text-danger" data-role="group-shipping">배송 정책 미설정</span>
                        <?php elseif ($group['shipping_fee'] > 0): ?>
                            <span class="shop-cart__group-shipping" data-role="group-shipping">배송비 <?= number_format($group['shipping_fee']) ?>원</span>
                        <?php else: ?>
                            <span class="shop-cart__group-shipping shop-cart__group-shipping--free" data-role="group-shipping">무료배송</span>
                        <?php endif; ?>
                    </div>

                    <div class="shop-cart__select-header">
                        <label><input type="checkbox" class="group-check" data-group="<?= htmlspecialchars($groupKey) ?>" checked> 전체선택</label>
                    </div>

                    <?php foreach ($group['goods'] as $goodsId => $goods): ?>
                        <?php foreach ($goods['options'] as $opt): ?>
                            <?php
                                $soldout = !$goods['goods_info']['is_available'];
                                $priceChanged = ($opt['price_changed'] ?? false) && !$soldout;
                                $blocked = $soldout || $priceChanged;
                            ?>
                            <div class="shop-cart__item <?= $soldout ? 'shop-cart__item--unavailable' : '' ?><?= $priceChanged ? ' shop-cart__item--changed' : '' ?>"
                                 data-cart-id="<?= $opt['cart_item_id'] ?>" data-group="<?= htmlspecialchars($groupKey) ?>">
                                <div class="shop-cart__item-check">
                                    <input type="checkbox" class="cart-check" name="cart_item_ids[]" value="<?= $opt['cart_item_id'] ?>" <?= $blocked ? 'disabled' : 'checked' ?>>
                                </div>
                                <?php
                                    $imgData = $goods['goods_info']['product_image'] ?? null;
                                    $productImage = is_array($imgData)
                                        ? (string) ($imgData['thumbnail_url'] ?? $imgData['image_url'] ?? '')
                                        : (string) ($imgData ?? '');
                                    $productUrl = '/shop/products/' . (int) $goodsId;
                                ?>
                                <?php if ($productImage !== ''): ?>
                                    <a class="shop-cart__item-image" href="<?= $productUrl ?>">
                                        <img src="<?= htmlspecialchars($productImage) ?>" alt="">
                                    </a>
                                <?php else: ?>
                                    <a class="shop-cart__item-image shop-cart__item-image--empty" href="<?= $productUrl ?>">
                                        <span class="shop-cart__icon shop-cart__icon--img">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                            </svg>
                                        </span>
                                    </a>
                                <?php endif; ?>
                                <div class="shop-cart__item-info">
                                    <a class="shop-cart__item-name" href="<?= $productUrl ?>"><?= htmlspecialchars($goods['goods_info']['goods_name']) ?></a>
                                    <?php if ($opt['option_label'] || $goods['goods_info']['option_mode'] !== 'NONE'): ?>
                                        <div class="shop-cart__item-option">
                                            <?php if ($goods['goods_info']['option_mode'] !== 'NONE'): ?>
                                                <button type="button"
                                                        class="shop-cart__option-edit"
                                                        onclick="ShopCart.changeOption(<?= $opt['cart_item_id'] ?>, <?= $goodsId ?>, <?= (int) $opt['option_id'] ?>)">
                                                    옵션변경
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($opt['option_label']): ?>
                                                <span><?= htmlspecialchars($opt['option_label']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($soldout): ?>
                                        <span class="shop-cart__item-badge">품절</span>
                                    <?php elseif ($priceChanged): ?>
                                        <span class="shop-cart__item-badge shop-cart__item-badge--price">가격변동</span>
                                    <?php endif; ?>
                                </div>
                                <div class="shop-cart__item-qty">
                                    <button type="button" class="shop-cart__qty-btn" onclick="ShopCart.updateQty(<?= $opt['cart_item_id'] ?>, -1)"><i class="bi bi-dash-lg"></i></button>
                                    <input type="text" class="shop-cart__qty-input" value="<?= $opt['quantity'] ?>" readonly>
                                    <button type="button" class="shop-cart__qty-btn" onclick="ShopCart.updateQty(<?= $opt['cart_item_id'] ?>, 1)"><i class="bi bi-plus-lg"></i></button>
                                </div>
                                <div class="shop-cart__item-price">
                                    <?php if ($priceChanged): ?>
                                        <del class="shop-cart__item-price-old"><?= number_format($opt['total_price']) ?>원</del>
                                        <span class="shop-cart__item-price-new"><?= number_format($opt['current_total_price']) ?>원</span>
                                        <button type="button" class="shop-cart__refresh-btn" onclick="ShopCart.refreshPrice(<?= $opt['cart_item_id'] ?>)">현재가로 담기</button>
                                    <?php else: ?>
                                        <?= number_format($opt['total_price']) ?>원
                                    <?php endif; ?>
                                </div>
                                <div class="shop-cart__item-remove">
                                    <button type="button" class="shop-cart__remove-btn" onclick="ShopCart.remove(<?= $opt['cart_item_id'] ?>)"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($goods['extras'] as $ext): ?>
                            <div class="shop-cart__item shop-cart__item--extra<?= !$goods['goods_info']['is_available'] ? ' shop-cart__item--unavailable' : '' ?>"
                                 data-cart-id="<?= $ext['cart_item_id'] ?>" data-group="<?= htmlspecialchars($groupKey) ?>">
                                <div class="shop-cart__item-check">
                                    <input type="checkbox" class="cart-check" name="cart_item_ids[]" value="<?= $ext['cart_item_id'] ?>" <?= !$goods['goods_info']['is_available'] ? 'disabled' : 'checked' ?>>
                                </div>
                                <div class="shop-cart__item-info">
                                    <div class="shop-cart__item-extra-label">
                                        <span class="shop-cart__icon--plus">+</span>
                                        추가옵션: <?= htmlspecialchars($ext['option_label'] ?? $ext['option_code'] ?? '') ?>
                                    </div>
                                </div>
                                <div class="shop-cart__item-qty">
                                    <button type="button" class="shop-cart__qty-btn" onclick="ShopCart.updateQty(<?= $ext['cart_item_id'] ?>, -1)"><i class="bi bi-dash-lg"></i></button>
                                    <input type="text" class="shop-cart__qty-input" value="<?= $ext['quantity'] ?>" readonly>
                                    <button type="button" class="shop-cart__qty-btn" onclick="ShopCart.updateQty(<?= $ext['cart_item_id'] ?>, 1)"><i class="bi bi-plus-lg"></i></button>
                                </div>
                                <div class="shop-cart__item-price"><?= number_format($ext['total_price']) ?>원</div>
                                <div class="shop-cart__item-remove">
                                    <button type="button" class="shop-cart__remove-btn" onclick="ShopCart.remove(<?= $ext['cart_item_id'] ?>)"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    <?php endforeach; ?>

                    <?php
                    // 조건부 무료배송 유도: 기준 미달 시 부족액만 안내 (쿠팡식)
                    // AJAX 재계산으로 토글되도록 컨테이너는 항상 렌더(미해당 시 hidden)
                    $remain = 0;
                    if (empty($group['unresolved'])
                        && ($group['shipping_method'] ?? '') === 'COND'
                        && (int) ($group['free_threshold'] ?? 0) > 0
                        && (int) ($group['shipping_fee'] ?? 0) > 0
                    ) {
                        $remain = max(0, (int) $group['free_threshold'] - (int) ($group['group_total'] ?? 0));
                    }
                    ?>
                    <div class="shop-cart__group-free-nudge" data-role="group-nudge"<?= $remain > 0 ? '' : ' hidden' ?>>
                        <i class="bi bi-truck"></i>
                        <strong data-role="nudge-remain"><?= number_format($remain) ?>원</strong> 더 담으면 무료배송!
                    </div>
                </div>
            <?php endforeach; ?>
        </form>

        <!-- 합계 -->
        <div class="shop-cart__summary">
            <div class="shop-cart__summary-row">
                <div class="shop-cart__summary-item">
                    <div class="shop-cart__summary-label">상품금액</div>
                    <div class="shop-cart__summary-value" id="cartItemTotal"><?= number_format($totals['itemTotal']) ?>원</div>
                </div>
                <div class="shop-cart__summary-op">+</div>
                <div class="shop-cart__summary-item">
                    <div class="shop-cart__summary-label">배송비</div>
                    <div class="shop-cart__summary-value" id="cartShippingTotal">
                        <?php if (!empty($totals['unresolved'])): ?>
                            <span class="text-danger">미설정</span>
                        <?php else: ?>
                            <?= number_format((int) ($totals['shippingTotal'] ?? 0)) ?>원
                        <?php endif; ?>
                    </div>
                </div>
                <div class="shop-cart__summary-op">=</div>
                <div class="shop-cart__summary-item">
                    <div class="shop-cart__summary-label">총 결제금액</div>
                    <div class="shop-cart__summary-value shop-cart__summary-value--primary" id="cartGrandTotal"><?= number_format($totals['grandTotal']) ?>원</div>
                </div>
            </div>
        </div>

        <div class="shop-cart__actions">
            <a href="/shop/products" class="shop-cart__continue-btn">쇼핑 계속하기</a>
            <button type="button" class="shop-cart__order-btn" id="cartOrderBtn" onclick="ShopCart.checkout()"
                <?= !empty($totals['unresolved']) ? 'disabled title="배송 정책이 설정되지 않아 주문할 수 없습니다."' : '' ?>>주문하기</button>
        </div>
        <p class="text-danger text-center mt-2 small" id="cartUnresolvedMsg"<?= !empty($totals['unresolved']) ? '' : ' hidden' ?>>배송 정책이 설정되지 않았습니다. 관리자에게 문의해주세요.</p>
    <?php endif; ?>
</div>

<!-- 옵션 변경 모달 -->
<div class="shop-cart__modal-overlay" id="optionChangeOverlay">
    <div class="shop-cart__modal">
        <div class="shop-cart__modal-header">
            <span class="shop-cart__modal-title">옵션 변경</span>
            <button type="button" class="shop-cart__modal-close" onclick="ShopCart.closeOptionModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="shop-cart__modal-body" id="optionChangeBody"></div>
        <div class="shop-cart__modal-footer">
            <button type="button" class="shop-cart__modal-btn shop-cart__modal-btn--cancel" onclick="ShopCart.closeOptionModal()">취소</button>
            <button type="button" class="shop-cart__modal-btn shop-cart__modal-btn--confirm" id="optionChangeConfirm">변경</button>
        </div>
    </div>
</div>

<script type="application/json" id="cart-product-data"><?= json_encode($productData ?? [], JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= asset('/serve/package/Shop/views/Front/Cart/basic/_assets/js/cart-list.js') ?>"></script>
