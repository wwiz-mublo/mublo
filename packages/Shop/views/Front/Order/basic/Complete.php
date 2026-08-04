<?php
/**
 * 주문 완료 (프론트)
 * @var array $order      주문 정보 (grand_total, shipping_fee, total_price, payment_method 등)
 * @var array $orderItems 주문 상품 목록
 * @var string $receiptUrl PG가 제공한 영수증 URL (없으면 빈 문자열)
 * @var string|null $message 오류 메시지 (주문 없을 때)
 */
$order      = $order ?? [];
$orderItems = $orderItems ?? [];
$receiptUrl = $receiptUrl ?? '';
$message    = $message ?? null;
$this->assets->addCss('/serve/package/Shop/views/Front/Order/basic/_assets/css/order-complete.css');
?>

<?php if ($message): ?>
<div class="shop-order-complete text-center">
    <i class="bi bi-x-circle text-danger" style="font-size:4rem"></i>
    <h2 class="mt-3">주문 정보를 찾을 수 없습니다</h2>
    <p class="text-muted"><?= htmlspecialchars($message) ?></p>
    <a href="/shop/products" class="shop-complete__btn shop-complete__btn--primary mt-3" style="display:inline-flex;flex:0 0 auto;padding:10px 28px">쇼핑 계속하기</a>
</div>
<?php return; endif; ?>


<div class="shop-complete text-center">
    <i class="bi bi-check-circle-fill shop-complete__icon"></i>
    <div class="shop-complete__title">주문이 완료되었습니다</div>
    <div class="shop-complete__orderno">주문번호: <strong><?= htmlspecialchars($order['order_no'] ?? '') ?></strong></div>

    <?php if (!empty($orderItems)): ?>
    <div class="shop-complete__card text-start">
        <div class="shop-complete__card-title">주문 상품</div>
        <?php foreach ($orderItems as $item): ?>
        <div class="shop-complete__item">
            <?php if (!empty($item['goods_image'])): ?>
            <img src="<?= htmlspecialchars($item['goods_image']) ?>" alt="" class="shop-complete__item-img">
            <?php else: ?>
            <div class="shop-complete__item-img shop-complete__item-img--empty"><i class="bi bi-image"></i></div>
            <?php endif; ?>
            <div class="shop-complete__item-info">
                <div class="shop-complete__item-name"><?= htmlspecialchars($item['goods_name'] ?? '') ?></div>
                <?php $qty = (int) ($item['quantity'] ?? 1); ?>
                <?php if (!empty($item['option_name'])): ?>
                <div class="shop-complete__item-option"><?= htmlspecialchars($item['option_name']) ?> <?= $qty ?>개</div>
                <?php else: ?>
                <div class="shop-complete__item-option">수량 <?= $qty ?>개</div>
                <?php endif; ?>
            </div>
            <div class="shop-complete__item-price"><?= number_format((int) ($item['total_price'] ?? 0)) ?>원</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="shop-complete__card text-start">
        <div class="shop-complete__card-title">결제 정보</div>
        <div class="shop-complete__row">
            <span class="shop-complete__label">상품 금액</span>
            <span><?= number_format((int) ($order['total_price'] ?? 0)) ?>원</span>
        </div>
<?php
            // 배송비 분해: shipping_fee(합계)에서 도서산간 추가비를 분리 표시
            $__shipTotal = (int) ($order['shipping_fee'] ?? 0);
            $__shipBd = $order['shipping_breakdown'] ?? null;
            if (is_string($__shipBd)) { $__shipBd = json_decode($__shipBd, true); }
            $__extraShip = 0;
            if (is_array($__shipBd)) { foreach ($__shipBd as $__g) { $__extraShip += (int) ($__g['extra_fee'] ?? 0); } }
            $__baseShip = $__shipTotal - $__extraShip;
        ?>
        <div class="shop-complete__row">
            <span class="shop-complete__label">배송비</span>
            <span><?= number_format($__baseShip) ?>원</span>
        </div>
        <?php if ($__extraShip > 0): ?>
        <div class="shop-complete__row">
            <span class="shop-complete__label">추가배송비</span>
            <span><?= number_format($__extraShip) ?>원</span>
        </div>
        <?php endif; ?>
        <?php if ((int) ($order['coupon_discount'] ?? 0) > 0): ?>
        <div class="shop-complete__row">
            <span class="shop-complete__label">쿠폰 할인</span>
            <span style="color:#e53e3e">-<?= number_format((int) $order['coupon_discount']) ?>원</span>
        </div>
        <?php endif; ?>
        <?php if ((int) ($order['point_used'] ?? 0) > 0): ?>
        <div class="shop-complete__row">
            <span class="shop-complete__label">포인트 사용</span>
            <span style="color:#e53e3e">-<?= number_format((int) $order['point_used']) ?>원</span>
        </div>
        <?php endif; ?>
        <div class="shop-complete__row shop-complete__row--total">
            <span>결제 금액</span>
            <span><?= number_format((int) ($order['grand_total'] ?? 0)) ?>원</span>
        </div>
        <div class="shop-complete__row mt-2">
            <span class="shop-complete__label">결제 수단</span>
            <?php
                $pm = $order['payment_method'] ?? '';
                $pmLabel = \Mublo\Packages\Shop\Enum\PaymentMethod::tryFrom($pm)?->label() ?? ($pm ?: '-');
            ?>
            <span><?= htmlspecialchars($pmLabel) ?></span>
        </div>
        <?php if ($receiptUrl !== ''): ?>
        <div class="shop-complete__row">
            <span class="shop-complete__label">영수증</span>
            <span>
                <a href="<?= htmlspecialchars($receiptUrl) ?>" target="_blank" rel="noopener">
                    <i class="bi bi-receipt"></i> 영수증 보기
                </a>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <?php
        // 무통장입금: 선택한 입금계좌 안내
        $bankInfo = null;
        if ($pm === 'BANK' && !empty($order['bank_account_info'])) {
            $decoded = json_decode((string) $order['bank_account_info'], true);
            if (is_array($decoded)) {
                $bankInfo = $decoded;
            }
        }
    ?>
    <?php if ($bankInfo): ?>
    <div class="shop-complete__card shop-complete__card--bank text-start">
        <div class="shop-complete__card-title">입금 계좌 안내</div>
        <div class="shop-complete__row">
            <span class="shop-complete__label">은행</span>
            <span><?= htmlspecialchars((string) ($bankInfo['bank'] ?? '')) ?></span>
        </div>
        <div class="shop-complete__row">
            <span class="shop-complete__label">계좌번호</span>
            <span class="shop-complete__bank-account"><?= htmlspecialchars((string) ($bankInfo['account'] ?? '')) ?></span>
        </div>
        <div class="shop-complete__row">
            <span class="shop-complete__label">예금주</span>
            <span><?= htmlspecialchars((string) ($bankInfo['holder'] ?? '')) ?></span>
        </div>
        <div class="shop-complete__row shop-complete__row--total">
            <span>입금 금액</span>
            <span><?= number_format((int) ($order['grand_total'] ?? 0)) ?>원</span>
        </div>
        <p class="shop-complete__bank-notice">위 계좌로 입금하시면 확인 후 배송 준비를 시작합니다. 주문자명으로 입금해주세요.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($order['recipient_name'])): ?>
    <div class="shop-complete__card text-start">
        <div class="shop-complete__card-title">배송 정보</div>
        <div class="shop-complete__row">
            <span class="shop-complete__label">수령인</span>
            <span><?= htmlspecialchars($order['recipient_name']) ?></span>
        </div>
        <div class="shop-complete__row">
            <span class="shop-complete__label">배송지</span>
            <span><?= htmlspecialchars(trim(($order['shipping_address1'] ?? '') . ' ' . ($order['shipping_address2'] ?? ''))) ?></span>
        </div>
        <?php if (!empty($order['shipping_zip'])): ?>
        <div class="shop-complete__row">
            <span class="shop-complete__label">우편번호</span>
            <span><?= htmlspecialchars($order['shipping_zip']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="shop-complete__actions">
        <a href="/shop/orders" class="shop-complete__btn shop-complete__btn--outline">주문내역</a>
        <a href="/shop/products" class="shop-complete__btn shop-complete__btn--primary">쇼핑 계속하기</a>
    </div>
</div>
