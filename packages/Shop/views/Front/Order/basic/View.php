<?php
/**
 * 주문 상세 (프론트)
 *
 * @var array $order 주문 정보 (복호화 완료)
 * @var array $orderItems 주문 상품 목록
 * @var array $orderFieldValues 커스텀 필드 값
 * @var string $statusLabel 현재 상태 라벨
 * @var array $allStates 전체 상태 목록 (타임라인용)
 * @var string|null $message 에러 메시지
 */

use Mublo\Packages\Shop\Enum\PaymentMethod;

$isGuest = $isGuest ?? false;
// 비회원도 회원과 동일하게 주문목록으로 돌아간다.
// (/shop/orders는 비회원 세션도 처리하며, 세션 만료 시 로그인→주문조회로 자동 유도)
$listPage = (int) ($listPage ?? 1);
$listKeyword = (string) ($listKeyword ?? '');
$listParams = [];
if ($listPage > 1) { $listParams['page'] = $listPage; }
if ($listKeyword !== '') { $listParams['keyword'] = $listKeyword; }
$backUrl = '/shop/orders' . ($listParams ? '?' . http_build_query($listParams) : '');
$backLabel = '주문내역';

// 에러 상태
if (!empty($message)) { ?>
    <div class="shop-order-view" style="text-align:center;padding:60px 16px">
        <p style="font-size:1.1rem;color:#555"><?= htmlspecialchars($message) ?></p>
        <a href="<?= htmlspecialchars($backUrl) ?>" style="display:inline-block;margin-top:16px;color:#667eea">← <?= htmlspecialchars($backLabel) ?></a>
    </div>
<?php return; }

$order = $order ?? [];
$orderItems = $orderItems ?? [];
$orderFieldValues = $orderFieldValues ?? [];
$statusLabel = $statusLabel ?? '';
$allStates = $allStates ?? [];
$orderLogs = $orderLogs ?? [];
$shipments = $shipments ?? [];
$reviewMeta = $reviewMeta ?? [];
$shipmentItemNames = $shipmentItemNames ?? [];
$claimsByDetail = $claimsByDetail ?? [];
$claimableByDetail = $claimableByDetail ?? [];
$claimableQuantityByDetail = $claimableQuantityByDetail ?? [];
$exchangeOptionsByDetail = $exchangeOptionsByDetail ?? [];

$currentStatus = $order['order_status'] ?? '';
$orderNo = $order['order_no'] ?? '';
$createdAt = $order['created_at'] ?? '';

// 최종 결제 금액
$totalPrice = (int) ($order['total_price'] ?? 0);
$shippingFee = (int) ($order['shipping_fee'] ?? 0);
$couponDiscount = (int) ($order['coupon_discount'] ?? 0);
$pointUsed = (int) ($order['point_used'] ?? 0);
$taxAmount = (int) ($order['tax_amount'] ?? 0);
$finalAmount = $totalPrice - $couponDiscount - $pointUsed + $shippingFee + $taxAmount;

// 배송비 분해: shipping_fee(합계)에서 도서산간 추가비를 분리
$shippingBreakdown = $order['shipping_breakdown'] ?? null;
if (is_string($shippingBreakdown)) { $shippingBreakdown = json_decode($shippingBreakdown, true); }
$extraShippingFee = 0;
if (is_array($shippingBreakdown)) { foreach ($shippingBreakdown as $g) { $extraShippingFee += (int) ($g['extra_fee'] ?? 0); } }
$baseShippingFee = $shippingFee - $extraShippingFee;

// 결제수단 라벨
$paymentMethodRaw = $order['payment_method'] ?? '';
$paymentMethodEnum = PaymentMethod::tryFrom($paymentMethodRaw);
$paymentMethodLabel = $paymentMethodEnum ? $paymentMethodEnum->label() : $paymentMethodRaw;

// ── 타임라인: 메인 플로우 상태 추출 ──
$mainFlowActions = ['received', 'paid', 'preparing', 'shipping', 'delivered', 'confirmed'];
$cancelActions = ['cancel_requested', 'cancelled', 'return_requested', 'returned'];

$mainFlowStates = [];
foreach ($allStates as $s) {
    $action = $s['action'] ?? 'custom';
    if (in_array($action, $mainFlowActions, true)) {
        $mainFlowStates[] = $s;
    }
}
usort($mainFlowStates, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

// 현재 상태의 sort_order
$currentSortOrder = 0;
$isCancelledOrReturned = false;
$currentAction = '';
foreach ($allStates as $s) {
    if (($s['id'] ?? '') === $currentStatus) {
        $currentSortOrder = $s['sort_order'] ?? 0;
        $currentAction = $s['action'] ?? 'custom';
        if (in_array($currentAction, $cancelActions, true)) {
            $isCancelledOrReturned = true;
        }
        break;
    }
}

// 취소/반품 주문 타임라인: 설정(메인플로우 일직선)으론 분기를 못 그리므로
// 실제 상태 로그로 거쳐온 경로를 재구성한다 (과거→현재, 미래 없음).
$cancelTimeline = [];
if ($isCancelledOrReturned) {
    // 상태 id → action 매핑 (취소/반품 노드 색 구분용)
    $actionById = [];
    foreach ($allStates as $s) { $actionById[$s['id'] ?? ''] = $s['action'] ?? ''; }

    // STATUS · 주문레벨 · 실제 전이만 (아이템 로그·비전이 제외)
    $statusLogs = array_filter($orderLogs, static function ($l) {
        return ($l['change_type'] ?? '') === 'STATUS'
            && empty($l['order_detail_id'])
            && ($l['prev_status'] ?? '') !== ($l['new_status'] ?? '');
    });
    // getOrderLogs는 최신순(DESC) → 시간순(ASC)으로 뒤집어 경로 재구성
    $statusLogs = array_reverse(array_values($statusLogs));

    $seq = [];
    foreach ($statusLogs as $i => $l) {
        if ($i === 0) {
            $seq[] = ['id' => (string) ($l['prev_status'] ?? ''), 'label' => (string) ($l['prev_status_label'] ?: ($l['prev_status'] ?? ''))];
        }
        $seq[] = ['id' => (string) ($l['new_status'] ?? ''), 'label' => (string) ($l['new_status_label'] ?: ($l['new_status'] ?? ''))];
    }
    // 내부/빈 상태(attempting 등) 제외 + 연속 중복 제거
    foreach ($seq as $node) {
        if ($node['id'] === '' || $node['id'] === 'attempting') { continue; }
        if (!empty($cancelTimeline) && end($cancelTimeline)['id'] === $node['id']) { continue; }
        $node['action'] = $actionById[$node['id']] ?? '';
        $cancelTimeline[] = $node;
    }
}

// 사용자 주문취소 가능 여부: 주문접수(received)·결제완료(paid)에서만 (OrderAction::isCancellable과 동일)
$canCancel = in_array($currentAction, ['received', 'paid'], true);

// 취소 안내 문구 (실제 처리 정책은 서버 OrderCancelService가 결정; 여기선 예상 안내만)
$cancelGateway = trim((string) ($order['payment_gateway'] ?? ''));
if ($currentAction === 'received') {
    $cancelHint = '결제 전 주문으로 즉시 취소 처리됩니다.';
} elseif ($cancelGateway !== '') {
    $cancelHint = '취소 시 결제가 자동으로 취소·환불됩니다.';
} else {
    $cancelHint = '취소 요청이 접수되며, 환불이 필요하면 판매자 확인 후 처리됩니다.';
}

// 날짜 포맷
$dateFormatted = '';
if ($createdAt) {
    $ts = strtotime($createdAt);
    $dateFormatted = $ts ? date('Y.m.d H:i', $ts) : $createdAt;
}
$this->assets->addCss('/serve/package/Shop/views/Front/Order/basic/_assets/css/order-view.css');
?>

<div class="shop-order-view">

    <!-- ── 헤더 ── -->
    <div class="shop-order-view__header">
        <div class="shop-order-view__header-info">
            <span class="shop-order-view__order-no">주문번호: <?= e($orderNo) ?></span>
            <span class="shop-order-view__order-date"><?= e($dateFormatted) ?></span>
        </div>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="shop-order-view__header-back"><i class="bi bi-list"></i> <?= htmlspecialchars($backLabel) ?></a>
    </div>

    <!-- ── 상태 타임라인 ── -->
    <?php if ($isCancelledOrReturned && !empty($cancelTimeline)): ?>
        <!-- 취소/반품: 실제 상태 로그로 거쳐온 경로 (미래 없음) -->
        <div class="shop-order-view__timeline">
            <?php foreach ($cancelTimeline as $i => $node):
                $nodeAction = $node['action'] ?? '';
                if (in_array($nodeAction, ['cancelled', 'returned'], true)) {
                    $stepClass = ' shop-order-view__timeline-step--cancelled';
                } elseif (in_array($nodeAction, ['cancel_requested', 'return_requested'], true)) {
                    $stepClass = ' shop-order-view__timeline-step--requested';
                } else {
                    $stepClass = ' shop-order-view__timeline-step--active';
                }
            ?>
                <?php if ($i > 0): ?>
                    <div class="shop-order-view__timeline-line shop-order-view__timeline-line--active"></div>
                <?php endif; ?>
                <div class="shop-order-view__timeline-step<?= $stepClass ?>">
                    <div class="shop-order-view__timeline-dot"></div>
                    <div class="shop-order-view__timeline-label"><?= e($node['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($isCancelledOrReturned): ?>
        <div class="shop-order-view__status-badge shop-order-view__status-badge--danger">
            <?= e($statusLabel) ?>
        </div>
    <?php elseif (!empty($mainFlowStates)): ?>
        <div class="shop-order-view__timeline">
            <?php foreach ($mainFlowStates as $i => $state):
                $isCompleted = ($state['sort_order'] ?? 0) < $currentSortOrder;
                $isCurrent = ($state['id'] ?? '') === $currentStatus;
                $stepClass = $isCurrent ? ' shop-order-view__timeline-step--current' : ($isCompleted ? ' shop-order-view__timeline-step--active' : '');
                $lineActive = ($isCompleted || $isCurrent) ? ' shop-order-view__timeline-line--active' : '';
            ?>
                <?php if ($i > 0): ?>
                    <div class="shop-order-view__timeline-line<?= $lineActive ?>"></div>
                <?php endif; ?>
                <div class="shop-order-view__timeline-step<?= $stepClass ?>">
                    <div class="shop-order-view__timeline-dot"></div>
                    <div class="shop-order-view__timeline-label"><?= e($state['label'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ── 2컬럼 레이아웃 ── -->
    <div class="shop-order-view__layout">

        <!-- 메인 -->
        <div class="shop-order-view__main">

            <!-- 주문 상품 -->
            <div class="shop-order-view__section">
                <div class="shop-order-view__section-header">
                    주문 상품 <span style="color:#888;font-weight:400;font-size:0.85rem">(<?= count($orderItems) ?>개)</span>
                </div>
                <div class="shop-order-view__section-body">
                    <?php if (empty($orderItems)): ?>
                        <p style="color:#888;text-align:center;padding:16px 0">상품 정보가 없습니다.</p>
                    <?php else: ?>
                        <?php foreach ($orderItems as $item): ?>
                        <div class="shop-order-view__item">
                            <?php $goodsId = (int) ($item['goods_id'] ?? 0); ?>
                            <div class="shop-order-view__item-image">
                                <?php if ($goodsId > 0): ?><a href="/shop/products/<?= $goodsId ?>"><?php endif; ?>
                                <?php if (!empty($item['goods_image'])): ?>
                                    <img src="<?= e($item['goods_image']) ?>" alt="<?= e($item['goods_name'] ?? '') ?>">
                                <?php else: ?>
                                    <span class="shop-order-view__item-image--empty">&#128230;</span>
                                <?php endif; ?>
                                <?php if ($goodsId > 0): ?></a><?php endif; ?>
                            </div>
                            <div class="shop-order-view__item-info">
                                <?php if ($goodsId > 0): ?>
                                    <a href="/shop/products/<?= $goodsId ?>" class="shop-order-view__item-name"><?= e($item['goods_name'] ?? '') ?></a>
                                <?php else: ?>
                                    <div class="shop-order-view__item-name"><?= e($item['goods_name'] ?? '') ?></div>
                                <?php endif; ?>
                                <?php
                                $optName   = trim((string) ($item['option_name'] ?? ''));
                                $optPrice  = (int) ($item['option_price'] ?? 0);
                                // 옵션 포함 실단가 (네이버 방식: 옵션가를 단가에 합산해 표시)
                                $unitPrice = (int) ($item['goods_price'] ?? 0) + $optPrice;
                                $qty       = (int) ($item['quantity'] ?? 1);
                                ?>
                                <?php if ($optName !== ''): ?>
                                    <div class="shop-order-view__item-option">
                                        <span class="shop-order-view__item-tag">옵션</span>
                                        <?= e($optName) ?>
                                        <span class="shop-order-view__item-divider">|</span><span class="shop-order-view__item-qtysep"><?= $qty ?>개</span>
                                    </div>
                                <?php else: ?>
                                    <div class="shop-order-view__item-option">
                                        <span class="shop-order-view__item-tag">수량</span> <?= $qty ?>개
                                    </div>
                                <?php endif; ?>
                                <div class="shop-order-view__item-unit"><span class="shop-order-view__item-tag">가격</span> <?= number_format($unitPrice) ?>원</div>
                            </div>
                            <div class="shop-order-view__item-price"><?= number_format((int) ($item['total_price'] ?? 0)) ?>원</div>
                            <?php
                            $did = (int) ($item['order_detail_id'] ?? 0);
                            $meta = $reviewMeta[$did] ?? ['confirmed' => false, 'canConfirm' => false, 'pending' => false, 'reviewed' => false, 'review' => null];
                            $itemClaims = $claimsByDetail[$did] ?? [];
                            $latestClaim = $itemClaims[0] ?? null;
                            $latestClaimStatus = $latestClaim ? \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom((string) ($latestClaim['return_status'] ?? '')) : null;
                            $activeClaim = $latestClaimStatus?->isActive() ? $latestClaim : null;
                            $exchangeOptions = $exchangeOptionsByDetail[$did] ?? [];
                            ?>
                            <?php // 비회원 주문도 회원과 동일하게 구매확정/후기 노출 ?>
                            <div class="shop-order-view__item-review">
                                <?php if ($meta['reviewed']): ?>
                                    <button type="button" class="shop-order-view__review-btn shop-order-view__review-btn--view"
                                            data-review="<?= htmlspecialchars(json_encode($meta['review'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                                            data-goods-name="<?= e($item['goods_name'] ?? '') ?>">후기 보기</button>
                                <?php elseif ($meta['confirmed']): ?>
                                    <button type="button" class="shop-order-view__review-btn"
                                            data-review-detail="<?= $did ?>"
                                            data-goods-name="<?= e($item['goods_name'] ?? '') ?>">후기 작성</button>
                                <?php elseif ($meta['canConfirm']): ?>
                                    <button type="button" class="shop-order-view__review-btn shop-order-view__confirm-btn"
                                            data-confirm-detail="<?= $did ?>"
                                            data-goods-name="<?= e($item['goods_name'] ?? '') ?>"
                                            title="구매확정하면 후기를 작성할 수 있습니다">구매확정</button>
                                <?php endif; ?>
                                <?php // 아직 쓸 수 없는 후기의 자리를 비활성 버튼으로 잡아두지 않는다.
                                      // 반품·교환이 같은 줄에 들어오면서, 지금 할 수 있는 일과
                                      // 나중에 할 수 있는 일이 뒤섞여 읽히기 때문이다.
                                      // ($meta['pending'] 은 스킨이 쓸 수 있도록 그대로 전달된다) ?>
                                <div class="shop-order-view__item-actions">
                                <?php $remaining = (int) ($claimableQuantityByDetail[$did] ?? 0); ?>
                                <?php if (!empty($claimableByDetail[$did])): ?>
                                    <?php // 교환 옵션이 없는 상품(단종·품절 등)도 반품은 신청할 수 있다.
                                          // 일부만 신청한 상품은 남은 수량만큼 다시 신청할 수 있어야 한다. ?>
                                    <button type="button" class="shop-order-view__review-btn js-claim-open"
                                            data-detail-id="<?= $did ?>" data-max-quantity="<?= $remaining ?>"
                                            data-goods-name="<?= e($item['goods_name'] ?? '') ?>"
                                            data-options="<?= htmlspecialchars(json_encode($exchangeOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>">반품·교환</button>
                                <?php elseif ($latestClaimStatus && !$activeClaim): ?>
                                    <small style="display:block;color:#888;margin-top:4px">최근: <?= e($latestClaimStatus->label((string) ($latestClaim['return_type'] ?? 'EXCHANGE'))) ?></small>
                                <?php endif; ?>
                                </div>
                            </div>
                            <?php
                            // 클레임은 수량 단위라 한 상품에 여러 건이 생길 수 있다(3개 주문 →
                            // 2개 교환 + 1개 반품). 좁은 액션 칸에 쌓으면 읽을 수 없으므로
                            // 상품 행 아래 전폭 줄로 내린다.
                            $activeRows = [];
                            foreach ($itemClaims as $claimRow) {
                                $rowStatus = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom((string) ($claimRow['return_status'] ?? ''));
                                if ($rowStatus?->isActive()) {
                                    $activeRows[] = [$claimRow, $rowStatus];
                                }
                            }
                            ?>
                            <?php if ($activeRows !== []): ?>
                            <div class="shop-order-view__item-claims">
                                <?php foreach ($activeRows as [$claimRow, $rowStatus]):
                                    $rowType = (string) ($claimRow['return_type'] ?? 'EXCHANGE');
                                    $isReturn = $rowType === 'RETURN';
                                ?>
                                <div class="shop-order-view__claim-row">
                                    <span class="shop-order-view__claim-tag<?= $isReturn ? ' shop-order-view__claim-tag--return' : '' ?>"><?= $isReturn ? '반품' : '교환' ?></span>
                                    <span><?= max(1, (int) ($claimRow['quantity'] ?? 1)) ?>개</span>
                                    <span><?= e(\Mublo\Packages\Shop\Enum\ClaimReason::labelFor($claimRow['reason_type'] ?? '')) ?></span>
                                    <span class="shop-order-view__claim-state"><?= e($rowStatus->label($rowType)) ?></span>
                                    <?php if (($claimRow['return_status'] ?? '') === 'REQUESTED'): ?>
                                        <button type="button" class="shop-order-view__review-btn shop-order-view__claim-cancel js-exchange-cancel" data-claim-id="<?= (int) $claimRow['return_id'] ?>">신청 취소</button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 주문 정보 -->
            <?php
            // 주문인 필드는 값이 있는 것만 노출한다. (회원은 일부만, 비회원은 전부 채워질 수 있음)
            // 새 필드 수집 시 여기 한 줄만 추가하면 값이 있을 때 자동 노출된다. (예: 이메일)
            $ordererFields = [
                '주문자' => $order['orderer_name'] ?? '',
                '연락처' => $order['orderer_phone'] ?? '',
                '이메일' => $order['orderer_email'] ?? '',
            ];
            $ordererFields = array_filter($ordererFields, fn($v) => trim((string) $v) !== '');
            ?>
            <?php if (!empty($ordererFields)): ?>
            <div class="shop-order-view__section">
                <div class="shop-order-view__section-header">주문 정보</div>
                <div class="shop-order-view__section-body">
                    <?php foreach ($ordererFields as $label => $value): ?>
                    <div class="shop-order-view__info-row">
                        <span class="shop-order-view__info-label"><?= e($label) ?></span>
                        <span class="shop-order-view__info-value"><?= e($value) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 배송 정보 -->
            <div class="shop-order-view__section">
                <div class="shop-order-view__section-header">배송 정보</div>
                <div class="shop-order-view__section-body">
                    <div class="shop-order-view__info-row">
                        <span class="shop-order-view__info-label">수령인</span>
                        <span class="shop-order-view__info-value"><?= e($order['recipient_name'] ?? '-') ?></span>
                    </div>
                    <div class="shop-order-view__info-row">
                        <span class="shop-order-view__info-label">연락처</span>
                        <span class="shop-order-view__info-value"><?= e($order['recipient_phone'] ?? '-') ?></span>
                    </div>
                    <div class="shop-order-view__info-row">
                        <span class="shop-order-view__info-label">배송지</span>
                        <span class="shop-order-view__info-value">
                            <?php
                            $zip = $order['shipping_zip'] ?? '';
                            $addr1 = $order['shipping_address1'] ?? '';
                            $addr2 = $order['shipping_address2'] ?? '';
                            $addrText = '';
                            if ($zip) $addrText .= '[' . e($zip) . '] ';
                            $addrText .= e(trim($addr1 . ' ' . $addr2));
                            echo $addrText ?: '-';
                            ?>
                        </span>
                    </div>
                    <?php if (!empty($order['order_memo'])): ?>
                    <div class="shop-order-view__info-row">
                        <span class="shop-order-view__info-label">요청사항</span>
                        <span class="shop-order-view__info-value"><?= e($order['order_memo']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($shipments as $sh): ?>
                    <div class="shop-order-view__info-row">
                        <?php $shipmentLabel = match ($sh['shipment_role'] ?? 'ORIGINAL') {
                            'COLLECTION' => '회수 운송장',
                            'EXCHANGE_OUTBOUND' => '교환 운송장',
                            'REJECTED_RETURN' => '반송 운송장',
                            default => '운송장',
                        }; ?>
                        <span class="shop-order-view__info-label"><?= e($shipmentLabel) ?></span>
                        <span class="shop-order-view__info-value">
                            <?php $shTrackUrl = $sh['tracking_url'] ?? ''; ?>
                            <?= e((string) ($sh['company_name'] ?? '택배')) ?>
                            <?php if ($shTrackUrl !== ''): ?>
                                <a href="<?= e($shTrackUrl) ?>" target="_blank" rel="noopener"><?= e((string) ($sh['invoice_no'] ?? '')) ?> <i class="bi bi-box-arrow-up-right" style="font-size:0.8em"></i></a>
                            <?php else: ?>
                                <?= e((string) ($sh['invoice_no'] ?? '')) ?>
                            <?php endif; ?>
                            <?php
                            // 배송비를 따로 받은 묶음이 여러 개인 주문에서만 채워진다.
                            // 어느 상품이 이 송장으로 오는지 밝혀야 "왜 일부만 왔지"가 남지 않는다.
                            $shItems = $shipmentItemNames[(int) ($sh['shipment_id'] ?? 0)] ?? [];
                            ?>
                            <?php if ($shItems !== []): ?>
                                <small style="display:block;color:#777"><?= e(implode(', ', $shItems)) ?></small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 추가 정보 (커스텀 필드) -->
            <?php if (!empty($orderFieldValues)): ?>
            <div class="shop-order-view__section">
                <div class="shop-order-view__section-header">추가 정보</div>
                <div class="shop-order-view__section-body">
                    <?php foreach ($orderFieldValues as $fv): ?>
                    <div class="shop-order-view__info-row">
                        <span class="shop-order-view__info-label"><?= e($fv['field_label'] ?? '') ?></span>
                        <span class="shop-order-view__info-value">
                            <?php if (($fv['field_type'] ?? '') === 'file' && !empty($fv['filename'])): ?>
                                &#128206; <?= e($fv['filename']) ?>
                            <?php elseif (($fv['field_type'] ?? '') === 'address'): ?>
                                <?= e($fv['display_value'] ?? '') ?>
                            <?php else: ?>
                                <?= nl2br(e($fv['display_value'] ?? $fv['field_value'] ?? '-')) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /main -->

        <!-- 사이드바 -->
        <div class="shop-order-view__sidebar">
            <div class="shop-order-view__section">
                <div class="shop-order-view__section-header">결제 정보</div>
                <div class="shop-order-view__section-body">
                    <div class="shop-order-view__summary-row">
                        <span>상품 합계</span>
                        <span><?= number_format($totalPrice) ?>원</span>
                    </div>
                    <div class="shop-order-view__summary-row">
                        <span>배송비</span>
                        <span><?= $baseShippingFee > 0 ? number_format($baseShippingFee) . '원' : '무료' ?></span>
                    </div>
                    <?php if ($extraShippingFee > 0): ?>
                    <div class="shop-order-view__summary-row">
                        <span>추가배송비</span>
                        <span><?= number_format($extraShippingFee) ?>원</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($couponDiscount > 0): ?>
                    <div class="shop-order-view__summary-row">
                        <span>쿠폰 할인</span>
                        <span style="color:#e53e3e">-<?= number_format($couponDiscount) ?>원</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($pointUsed > 0): ?>
                    <div class="shop-order-view__summary-row">
                        <span>포인트 사용</span>
                        <span style="color:#e53e3e">-<?= number_format($pointUsed) ?>원</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($taxAmount > 0): ?>
                    <div class="shop-order-view__summary-row">
                        <span>세금</span>
                        <span><?= number_format($taxAmount) ?>원</span>
                    </div>
                    <?php endif; ?>

                    <div class="shop-order-view__summary-divider"></div>

                    <div class="shop-order-view__summary-total">
                        <span>결제 금액</span>
                        <span class="shop-order-view__summary-total-price"><?= number_format($finalAmount) ?>원</span>
                    </div>

                    <div class="shop-order-view__summary-divider"></div>

                    <div class="shop-order-view__summary-row">
                        <span>결제 수단</span>
                        <span style="font-weight:600"><?= e($paymentMethodLabel) ?></span>
                    </div>

                    <?php
                        // 무통장입금: 선택한 입금계좌 안내
                        $bankInfo = null;
                        if ($paymentMethodRaw === 'BANK' && !empty($order['bank_account_info'])) {
                            $decoded = json_decode((string) $order['bank_account_info'], true);
                            if (is_array($decoded)) {
                                $bankInfo = $decoded;
                            }
                        }
                    ?>
                    <?php if ($bankInfo): ?>
                    <?php // 입금 완료(받음 이후 상태)면 연하게 — 입금처 확인용으로 남겨두되 hover 시 선명 ?>
                    <div class="shop-order-view__bank-box<?= !in_array($currentAction, ['received', 'attempting'], true) ? ' shop-order-view__bank-box--paid' : '' ?>">
                        <div class="shop-order-view__bank-title">입금 계좌 안내</div>
                        <div class="shop-order-view__bank-row">
                            <span class="shop-order-view__bank-label">은행</span>
                            <span><?= e((string) ($bankInfo['bank'] ?? '')) ?></span>
                        </div>
                        <div class="shop-order-view__bank-row">
                            <span class="shop-order-view__bank-label">계좌번호</span>
                            <span class="shop-order-view__bank-account"><?= e((string) ($bankInfo['account'] ?? '')) ?></span>
                        </div>
                        <div class="shop-order-view__bank-row">
                            <span class="shop-order-view__bank-label">예금주</span>
                            <span><?= e((string) ($bankInfo['holder'] ?? '')) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canCancel): ?>
            <button type="button" id="spvCancelOpen" class="shop-order-view__btn shop-order-view__btn--danger shop-order-view__cancel-side">주문 취소</button>
            <?php endif; ?>
        </div><!-- /sidebar -->

    </div><!-- /layout -->

    <!-- ── 하단 버튼 ── -->
    <div class="shop-order-view__footer">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="shop-order-view__btn shop-order-view__btn--outline">주문내역 목록</a>
        <a href="/shop/products" class="shop-order-view__btn shop-order-view__btn--primary">쇼핑 계속하기</a>
    </div>

</div>

<!-- 반품·교환 신청 모달 -->
<div class="spv-rv-overlay" id="spvClaimOverlay">
    <div class="spv-rv-modal">
        <h3>반품·교환 신청</h3>
        <p class="spv-rv-modal__product" id="spvClaimProduct"></p>
        <input type="hidden" id="spvClaimDetailId">

        <label class="spv-cancel-label">어떻게 처리할까요?</label>
        <select id="spvClaimType" class="spv-cancel-select">
            <option value="EXCHANGE">교환 — 같은 상품으로 다시 받기</option>
            <option value="RETURN">반품 — 돌려보내고 환불받기</option>
        </select>
        <p id="spvClaimExchangeUnavailable" style="display:none;font-size:.8rem;color:#c05621">
            이 상품은 지금 바꿔 드릴 수 있는 옵션이 없어 교환을 신청할 수 없습니다. 반품으로 진행해주세요.
        </p>

        <div id="spvClaimOptionWrap">
            <label class="spv-cancel-label">교환 옵션</label>
            <select id="spvClaimOption" class="spv-cancel-select"></select>
        </div>

        <div id="spvClaimQuantityWrap">
            <label class="spv-cancel-label">수량 <span style="font-weight:400;color:#888">(최대 <span id="spvClaimMaxQuantity">1</span>개까지 신청할 수 있습니다)</span></label>
            <input type="number" id="spvClaimQuantity" class="spv-cancel-input" min="1" value="1">
        </div>

        <label class="spv-cancel-label">사유</label>
        <select id="spvClaimReason" class="spv-cancel-select">
            <?php foreach (\Mublo\Packages\Shop\Enum\ClaimReason::options() as $reasonValue => $reasonLabel): ?>
            <option value="<?= e($reasonValue) ?>"><?= e($reasonLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <textarea id="spvClaimDetail" placeholder="상세 사유를 입력해주세요."></textarea>

        <p id="spvClaimHintExchange" style="font-size:.8rem;color:#777">
            동일 상품의 결제금액이 같은 옵션만 교환할 수 있습니다. 고객 귀책 시 주문 당시 교환 배송비가 적용됩니다.
        </p>
        <p id="spvClaimHintReturn" style="display:none;font-size:.8rem;color:#777">
            상품을 회수해 확인한 뒤 환불해 드립니다. 고객 귀책 시 주문 당시 반품 배송비가 환불액에서 차감됩니다.
        </p>

        <div class="spv-rv-actions">
            <button type="button" class="spv-rv-cancel" id="spvClaimClose">닫기</button>
            <button type="button" class="spv-rv-submit" id="spvClaimSubmit">신청하기</button>
        </div>
    </div>
</div>

<!-- 후기 작성 모달 -->
<div class="spv-rv-overlay" id="spvRvOverlay">
    <div class="spv-rv-modal">
        <h3>구매후기 작성</h3>
        <p class="spv-rv-modal__product" id="spvRvProduct"></p>
        <form id="spvRvForm">
            <input type="hidden" name="order_detail_id" id="spvRvDetailId">
            <div class="spv-rv-stars" id="spvRvStars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="spv-rv-star" data-v="<?= $i ?>">★</span>
                <?php endfor; ?>
            </div>
            <textarea name="content" id="spvRvContent" placeholder="상품에 대한 솔직한 후기를 남겨주세요. (최소 10자)"></textarea>
            <div class="spv-rv-files">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <label class="spv-rv-slot" id="spvRvSlot<?= $i ?>">
                    <i class="bi bi-plus-lg" style="color:#9ca3af;font-size:1.4rem"></i>
                    <input type="file" accept="image/*" data-slot="<?= $i ?>">
                </label>
                <?php endfor; ?>
            </div>
            <div style="font-size:0.78rem;color:#9ca3af">JPG·PNG·WEBP, 각 5MB 이하 (선택)</div>
            <div class="spv-rv-actions">
                <button type="button" class="spv-rv-cancel" id="spvRvCancel">취소</button>
                <button type="submit" class="spv-rv-submit">등록하기</button>
            </div>
        </form>
    </div>
</div>

<!-- 후기 보기 모달 -->
<div class="spv-rv-overlay" id="spvRvViewOverlay">
    <div class="spv-rv-modal">
        <h3>구매후기</h3>
        <p class="spv-rv-modal__product" id="spvRvViewProduct"></p>
        <div class="spv-rv-view__stars" id="spvRvViewStars"></div>
        <div class="spv-rv-view__date" id="spvRvViewDate"></div>
        <div class="spv-rv-view__content" id="spvRvViewContent"></div>
        <div class="spv-rv-view__images" id="spvRvViewImages"></div>
        <div id="spvRvViewReply"></div>
        <div class="spv-rv-actions">
            <button type="button" class="spv-rv-cancel" id="spvRvViewClose">닫기</button>
            <button type="button" class="spv-rv-submit spv-rv-delete" id="spvRvViewDelete">삭제</button>
        </div>
    </div>
</div>

<script type="application/json" id="order-view-data"><?= json_encode(['orderNo' => $orderNo], JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= asset('/serve/package/Shop/views/Front/Order/basic/_assets/js/order-view-1.js') ?>"></script>

<script src="<?= asset('/serve/package/Shop/views/Front/Order/basic/_assets/js/order-view-2.js') ?>"></script>

<script>
(function(){
 const overlay=document.getElementById('spvClaimOverlay');
 if(!overlay) return;
 // .mublo-main(z-index:1) stacking context 탈출 — 후기·주문취소 모달과 같은 처리
 document.body.appendChild(overlay);

 // 배경 스크롤 잠금. 스크롤바가 사라지며 페이지가 밀리지 않도록 그 폭만큼 보정한다.
 let scrollLocked=false;
 function lockScroll(on){
   if(on===scrollLocked) return;
   scrollLocked=on;
   if(on){
     const sbw=window.innerWidth-document.documentElement.clientWidth;
     document.body.style.overflow='hidden';
     if(sbw>0) document.body.style.paddingRight=sbw+'px';
   }else{
     document.body.style.overflow='';
     document.body.style.paddingRight='';
   }
 }
 function closeModal(){ overlay.classList.remove('is-open'); lockScroll(false); }

 const typeSel=document.getElementById('spvClaimType'), optionSel=document.getElementById('spvClaimOption');
 const optionWrap=document.getElementById('spvClaimOptionWrap');
 const unavailable=document.getElementById('spvClaimExchangeUnavailable');
 const hintExchange=document.getElementById('spvClaimHintExchange'), hintReturn=document.getElementById('spvClaimHintReturn');
 let hasExchangeOptions=false;

 // 교환은 바꿔 줄 옵션이 있어야 성립하고, 반품은 그런 제약이 없다.
 // 옵션이 없으면 교환을 고를 수 없게 잠그고 이유를 밝힌다.
 function syncType(){
   const isExchange=typeSel.value==='EXCHANGE';
   optionWrap.style.display=isExchange?'':'none';
   hintExchange.style.display=isExchange?'':'none';
   hintReturn.style.display=isExchange?'none':'';
 }
 typeSel.addEventListener('change',syncType);

 document.querySelectorAll('.js-claim-open').forEach(btn=>btn.addEventListener('click',()=>{
   document.getElementById('spvClaimDetailId').value=btn.dataset.detailId;
   document.getElementById('spvClaimProduct').textContent=btn.dataset.goodsName;
   const max=Math.max(1, Number(btn.dataset.maxQuantity||1));
   const qty=document.getElementById('spvClaimQuantity'); qty.max=max; qty.value=1;
   // 한 개만 주문한 상품은 물어볼 것이 없다
   document.getElementById('spvClaimQuantityWrap').style.display=max>1?'':'none';
   document.getElementById('spvClaimMaxQuantity').textContent=String(max);
   optionSel.innerHTML='';
   const options=JSON.parse(btn.dataset.options||'[]');
   hasExchangeOptions=options.length>0;
   options.forEach(row=>{
     const el=document.createElement('option');
     el.value=row.option_id; el.dataset.code=row.option_code||'';
     el.textContent=(row.option_name||'동일 상품')+(row.stock_quantity===null?'':' (재고 '+row.stock_quantity+')');
     optionSel.appendChild(el);
   });
   typeSel.querySelector('option[value=EXCHANGE]').disabled=!hasExchangeOptions;
   typeSel.value=hasExchangeOptions?'EXCHANGE':'RETURN';
   unavailable.style.display=hasExchangeOptions?'none':'';
   syncType();
   overlay.classList.add('is-open');
   lockScroll(true);
 }));

 document.getElementById('spvClaimClose')?.addEventListener('click',closeModal);
 overlay.addEventListener('click',e=>{ if(e.target===overlay) closeModal(); });
 document.addEventListener('keydown',e=>{ if(e.key==='Escape'&&overlay.classList.contains('is-open')) closeModal(); });
 document.getElementById('spvClaimSubmit')?.addEventListener('click',()=>{
   const returnType=typeSel.value;
   const reason=document.getElementById('spvClaimReason').value;
   const responsibility=['DEFECT','WRONG_PRODUCT','LATE_DELIVERY'].includes(reason)?'SELLER':'CUSTOMER';
   const selected=optionSel.options[optionSel.selectedIndex];
   const detailId=document.getElementById('spvClaimDetailId').value;
   MubloRequest.requestJson('/shop/order/<?= e($orderNo) ?>/items/'+detailId+'/claim',{
     return_type:returnType,
     quantity:Number(document.getElementById('spvClaimQuantity').value),
     target_option_id:returnType==='EXCHANGE'?Number(selected?.value||0):0,
     target_option_code:returnType==='EXCHANGE'?(selected?.dataset.code||''):'',
     reason_type:reason,
     reason_detail:document.getElementById('spvClaimDetail').value,
     responsibility:responsibility
   }).then(()=>location.reload());
 });

 document.querySelectorAll('.js-exchange-cancel').forEach(btn=>btn.addEventListener('click',()=>{if(confirm('신청을 취소하시겠습니까?'))MubloRequest.requestJson('/shop/order/<?= e($orderNo) ?>/exchanges/'+btn.dataset.claimId+'/cancel',{}).then(()=>location.reload());}));
})();
</script>

<?php if ($canCancel): ?>
<!-- 주문 취소 모달 -->
<div class="spv-rv-overlay" id="spvCancelOverlay">
    <div class="spv-rv-modal">
        <h3>주문 취소</h3>
        <p class="spv-rv-modal__product">주문번호: <?= e($orderNo) ?></p>
        <p class="spv-cancel-hint"><?= e($cancelHint) ?></p>
        <label class="spv-cancel-label" for="spvCancelReason">취소 사유</label>
        <select id="spvCancelReason" class="spv-cancel-select">
            <option value="단순 변심">단순 변심</option>
            <option value="상품을 잘못 주문함">상품을 잘못 주문함</option>
            <option value="배송이 너무 늦음">배송이 너무 늦음</option>
            <option value="다른 상품으로 재주문">다른 상품으로 재주문</option>
            <option value="기타">기타</option>
        </select>
        <textarea id="spvCancelDetail" placeholder="상세 사유를 입력해주세요. (선택)"></textarea>
        <div class="spv-rv-actions">
            <button type="button" class="spv-rv-cancel" id="spvCancelClose">닫기</button>
            <button type="button" class="spv-rv-submit spv-rv-delete" id="spvCancelSubmit">주문 취소하기</button>
        </div>
    </div>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Order/basic/_assets/js/order-view-3.js') ?>"></script>
<?php endif; ?>
