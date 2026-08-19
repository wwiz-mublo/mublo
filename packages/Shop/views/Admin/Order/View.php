<?php
/**
 * 주문 상세 (FSM 기반 + 아이템별 관리)
 *
 * @var string $pageTitle 페이지 제목
 * @var array $order 주문 정보
 * @var array $orderItems 주문 상품 목록
 * @var array $orderStatusOptions FSM 상태 옵션 [id => label]
 * @var array $availableTransitions 현재 상태에서 이동 가능한 상태 배열
 * @var string $currentStatusLabel 현재 상태 라벨
 * @var array $orderLogs 상태 변경 이력
 * @var array $orderFieldValues 주문 추가 필드 값
 * @var array $orderReturns 반품 정보
 * @var array $refundInfo 환불 정보 [total_paid, total_refunded, refundable]
 * @var array $paymentTransactions 결제·환불 트랜잭션 이력
 * @var array $orderMemos 관리자 메모 목록
 * @var array $memoTypeLabels 메모 유형 라벨
 * @var int $domainId 도메인 ID
 */

$order = $order ?? [];
$orderItems = $orderItems ?? [];
$orderFieldValues = $orderFieldValues ?? [];
$orderLogs = $orderLogs ?? [];
$orderReturns = $orderReturns ?? [];
$availableTransitions = $availableTransitions ?? [];
$orderStatusOptions = $orderStatusOptions ?? [];
$orderStatusColors = $orderStatusColors ?? [];
$currentStatusLabel = $currentStatusLabel ?? '-';

// FSM 상태 배지 렌더 (목록과 동일한 소프트 색맵)
$statusBadge = static function (string $stateId, string $label, string $extra = '') use ($orderStatusColors) {
    $c = $orderStatusColors[$stateId] ?? 'secondary';
    $cls = 'badge bg-' . $c . '-subtle text-' . $c . '-emphasis border border-' . $c . '-subtle'
        . ($extra !== '' ? ' ' . $extra : '');
    return '<span class="' . $cls . '">' . htmlspecialchars($label) . '</span>';
};
$refundInfo = $refundInfo ?? [];
$paymentTransactions = $paymentTransactions ?? [];
$shipments = $shipments ?? [];
$deliveryCompanies = $deliveryCompanies ?? [];
$deliveryEditable = $deliveryEditable ?? false;
// 배송비 그룹이 둘 이상인 주문만 그룹 단위 송장 입력을 노출한다 (쪼갤 게 없으면 선택을 시키지 않는다)
$shippingGroups = $shippingGroups ?? [];
$shipmentItemNames = $shipmentItemNames ?? [];
// 선택 상품 일괄 변경은 배송 단계만 (취소·반품은 환불·재고 후처리가 딸린 전용 흐름)
$bulkItemStatusOptions = $bulkItemStatusOptions ?? [];

// 출고 단위(배송비 묶음)가 곧 관리 단위다. 주문 상품 표를 묶음으로 묶고 그 묶음의
// 송장 상태와 등록 동선을 상품 바로 옆에 둔다 — 무엇을 어떻게 보내야 하는지는
// 상품 목록에서 읽혀야지, 카드 다섯 개 아래에서 짜맞출 일이 아니다.
$shipmentsByGroupKey = [];
$wholeOrderShipments = [];
foreach ($shipments as $sh) {
    if (!empty($sh['claim_id'])) {
        continue; // 교환 송장은 교환 관리가 소유한다
    }
    $shGroupKey = $sh['shipping_group_key'] ?? null;
    if ($shGroupKey === null || $shGroupKey === '') {
        $wholeOrderShipments[] = $sh;
    } else {
        $shipmentsByGroupKey[(string) $shGroupKey][] = $sh;
    }
}

// 묶음이 하나뿐이거나 상품을 온전히 나눌 수 없으면 평면 목록으로 둔다
$itemsByDetailId = [];
foreach ($orderItems as $__item) {
    $itemsByDetailId[(int) ($__item['order_detail_id'] ?? 0)] = $__item;
}
$itemGroups = [];
if ($shippingGroups !== []) {
    $coveredCount = 0;
    foreach ($shippingGroups as $gi => $g) {
        $rows = [];
        foreach ($g['detail_ids'] as $gDetailId) {
            if (isset($itemsByDetailId[(int) $gDetailId])) {
                $rows[] = $itemsByDetailId[(int) $gDetailId];
                $coveredCount++;
            }
        }
        $itemGroups[] = ['no' => $gi + 1, 'group' => $g, 'items' => $rows];
    }
    if ($coveredCount !== count($itemsByDetailId)) {
        $itemGroups = [];
    }
}
if ($itemGroups === []) {
    $itemGroups = [['no' => 0, 'group' => null, 'items' => $orderItems]];
}
// 운송장 역할 — 클레임 운송장(회수·재출고·반송)이 함께 나오므로 무엇인지 밝힌다
$shipmentRoleLabels = [
    'COLLECTION' => '회수', 'EXCHANGE_OUTBOUND' => '교환 재출고', 'REJECTED_RETURN' => '고객 반송',
];
// 배송 상태 라벨
$shipmentStatusLabels = [
    'READY' => '준비', 'PICKED_UP' => '집화', 'IN_TRANSIT' => '배송중',
    'DELIVERED' => '배송완료', 'FAILED' => '실패',
];
$orderMemos = $orderMemos ?? [];
$memoTypeLabels = $memoTypeLabels ?? [];
$listQuery = $listQuery ?? '';
$orderNo = $order['order_no'] ?? '';

// 클레임은 수량 단위라 한 품목에 여러 건이 붙는다(2개 교환 + 1개 반품).
// 덮어쓰면 마지막 한 건만 남아 유형과 수량이 어긋나 보인다.
$returnsByDetail = [];
foreach ($orderReturns as $ret) {
    $returnsByDetail[(int) ($ret['order_detail_id'] ?? 0)][] = $ret;
}
?>

<div class="page-container">
<div class="page-title">
    <div class="page-title-text">
        <h3><?= htmlspecialchars($pageTitle ?? '주문 상세') ?></h3>
        <p>
            <span class="text-muted">주문일시:</span> <span><?= !empty($order['created_at']) ? htmlspecialchars($order['created_at']) : '<span class="text-muted">-</span>' ?></span>
            <span class="mx-1 small opacity-50">|</span>
            <span class="text-muted">변경일시:</span> <span><?= !empty($order['updated_at']) ? htmlspecialchars($order['updated_at']) : '<span class="text-muted">-</span>' ?></span>
        </p>
    </div>
    <div class="page-title-actions">
        <a href="/admin/shop/orders<?= !empty($listQuery) ? '?' . htmlspecialchars($listQuery) : '' ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list"></i> 목록
        </a>
    </div>
</div>

<div class="page-block">
        <!-- 주문 요약 (주문 · 배송 · 결제) -->
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card h-100 mb-0">
                    <div class="card-hero">
                        <i class="bi bi-receipt text-pastel-blue"></i>
                        <span>주문 정보</span>
                    </div>
                    <div class="card-body">

                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">주문번호</span>
                            <span class="text-end"><strong><?= htmlspecialchars($orderNo) ?></strong></span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">주문인</span>
                            <span class="text-end"><?= htmlspecialchars($order['orderer_name'] ?? '') ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">연락처</span>
                            <span class="text-end"><?= htmlspecialchars($order['orderer_phone'] ?? '') ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">결제수단</span>
                            <span class="text-end">
                                <?php
                                    // 표기 규칙: gateway / method (원문 그대로 — DB 저장값/PG 응답이 진실).
                                    // BANK 같이 gateway가 비어 있으면(PG 안 거치는 채널) method만 표시.
                                    $pmRaw = (string) ($order['payment_method'] ?? '');
                                    $pgRaw = trim((string) ($order['payment_gateway'] ?? ''));
                                ?>
                                <?php if ($pgRaw !== ''): ?>
                                    <?= htmlspecialchars($pgRaw) ?> <span class="text-muted">/ <?= htmlspecialchars($pmRaw ?: '-') ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($pmRaw ?: '-') ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php
                            // 무통장입금: 고객이 선택한 입금계좌
                            $adminBankInfo = null;
                            if (($order['payment_method'] ?? '') === 'BANK' && !empty($order['bank_account_info'])) {
                                $decoded = json_decode((string) $order['bank_account_info'], true);
                                if (is_array($decoded)) {
                                    $adminBankInfo = $decoded;
                                }
                            }
                        ?>
                        <?php if ($adminBankInfo): ?>
                        <div class="mt-2">
                            <div class="text-muted">입금 계좌</div>
                            <div>
                                <?= htmlspecialchars((string) ($adminBankInfo['bank'] ?? '')) ?>
                                <code><?= htmlspecialchars((string) ($adminBankInfo['account'] ?? '')) ?></code>
                                (<?= htmlspecialchars((string) ($adminBankInfo['holder'] ?? '')) ?>)
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($order['order_memo'])): ?>
                        <div class="mt-2">
                            <div class="text-muted">주문 메모</div>
                            <div><?= nl2br(htmlspecialchars($order['order_memo'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 mb-0">
                    <div class="card-hero">
                        <i class="bi bi-truck text-pastel-green"></i>
                        <span>배송 정보</span>
                    </div>
                    <div class="card-body">

                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">수령인</span>
                            <span class="text-end"><?= htmlspecialchars($order['recipient_name'] ?? '') ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">연락처</span>
                            <span class="text-end"><?= htmlspecialchars($order['recipient_phone'] ?? '') ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-2 mb-1">
                            <span class="text-muted flex-shrink-0">주소</span>
                            <span class="text-end">
                                [<?= htmlspecialchars($order['shipping_zip'] ?? '') ?>]
                                <?= htmlspecialchars($order['shipping_address1'] ?? '') ?>
                                <?= htmlspecialchars($order['shipping_address2'] ?? '') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 mb-0">
                    <div class="card-hero">
                        <i class="bi bi-currency-dollar text-pastel-purple"></i>
                        <span>결제 정보</span>
                    </div>
                    <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">상품 합계</span>
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
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">배송비</span>
                    <span><?= number_format($__baseShip) ?>원</span>
                </div>
                <?php if ($__extraShip > 0): ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">추가배송비</span>
                    <span><?= number_format($__extraShip) ?>원</span>
                </div>
                <?php endif; ?>
                <?php if (($order['coupon_discount'] ?? 0) > 0): ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">쿠폰 할인</span>
                    <span class="text-danger">-<?= number_format((int) $order['coupon_discount']) ?>원</span>
                </div>
                <?php endif; ?>
                <?php if (($order['point_used'] ?? 0) > 0): ?>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">포인트 사용</span>
                    <span class="text-danger">-<?= number_format((int) $order['point_used']) ?>원</span>
                </div>
                <?php endif; ?>
                <hr>
                <div class="d-flex justify-content-between">
                    <strong>총 결제 금액</strong>
                    <strong class="text-primary fs-5">
                        <?= number_format(
                            ((int) ($order['total_price'] ?? 0))
                            + ((int) ($order['shipping_fee'] ?? 0))
                            + ((int) ($order['extra_price'] ?? 0))
                            - ((int) ($order['coupon_discount'] ?? 0))
                            - ((int) ($order['point_used'] ?? 0))
                        ) ?>원
                    </strong>
                </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 주문 상태 (주문 전체에 적용) — 상품 목록 바로 위 -->
        <?php // 배경은 카드에 준다. card-hero에 bg-*를 붙이면 뒤따르는 card-body의
              // 여백을 되살리는 규칙이 걸려 헤더와 본문 사이가 벌어진다. ?>
        <div class="card mb-4 bg-body-tertiary">
            <div class="card-hero">
                <i class="bi bi-arrow-repeat text-pastel-orange"></i>
                <span>주문 상태</span>
                <?= $statusBadge($order['order_status'] ?? '', $currentStatusLabel, 'ms-auto') ?>
            </div>
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <?php if (!empty($availableTransitions)): ?>
                <select id="newOrderStatus" class="form-select" style="width:auto">
                    <option value="">변경할 상태 선택</option>
                    <?php foreach ($availableTransitions as $trans): ?>
                    <option value="<?= htmlspecialchars($trans['id']) ?>"><?= htmlspecialchars($trans['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="statusReason" class="form-control" style="width:300px;max-width:100%" placeholder="변경 사유 (선택)">
                <button type="button" class="btn btn-primary" id="btnChangeStatus"><i class="bi bi-check-lg"></i> 변경</button>
                <span class="form-text m-0 ms-2">이 주문의 상품이 <strong>모두</strong> 함께 옮겨집니다. 일부만 옮기려면 아래에서 선택해 적용하세요.</span>
                <?php else: ?>
                <span class="form-text m-0 ms-2">변경 가능한 상태가 없습니다.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 주문 상품 (아이템별 관리) -->
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-box-seam text-pastel-purple"></i>
                <span>주문 상품</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-hover mb-0 order-items-table">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:36px"><input type="checkbox" class="form-check-input" id="itemCheckAll" title="전체 선택"></th>
                            <th>상품명</th>
                            <th>옵션</th>
                            <th class="text-end text-nowrap">단가</th>
                            <th class="text-center text-nowrap">수량</th>
                            <th class="text-end text-nowrap">합계</th>
                            <th class="text-center text-nowrap">상태</th>
                            <th class="text-center text-nowrap">반품</th>
                            <th class="text-nowrap" style="width:100px">관리</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itemGroups as $itemGroup): ?>
                        <?php if ($itemGroup['group'] !== null): ?>
                            <?php
                            $gKey = $itemGroup['group']['key'];
                            $gShipments = $gKey !== null ? ($shipmentsByGroupKey[(string) $gKey] ?? []) : [];
                            ?>
                            <tr class="order-items-table__group table-tertiary">
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input js-group-check"
                                           data-group-no="<?= $itemGroup['no'] ?>" title="이 묶음 전체 선택">
                                </td>
                                <td colspan="8">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">묶음 <?= $itemGroup['no'] ?></span>
                                        <span class="small text-muted"><?= htmlspecialchars($itemGroup['group']['label']) ?></span>
                                        <?php if (!empty($itemGroup['group']['separate'])): ?>
                                            <?php // 같은 배송 정책인데 나뉜 이유를 밝힌다 ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="개별 배송 상품이라 따로 나갑니다">개별배송</span>
                                        <?php endif; ?>
                                        <span class="ms-auto small d-flex flex-wrap align-items-center gap-2">
                                            <?php if ($gShipments !== []): ?>
                                                <?php foreach ($gShipments as $gsh): ?>
                                                    <span>
                                                        <?= htmlspecialchars($gsh['company_name'] ?? '택배') ?>
                                                        <?php if (!empty($gsh['tracking_url'])): ?>
                                                            <a href="<?= htmlspecialchars($gsh['tracking_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($gsh['invoice_no'] ?? '') ?></a>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars($gsh['invoice_no'] ?? '') ?>
                                                        <?php endif; ?>
                                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?= htmlspecialchars($shipmentStatusLabels[$gsh['shipment_status'] ?? ''] ?? ($gsh['shipment_status'] ?? '')) ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php elseif ($wholeOrderShipments !== []): ?>
                                                <span class="text-muted">주문 전체 운송장으로 발송</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">운송장 미등록</span>
                                                <?php if ($deliveryEditable && $gKey !== null): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 js-group-shipment"
                                                            data-group-key="<?= htmlspecialchars((string) $gKey) ?>">
                                                        <i class="bi bi-plus-lg"></i> 운송장 등록
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($itemGroup['items'] as $item): ?>
                        <?php
                            $detailId = (int) ($item['order_detail_id'] ?? 0);
                            $itemStatus = $item['status'] ?? '';
                            $itemStatusLabel = $orderStatusOptions[$itemStatus] ?? $itemStatus ?: '-';
                            $returnType = $item['return_type'] ?? 'NONE';
                            $returnStatus = $item['return_status'] ?? 'NONE';
                            $hasReturn = $returnType !== 'NONE' && $returnType !== '';
                            $isPendingReturn = $returnType === 'RETURN' && $returnStatus === 'REQUESTED';
                        ?>
                        <?php $claimActive = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom((string) $returnStatus)?->isActive() ?? false; ?>
                        <tr>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input js-item-check"
                                       value="<?= $detailId ?>" data-group-no="<?= $itemGroup['no'] ?>"
                                       <?= $claimActive ? 'disabled title="반품·교환이 진행 중입니다"' : '' ?>>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if (!empty($item['goods_image'])): ?>
                                        <img src="<?= htmlspecialchars($item['goods_image']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;flex:none">
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($item['goods_name'] ?? '') ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($item['option_name'] ?? '-') ?></td>
                            <td class="text-end text-nowrap"><?= number_format((int) ($item['goods_price'] ?? 0)) ?>원</td>
                            <td class="text-center text-nowrap"><?= (int) ($item['quantity'] ?? 0) ?></td>
                            <td class="text-end text-nowrap"><strong><?= number_format((int) ($item['total_price'] ?? 0)) ?>원</strong></td>
                            <td class="text-center text-nowrap">
                                <?= $statusBadge($itemStatus, $itemStatusLabel) ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <?php $itemClaimRows = $returnsByDetail[$detailId] ?? []; ?>
                                <?php if ($itemClaimRows === []): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <?php foreach ($itemClaimRows as $claimRow): ?>
                                    <?php
                                        $rowType = (string) ($claimRow['return_type'] ?? '');
                                        $rowStatusId = (string) ($claimRow['return_status'] ?? '');
                                        $rowStatus = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom($rowStatusId);
                                        $rtLabel = $rowType === 'RETURN' ? '반품' : ($rowType === 'EXCHANGE' ? '교환' : ($rowType ?: '취소'));
                                        $rsBg = match (true) {
                                            $rowStatusId === 'COMPLETED' => 'success',
                                            in_array($rowStatusId, ['REFUSED', 'CANCELLED', 'REJECTED'], true) => 'danger',
                                            default => 'warning',
                                        };
                                    ?>
                                    <div class="mb-1">
                                        <?php if (in_array($rowType, ['RETURN', 'EXCHANGE'], true)): ?>
                                            <a href="/admin/shop/claims/<?= (int) ($claimRow['return_id'] ?? 0) ?>?activeCode=K_Shop_016" class="badge bg-<?= $rsBg ?>-subtle text-<?= $rsBg ?>-emphasis border border-<?= $rsBg ?>-subtle text-decoration-none">
                                                <?= $rtLabel ?> <?= max(1, (int) ($claimRow['quantity'] ?? 1)) ?>개 · <?= htmlspecialchars($rowStatus?->label($rowType) ?? $rowStatusId) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-<?= $rsBg ?>-subtle text-<?= $rsBg ?>-emphasis border border-<?= $rsBg ?>-subtle"><?= $rtLabel ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        관리
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item js-order-item-status" href="#" data-detail-id="<?= $detailId ?>" data-item-name="<?= htmlspecialchars($item['goods_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                상태 변경
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item js-order-item-cancel" href="#" data-detail-id="<?= $detailId ?>" data-item-name="<?= htmlspecialchars($item['goods_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                취소
                                            </a>
                                        </li>
                                        <?php if (!$hasReturn): ?>
                                        <li>
                                            <a class="dropdown-item js-order-item-return" href="#" data-detail-id="<?= $detailId ?>" data-item-name="<?= htmlspecialchars($item['goods_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                반품 접수
                                            </a>
                                        </li>
                                        <?php else: ?>
                                        <li>
                                            <a class="dropdown-item" href="/admin/shop/claims?keyword=<?= urlencode($orderNo) ?>&amp;activeCode=K_Shop_016">
                                                반품·교환 관리에서 처리
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($bulkItemStatusOptions !== []): ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                    <span class="small text-muted" id="itemBulkCount">선택 0개</span>
                    <select id="itemBulkStatus" class="form-select form-select-sm" style="width:auto" disabled>
                        <option value="">배송 단계 선택</option>
                        <?php foreach ($bulkItemStatusOptions as $bulkId => $bulkLabel): ?>
                        <option value="<?= htmlspecialchars((string) $bulkId) ?>"><?= htmlspecialchars($bulkLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-primary" id="itemBulkApply" disabled>선택 상품 적용</button>
                    <span class="form-text m-0 ms-2">취소·반품·구매확정은 환불·재고 처리가 따르므로 상품별 <strong>관리</strong>에서 진행합니다.</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 운송장 (배송 추적) -->
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-box-seam text-pastel-sky"></i>
                <span>배송 운송장</span>
                <?php if (!$deliveryEditable): ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-auto">현재 상태에서는 입력 불가</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- 등록된 운송장 목록 -->
                <?php if (empty($shipments)): ?>
                    <p class="text-muted mb-0 text-center"><small>등록된 운송장이 없습니다.</small></p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>택배사</th>
                            <th>송장번호</th>
                            <?php if ($shippingGroups !== []): ?><th>포함 상품</th><?php endif; ?>
                            <th>상태</th>
                            <?php if ($deliveryEditable): ?><th class="text-end">관리</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shipments as $sh): ?>
                        <tr>
                            <td>
                                <?php if (!empty($sh['claim_id'])): ?>
                                    <a href="/admin/shop/claims/<?= (int) $sh['claim_id'] ?>?activeCode=K_Shop_016"
                                       class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none me-1"><?= htmlspecialchars($shipmentRoleLabels[$sh['shipment_role'] ?? ''] ?? '클레임') ?></a>
                                <?php endif; ?>
                                <?= htmlspecialchars($sh['company_name'] ?? '-') ?>
                            </td>
                            <td>
                                <?php $trackUrl = $sh['tracking_url'] ?? ''; ?>
                                <?php if ($trackUrl): ?>
                                    <a href="<?= htmlspecialchars($trackUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($sh['invoice_no'] ?? '') ?> <i class="bi bi-box-arrow-up-right small"></i></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($sh['invoice_no'] ?? '') ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($shippingGroups !== []): ?>
                            <td class="small text-muted">
                                <?php $shItems = $shipmentItemNames[(int) ($sh['shipment_id'] ?? 0)] ?? []; ?>
                                <?= $shItems === [] ? '-' : htmlspecialchars(implode(', ', $shItems)) ?>
                            </td>
                            <?php endif; ?>
                            <td><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?= htmlspecialchars($shipmentStatusLabels[$sh['shipment_status'] ?? ''] ?? ($sh['shipment_status'] ?? '')) ?></span></td>
                            <?php if ($deliveryEditable): ?>
                            <td class="text-end text-nowrap">
                                <?php if (empty($sh['claim_id'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick='editShipment(<?= (int) $sh['shipment_id'] ?>, <?= (int) ($sh['company_id'] ?? 0) ?>, <?= json_encode((string) ($sh['invoice_no'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode((string) ($sh['admin_memo'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>수정</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteShipment(<?= (int) $sh['shipment_id'] ?>)">삭제</button>
                                <?php else: ?>
                                    <a href="/admin/shop/claims/<?= (int) $sh['claim_id'] ?>?activeCode=K_Shop_016" class="btn btn-sm btn-outline-warning">교환 관리</a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

                <!-- 입력/수정 폼 (배송정보 편집 가능 상태에서만) -->
                <?php if ($deliveryEditable): ?>
                <hr class="my-3">
                <div id="shipmentFormTitle" class="fw-semibold mb-2 small">운송장 등록</div>
                <input type="hidden" id="shipmentEditId" value="">
                <?php if ($shippingGroups !== []): ?>
                    <?php
                    // 이미 송장이 붙은 그룹을 표시해 두면, 무엇이 남았는지 목록을 다시 훑지 않아도 된다.
                    $shippedGroupKeys = [];
                    foreach ($shipments as $sh) {
                        if (empty($sh['claim_id']) && ($sh['shipping_group_key'] ?? null) !== null) {
                            $shippedGroupKeys[(string) $sh['shipping_group_key']] = true;
                        }
                    }
                    ?>
                    <div class="mb-2" id="shipmentGroupWrap">
                        <select id="shipmentGroup" class="form-select">
                            <option value="">주문 전체 (한 번에 발송)</option>
                            <?php foreach ($shippingGroups as $gi => $group): ?>
                                <?php if ($group['key'] === null) { continue; } ?>
                                <option value="<?= htmlspecialchars((string) $group['key']) ?>">
                                    묶음 <?= $gi + 1 ?> · <?= htmlspecialchars($group['label']) ?><?= !empty($group['separate']) ? ' (개별배송)' : '' ?>
                                    · <?= htmlspecialchars(implode(', ', $group['item_names'])) ?>
                                    <?= isset($shippedGroupKeys[(string) $group['key']]) ? ' (운송장 등록됨)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">배송비를 따로 받은 묶음은 따로 나갑니다. 묶음별로 운송장을 등록하면 그 상품만 배송 상태가 움직입니다.</div>
                        <?php // 막지는 않는다 — 같은 창고에서 나가면 한 상자에 담는 게 정상이다.
                              // 다만 묶음별 배송 상태를 나눌 수 없게 된다는 건 알고 골라야 한다. ?>
                        <div class="form-text text-warning d-none" id="shipmentWholeOrderNote">
                            <i class="bi bi-exclamation-triangle"></i>
                            이 주문은 배송비를 <?= count($shippingGroups) ?>번 받았습니다. 한 번에 보내면 묶음별로 배송 상태를 따로 관리할 수 없습니다.
                        </div>
                    </div>
                <?php endif; ?>
                <div class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <select id="shipmentCompany" class="form-select">
                            <option value="">택배사 선택</option>
                            <?php foreach ($deliveryCompanies as $dc): ?>
                            <option value="<?= (int) $dc['company_id'] ?>"><?= htmlspecialchars($dc['company_name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" id="shipmentInvoice" class="form-control" placeholder="송장번호">
                    </div>
                    <div class="col-md">
                        <input type="text" id="shipmentMemo" class="form-control" placeholder="메모 (선택)">
                    </div>
                    <div class="col-md-auto text-end">
                        <button type="button" id="shipmentCancelEdit" class="btn btn-link text-muted d-none p-0 me-2" onclick="resetShipmentForm()">취소</button>
                        <button type="button" id="shipmentSubmit" class="btn btn-primary" onclick="submitShipment()"><i class="bi bi-plus-lg"></i> 등록</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <div class="row">
        <div class="col-lg-8">
        <!-- 주문 추가 정보 (커스텀 필드) -->
        <?php if (!empty($orderFieldValues)): ?>
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-info-circle text-pastel-sky"></i>
                <span>주문 추가 정보</span>
            </div>
            <div class="card-body">
                <?php foreach ($orderFieldValues as $fv): ?>
                <div class="row mb-2">
                    <div class="col-3 text-muted"><?= htmlspecialchars($fv['field_label']) ?></div>
                    <div class="col-9">
                        <?php if ($fv['field_type'] === 'file' && !empty($fv['download_url'])): ?>
                            <a href="<?= htmlspecialchars($fv['download_url']) ?>" target="_blank">
                                <i class="bi bi-file-earmark"></i> <?= htmlspecialchars($fv['filename'] ?? '파일') ?>
                            </a>
                        <?php elseif ($fv['field_type'] === 'address'): ?>
                            <?= htmlspecialchars($fv['display_value'] ?? '') ?>
                        <?php elseif ($fv['field_type'] === 'textarea'): ?>
                            <?= nl2br(htmlspecialchars($fv['display_value'] ?? '')) ?>
                        <?php else: ?>
                            <?= htmlspecialchars($fv['display_value'] ?? '') ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 반품 정보 -->
        <?php if (!empty($orderReturns)): ?>
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-arrow-return-left text-pastel-orange"></i>
                <span>반품·교환 내역</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>유형</th>
                            <th>상태</th>
                            <th>사유</th>
                            <th class="text-end">환불금액</th>
                            <th>요청일</th>
                            <th>완료일</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderReturns as $ret): ?>
                        <?php
                            $rtLabel = ($ret['return_type'] ?? '') === 'RETURN' ? '반품' : (($ret['return_type'] ?? '') === 'EXCHANGE' ? '교환' : ($ret['return_type'] ?? '취소'));
                            $retStatusId = (string) ($ret['return_status'] ?? '');
                            $rsLabel = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom($retStatusId)?->label((string) ($ret['return_type'] ?? '')) ?? ($retStatusId ?: '-');
                            $rsBg = match (true) {
                                $retStatusId === 'COMPLETED' => 'success',
                                in_array($retStatusId, ['REFUSED', 'CANCELLED', 'REJECTED'], true) => 'danger',
                                default => 'warning',
                            };
                        ?>
                        <tr>
                            <td>
                                <?php if (in_array($ret['return_type'] ?? '', ['EXCHANGE', 'RETURN'], true)): ?>
                                    <a href="/admin/shop/claims/<?= (int) ($ret['return_id'] ?? 0) ?>?activeCode=K_Shop_016"><?= $rtLabel ?> <?= max(1, (int) ($ret['quantity'] ?? 1)) ?>개</a>
                                <?php else: ?>
                                    <?= $rtLabel ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $rsBg ?>-subtle text-<?= $rsBg ?>-emphasis border border-<?= $rsBg ?>-subtle"><?= $rsLabel ?></span></td>
                            <td>
                                <?= htmlspecialchars(\Mublo\Packages\Shop\Enum\ClaimReason::labelFor($ret['reason_type'] ?? '')) ?>
                                <?php if (!empty($ret['reason_detail'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($ret['reason_detail']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($ret['refused_reason'])): ?>
                                    <br><small class="text-danger">거절: <?= htmlspecialchars($ret['refused_reason']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((int) ($ret['refund_amount'] ?? 0)) ?>원</td>
                            <td><?= $ret['requested_at'] ?? $ret['created_at'] ?? '-' ?></td>
                            <td><?= $ret['completed_at'] ?? '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 결제·환불 내역 -->
        <?php if (!empty($paymentTransactions)): ?>
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-credit-card text-pastel-blue"></i>
                <span>결제·환불 내역</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>유형</th>
                            <th>상태</th>
                            <th>PG</th>
                            <th class="text-end">금액</th>
                            <th>일시</th>
                            <th>비고</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentTransactions as $tx): ?>
                        <?php
                            $txType = $tx['transaction_type'] ?? '';
                            $txTypeLabel = match($txType) {
                                'PAYMENT' => '결제',
                                'CANCEL' => '전액취소',
                                'PARTIAL_CANCEL' => '부분취소',
                                default => $txType,
                            };
                            $txTypeBg = match($txType) {
                                'PAYMENT' => 'success',
                                'CANCEL', 'PARTIAL_CANCEL' => 'danger',
                                default => 'secondary',
                            };
                            $txStatus = $tx['transaction_status'] ?? '';
                            $txStatusLabel = match($txStatus) {
                                'SUCCESS' => '성공',
                                'FAILED' => '실패',
                                'PENDING' => '대기',
                                default => $txStatus,
                            };
                            $txStatusBg = match($txStatus) {
                                'SUCCESS' => 'success',
                                'FAILED' => 'danger',
                                'PENDING' => 'warning',
                                default => 'secondary',
                            };
                            $txAmount = ($txType === 'PAYMENT')
                                ? (int) ($tx['amount'] ?? 0)
                                : (int) ($tx['cancel_amount'] ?? 0);
                            // POINT 환불 건: 지급 재시도 버튼 노출 (멱등 — 이미 지급됐으면 안내만)
                            $isPointRefundTx = ($tx['pg_key'] ?? '') === 'manual'
                                && str_starts_with((string) ($tx['admin_memo'] ?? ''), '포인트 환불')
                                && ($tx['transaction_status'] ?? '') === 'SUCCESS';
                        ?>
                        <tr>
                            <td><span class="badge bg-<?= $txTypeBg ?>-subtle text-<?= $txTypeBg ?>-emphasis border border-<?= $txTypeBg ?>-subtle"><?= $txTypeLabel ?></span></td>
                            <td><span class="badge bg-<?= $txStatusBg ?>-subtle text-<?= $txStatusBg ?>-emphasis border border-<?= $txStatusBg ?>-subtle"><?= $txStatusLabel ?></span></td>
                            <td><?= htmlspecialchars($tx['pg_provider'] ?? '-') ?></td>
                            <td class="text-end"><?= $txType === 'PAYMENT' ? '' : '-' ?><?= number_format($txAmount) ?>원</td>
                            <td><small><?= $tx['created_at'] ?? '' ?></small></td>
                            <td>
                                <small class="text-muted"><?= htmlspecialchars($tx['cancel_reason'] ?? '') ?></small>
                                <?php if ($isPointRefundTx): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 ms-1"
                                        onclick="retryRefundPoint(<?= (int) ($tx['transaction_id'] ?? 0) ?>)"
                                        title="포인트 지급이 실패했던 경우 재시도합니다. 이미 지급된 건은 안내만 표시됩니다.">
                                    <i class="bi bi-arrow-repeat"></i> 포인트 재지급
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 상태 변경 이력 -->
        <?php if (!empty($orderLogs)): ?>
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-clock-history text-pastel-green"></i>
                <span>상태 변경 이력</span>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($orderLogs as $log): ?>
                    <?php
                        $logType = $log['change_type'] ?? 'STATUS';
                        $prevStatus = $log['prev_status'] ?? '';
                        $newStatus = $log['new_status'] ?? '';
                        // 실제 상태 전이가 있을 때만 '이전 → 다음'을 표시한다.
                        // 환불 등 사건(event) 로그는 prev/new가 비어(또는 동일) 있어 화살표를 생략한다.
                        $isTransition = $prevStatus !== $newStatus;

                        // PAYMENT 타입은 두 갈래: 환불(RefundService는 상태를 비움) vs
                        // 결제이벤트(logEvent는 현재상태로 채움 — PG 준비/검증 실패 등).
                        // 타입 배지 옆에 환불/결제 분류 배지를 덧붙인다.
                        $paymentKind = null;
                        if ($logType === 'PAYMENT') {
                            $paymentKind = ($prevStatus === '')
                                ? ['label' => '환불', 'color' => 'danger']
                                : ['label' => '결제', 'color' => 'secondary'];
                        }
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge border border-secondary text-secondary"><?= htmlspecialchars($logType) ?></span>
                                <?php if ($isTransition): // 상태 전이가 있으면 항상 먼저 표시 (섹션 주제) ?>
                                <?= $statusBadge($prevStatus, $log['prev_status_label'] ?? $prevStatus, 'ms-1') ?>
                                <i class="bi bi-arrow-right mx-1"></i>
                                <?= $statusBadge($newStatus, $log['new_status_label'] ?? $newStatus) ?>
                                <?php endif; ?>
                                <?php if ($paymentKind !== null): // 유형별 부가정보는 전이 뒤에 덧붙임 ?>
                                <span class="badge bg-<?= $paymentKind['color'] ?>-subtle text-<?= $paymentKind['color'] ?>-emphasis border border-<?= $paymentKind['color'] ?>-subtle ms-1"><?= $paymentKind['label'] ?></span>
                                <?php endif; ?>
                                <?php if (!empty($log['reason'])): ?>
                                    <br><small class="text-muted mt-1"><?= htmlspecialchars($log['reason']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <small class="text-muted"><?= $log['created_at'] ?? '' ?></small>
                                <?php if (!empty($log['changed_by'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($log['changed_by']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        </div>

        <div class="col-lg-4">
        <!-- 환불 처리 -->
        <?php
            $totalPaid = (int) ($refundInfo['total_paid'] ?? 0);
            $totalRefunded = (int) ($refundInfo['total_refunded'] ?? 0);
            $refundable = (int) ($refundInfo['refundable'] ?? 0);
        ?>
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-cash-coin text-pastel-sky"></i>
                <span>환불 처리</span>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">총 결제 금액</span>
                    <span><?= number_format($totalPaid) ?>원</span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">기환불 금액</span>
                    <span class="text-danger"><?= $totalRefunded > 0 ? '-' : '' ?><?= number_format($totalRefunded) ?>원</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between mb-2">
                    <strong>환불 가능 금액</strong>
                    <strong class="text-primary"><?= number_format($refundable) ?>원</strong>
                </div>
                <?php if ($refundable > 0): ?>
                <button type="button" class="btn btn-danger w-100" onclick="openRefundModal()">
                    <i class="bi bi-cash-coin"></i> 환불 처리
                </button>
                <?php else: ?>
                <p class="text-muted mb-0 text-center"><small>환불 가능 금액이 없습니다.</small></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 관리자 메모 -->
        <div class="card mb-4">
            <div class="card-hero">
                <i class="bi bi-journal-text text-pastel-blue"></i>
                <span>관리자 메모</span>
                <span class="badge bg-secondary ms-auto"><?= count($orderMemos) ?></span>
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <select id="memoType" class="form-select mb-2">
                        <?php foreach ($memoTypeLabels as $typeKey => $typeLabel): ?>
                        <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea id="memoContent" class="form-control mb-2" rows="3" placeholder="메모 내용을 입력하세요."></textarea>
                    <button type="button" class="btn btn-outline-primary w-100" onclick="submitMemo()">
                        <i class="bi bi-plus-lg"></i> 메모 추가
                    </button>
                </div>
                <?php if (!empty($orderMemos)): ?>
                <hr class="my-2">
                <div style="max-height:300px;overflow-y:auto">
                    <?php foreach ($orderMemos as $memo): ?>
                    <?php
                        $memoTypeBg = match($memo['memo_type'] ?? 'MEMO') {
                            'CS_CALL' => 'success',
                            'CS_CHAT' => 'info',
                            'CS_EMAIL' => 'primary',
                            'INTERNAL' => 'dark',
                            default => 'secondary',
                        };
                        $memoTypeLabel = $memoTypeLabels[$memo['memo_type'] ?? 'MEMO'] ?? $memo['memo_type'] ?? '메모';
                    ?>
                    <div class="border rounded p-2 mb-2 position-relative">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="badge bg-<?= $memoTypeBg ?>"><?= htmlspecialchars($memoTypeLabel) ?></span>
                            <button type="button" class="btn btn-sm text-danger p-0" onclick="deleteMemo(<?= (int) ($memo['memo_id'] ?? 0) ?>)" title="삭제">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="small"><?= nl2br(htmlspecialchars($memo['content'] ?? '')) ?></div>
                        <div class="text-muted mt-1" style="font-size:0.75rem">
                            <?= $memo['created_at'] ?? '' ?>
                            <?php if (!empty($memo['staff_id'])): ?>
                                · 담당자 #<?= (int) $memo['staff_id'] ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- 모달 1: 아이템 상태 변경 -->
<div class="modal fade" id="itemStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 상태 변경</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="itemStatusName"></strong></p>
                <input type="hidden" id="itemStatusDetailId">
                <div class="mb-3">
                    <label class="form-label">변경 상태</label>
                    <select id="itemStatusSelect" class="form-select">
                        <option value="">선택</option>
                        <?php foreach ($orderStatusOptions as $id => $label): ?>
                        <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">변경 사유 (선택)</label>
                    <textarea id="itemStatusReason" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
                <button type="button" class="btn btn-primary" onclick="submitItemStatus()">변경</button>
            </div>
        </div>
    </div>
</div>

<!-- 모달 2: 아이템 취소 -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 취소</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="cancelItemName"></strong></p>
                <input type="hidden" id="cancelDetailId">
                <div class="mb-3">
                    <label class="form-label">취소 사유 <span class="text-danger">*</span></label>
                    <textarea id="cancelReason" class="form-control" rows="3" placeholder="취소 사유를 입력해주세요."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
                <button type="button" class="btn btn-danger" onclick="submitCancel()">취소 처리</button>
            </div>
        </div>
    </div>
</div>

<!-- 모달 3: 반품 -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">반품 접수</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="returnItemName"></strong></p>
                <input type="hidden" id="returnDetailId">
                <div class="mb-3">
                    <label class="form-label">수량</label>
                    <input type="number" id="returnQuantity" class="form-control" min="1" value="1">
                </div>
                <div class="mb-3">
                    <label class="form-label">사유 유형</label>
                    <select id="returnReasonType" class="form-select">
                        <option value="">선택</option>
                        <?php foreach (\Mublo\Packages\Shop\Enum\ClaimReason::options() as $reasonValue => $reasonLabel): ?>
                        <option value="<?= htmlspecialchars($reasonValue) ?>"><?= htmlspecialchars($reasonLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">상세 사유</label>
                    <textarea id="returnReasonDetail" class="form-control" rows="3" placeholder="상세 사유를 입력해주세요."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
                <button type="button" class="btn btn-warning" onclick="submitReturn()">요청 접수</button>
            </div>
        </div>
    </div>
</div>

<!-- 모달 5: 환불 처리 -->
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">환불 처리</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 mb-3">
                    <div class="d-flex justify-content-between">
                        <span>환불 가능 금액</span>
                        <strong id="refundableDisplay"><?= number_format($refundable) ?>원</strong>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">환불 금액 <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" id="refundAmount" class="form-control" min="1" max="<?= $refundable ?>" placeholder="0" oninput="updateRefundNote()">
                        <span class="input-group-text">원</span>
                        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('refundAmount').value='<?= $refundable ?>';updateRefundNote()">전액</button>
                    </div>
                </div>
                <div id="refundStateNote" class="alert py-2 mb-3 small d-none"></div>
                <div class="mb-3">
                    <label class="form-label">환불 방법 <span class="text-danger">*</span></label>
                    <select id="refundMethod" class="form-select" onchange="toggleBankInfo()">
                        <option value="">선택</option>
                        <option value="PG_CANCEL">PG 결제 취소 (카드/간편결제)</option>
                        <option value="BANK">무통장 환불</option>
                        <option value="POINT">포인트 환불</option>
                    </select>
                </div>
                <div id="bankInfoArea" style="display:none">
                    <div class="mb-2">
                        <label class="form-label">은행명</label>
                        <input type="text" id="refundBank" class="form-control" placeholder="은행명">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">계좌번호</label>
                        <input type="text" id="refundAccount" class="form-control" placeholder="계좌번호">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">예금주</label>
                        <input type="text" id="refundHolder" class="form-control" placeholder="예금주">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">환불 사유 <span class="text-danger">*</span></label>
                    <textarea id="refundReason" class="form-control" rows="3" placeholder="환불 사유를 입력해주세요."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
                <button type="button" class="btn btn-danger" onclick="submitRefund()">환불 처리</button>
            </div>
        </div>
    </div>
</div>
</div>

<script>
document.addEventListener('click', function(event) {
    const link = event.target.closest('.js-order-item-status, .js-order-item-cancel, .js-order-item-return');
    if (!link) return;
    event.preventDefault();

    const detailId = Number(link.dataset.detailId || 0);
    const itemName = link.dataset.itemName || '';
    if (!detailId) return;

    if (link.classList.contains('js-order-item-status')) openItemStatusModal(detailId, itemName);
    else if (link.classList.contains('js-order-item-cancel')) openCancelModal(detailId, itemName);
    else openReturnModal(detailId, itemName);
});

var ORDER_NO = <?= json_encode($orderNo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
// 환불이 상태를 끈다: 전액환불 시 자동취소 가능 여부 + 안내용 값
var REFUNDABLE = <?= (int) $refundable ?>;
var POINT_USED = <?= (int) ($order['point_used'] ?? 0) ?>;
var AUTOCANCEL_ELIGIBLE = <?= in_array(($order['order_status'] ?? ''), ['received', 'paid', 'cancel_requested'], true) ? 'true' : 'false' ?>;
// 역방향 가드: 미환불 잔액이 있는데 종료(취소/반품) 상태로 바꾸려 하면 경고
var CANCEL_LIKE_STATES = <?= json_encode(array_values(array_map(
    static fn($t) => $t['id'],
    array_filter($availableTransitions, static fn($t) => in_array($t['action'] ?? '', ['cancelled', 'returned'], true))
)), JSON_UNESCAPED_UNICODE) ?>;

// 상품 테이블 관리 드롭다운: .table-responsive(overflow) 클리핑 회피 — Popper fixed 전략
document.querySelectorAll('.order-items-table [data-bs-toggle="dropdown"]').forEach(function (el) {
    bootstrap.Dropdown.getOrCreateInstance(el, {
        popperConfig: function (defaultConfig) {
            return Object.assign({}, defaultConfig, { strategy: 'fixed' });
        }
    });
});

// ===== 주문 상태 변경 =====
document.getElementById('btnChangeStatus')?.addEventListener('click', function() {
    var status = document.getElementById('newOrderStatus').value;
    var reason = document.getElementById('statusReason').value;

    if (!status) {
        alert('변경할 상태를 선택해주세요.');
        return;
    }
    // 역방향 가드: 미환불 잔액이 있는데 종료(취소/반품)로 바꾸면 현금 미환불 종료가 됨
    var confirmMsg = '주문 상태를 변경하시겠습니까?';
    if (CANCEL_LIKE_STATES.indexOf(status) !== -1 && REFUNDABLE > 0) {
        confirmMsg = '⚠️ 미환불 잔액 ' + REFUNDABLE.toLocaleString() + '원이 남아 있습니다.\n'
            + '환불 없이 종료 처리하면 현금이 미환불 상태로 마감됩니다.\n(원칙: 환불 처리가 상태를 변경합니다 — 먼저 환불을 권장)\n그래도 진행하시겠습니까?';
    }
    if (!confirm(confirmMsg)) return;

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/status', {
        order_status: status,
        reason: reason
    }).then(function() {
        location.reload();
    });
});

// ===== 아이템 상태 변경 =====
function openItemStatusModal(detailId, name) {
    document.getElementById('itemStatusDetailId').value = detailId;
    document.getElementById('itemStatusName').textContent = name;
    document.getElementById('itemStatusSelect').value = '';
    document.getElementById('itemStatusReason').value = '';
    new bootstrap.Modal(document.getElementById('itemStatusModal')).show();
}

function submitItemStatus() {
    var detailId = document.getElementById('itemStatusDetailId').value;
    var status = document.getElementById('itemStatusSelect').value;
    var reason = document.getElementById('itemStatusReason').value;

    if (!status) {
        alert('변경할 상태를 선택해주세요.');
        return;
    }

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/items/' + detailId + '/status', {
        order_status: status,
        reason: reason
    }).then(function() {
        location.reload();
    });
}

// ===== 아이템 취소 =====
function openCancelModal(detailId, name) {
    document.getElementById('cancelDetailId').value = detailId;
    document.getElementById('cancelItemName').textContent = name;
    document.getElementById('cancelReason').value = '';
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

function submitCancel() {
    var detailId = document.getElementById('cancelDetailId').value;
    var reason = document.getElementById('cancelReason').value;

    if (!reason.trim()) {
        alert('취소 사유를 입력해주세요.');
        return;
    }

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/items/' + detailId + '/cancel', {
        reason: reason
    }).then(function() {
        location.reload();
    });
}

// ===== 환불 포인트 재지급 (멱등 — 이미 지급된 건은 안내만) =====
function retryRefundPoint(transactionId) {
    if (!confirm('환불 포인트 지급을 재시도할까요?\n이미 지급된 건이면 이중 지급 없이 안내만 표시됩니다.')) return;
    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/refund-point-retry', {
        transaction_id: transactionId
    }).then(function(res) {
        alert(res.message || '처리되었습니다.');
    });
}

// ===== 반품 =====
function openReturnModal(detailId, name) {
    document.getElementById('returnDetailId').value = detailId;
    document.getElementById('returnItemName').textContent = name;
    document.getElementById('returnReasonType').value = '';
    document.getElementById('returnReasonDetail').value = '';
    new bootstrap.Modal(document.getElementById('returnModal')).show();
}

function submitReturn() {
    var detailId = document.getElementById('returnDetailId').value;
    var reasonType = document.getElementById('returnReasonType').value;
    var reasonDetail = document.getElementById('returnReasonDetail').value;

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/items/' + detailId + '/return', {
        quantity: parseInt(document.getElementById('returnQuantity').value, 10) || 1,
        return_type: 'RETURN',
        reason_type: reasonType,
        reason_detail: reasonDetail
    }).then(function() {
        location.reload();
    });
}

// ===== 환불 =====
function openRefundModal() {
    document.getElementById('refundAmount').value = '';
    document.getElementById('refundMethod').value = '';
    document.getElementById('refundReason').value = '';
    document.getElementById('refundBank').value = '';
    document.getElementById('refundAccount').value = '';
    document.getElementById('refundHolder').value = '';
    document.getElementById('bankInfoArea').style.display = 'none';
    updateRefundNote();
    new bootstrap.Modal(document.getElementById('refundModal')).show();
}

function toggleBankInfo() {
    var method = document.getElementById('refundMethod').value;
    document.getElementById('bankInfoArea').style.display = method === 'BANK' ? '' : 'none';
}

// 입력 금액에 따라 환불 후 상태 변화를 미리 안내 (환불이 상태를 끈다)
function updateRefundNote() {
    var note = document.getElementById('refundStateNote');
    if (!note) return;
    var amount = parseInt(document.getElementById('refundAmount').value) || 0;

    if (amount <= 0) {
        note.className = 'alert py-2 mb-3 small d-none';
        note.innerHTML = '';
        return;
    }
    if (amount >= REFUNDABLE) {
        var msg = '⚠️ <strong>전액 환불</strong>입니다.';
        if (AUTOCANCEL_ELIGIBLE) {
            msg += ' 처리 시 주문이 <strong>주문취소</strong>로 자동 변경됩니다';
            msg += (POINT_USED > 0) ? ' (포인트 ' + POINT_USED.toLocaleString() + 'P 복원).' : '.';
        }
        note.className = 'alert alert-warning py-2 mb-3 small';
        note.innerHTML = msg;
    } else {
        note.className = 'alert alert-info py-2 mb-3 small';
        note.innerHTML = 'ℹ️ <strong>부분 환불</strong>입니다. 주문 상태는 유지됩니다. '
            + '(처리 후 잔여 환불가능 ' + (REFUNDABLE - amount).toLocaleString() + '원)';
    }
}

function submitRefund() {
    var amount = parseInt(document.getElementById('refundAmount').value) || 0;
    var method = document.getElementById('refundMethod').value;
    var reason = document.getElementById('refundReason').value;

    if (amount <= 0) {
        alert('환불 금액을 입력해주세요.');
        return;
    }
    if (!method) {
        alert('환불 방법을 선택해주세요.');
        return;
    }
    if (!reason.trim()) {
        alert('환불 사유를 입력해주세요.');
        return;
    }

    var data = {
        amount: amount,
        refund_method: method,
        reason: reason
    };

    if (method === 'BANK') {
        data.refund_bank = document.getElementById('refundBank').value;
        data.refund_account = document.getElementById('refundAccount').value;
        data.refund_holder = document.getElementById('refundHolder').value;
    }

    var confirmMsg = amount.toLocaleString() + '원을 환불하시겠습니까?';
    if (amount >= REFUNDABLE && AUTOCANCEL_ELIGIBLE) {
        confirmMsg = amount.toLocaleString() + '원 전액을 환불하고 주문을 취소 처리하시겠습니까?';
    }
    if (!confirm(confirmMsg)) return;

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/refund', data).then(function() {
        location.reload();
    });
}

// ===== 주문 상품 선택 · 일괄 상태 변경 =====
(function () {
    var applyBtn = document.getElementById('itemBulkApply');
    var statusSel = document.getElementById('itemBulkStatus');
    var countEl = document.getElementById('itemBulkCount');
    if (!applyBtn || !statusSel || !countEl) { return; }

    function selectable() {
        return Array.prototype.slice.call(document.querySelectorAll('.js-item-check:not([disabled])'));
    }
    function selected() {
        return selectable().filter(function (box) { return box.checked; });
    }
    function sync() {
        var n = selected().length;
        countEl.textContent = '선택 ' + n + '개';
        statusSel.disabled = n === 0;
        applyBtn.disabled = n === 0 || statusSel.value === '';
    }

    var checkAll = document.getElementById('itemCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var on = this.checked;
            selectable().forEach(function (box) { box.checked = on; });
            document.querySelectorAll('.js-group-check').forEach(function (g) { g.checked = on; });
            sync();
        });
    }
    // 묶음 체크박스는 그 묶음의 상품만 집는다 — 출고 단위가 곧 선택 단위다
    document.querySelectorAll('.js-group-check').forEach(function (group) {
        group.addEventListener('change', function () {
            selectable().forEach(function (box) {
                if (box.dataset.groupNo === group.dataset.groupNo) { box.checked = group.checked; }
            });
            sync();
        });
    });
    selectable().forEach(function (box) { box.addEventListener('change', sync); });
    statusSel.addEventListener('change', sync);

    applyBtn.addEventListener('click', function () {
        var ids = selected().map(function (box) { return parseInt(box.value, 10); });
        if (ids.length === 0 || statusSel.value === '') { return; }
        applyBtn.disabled = true;
        MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/items/bulk-status', {
            detail_ids: ids,
            order_status: statusSel.value
        }).then(function () {
            location.reload();
        }).catch(function () {
            applyBtn.disabled = false;
        });
    });

    sync();
})();

// ===== 배송(운송장) =====
function resetShipmentForm() {
    var idEl = document.getElementById('shipmentEditId');
    if (!idEl) return;
    idEl.value = '';
    document.getElementById('shipmentCompany').value = '';
    document.getElementById('shipmentInvoice').value = '';
    document.getElementById('shipmentMemo').value = '';
    document.getElementById('shipmentFormTitle').textContent = '운송장 등록';
    document.getElementById('shipmentSubmit').innerHTML = '<i class="bi bi-plus-lg"></i> 등록';
    document.getElementById('shipmentCancelEdit').classList.add('d-none');
    // 등록 모드에서만 배송 묶음을 고른다 (수정은 송장 정보만 바꾼다)
    var groupWrap = document.getElementById('shipmentGroupWrap');
    if (groupWrap) {
        groupWrap.classList.remove('d-none');
        document.getElementById('shipmentGroup').value = '';
        syncWholeOrderNote();
    }
}

// 주문 상품 표의 묶음 헤더에서 부른다 — 그 묶음으로 폼을 맞춰 두고 커서까지 옮긴다.
// 묶음 키는 data-* 로 넘긴다 (인라인 onclick 안의 값은 JS 코드로 파싱되므로)
document.getElementById('shipmentGroup')?.addEventListener('change', syncWholeOrderNote);
syncWholeOrderNote();

document.querySelectorAll('.js-group-shipment').forEach(function (btn) {
    btn.addEventListener('click', function () {
        prepareGroupShipment(this.dataset.groupKey);
    });
});

function prepareGroupShipment(groupKey) {
    resetShipmentForm();
    var sel = document.getElementById('shipmentGroup');
    if (sel) { sel.value = groupKey; }
    var invoice = document.getElementById('shipmentInvoice');
    if (!invoice) { return; }
    invoice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    invoice.focus();
}

// '주문 전체'를 고르면 묶음별 배송 상태를 나눌 수 없다는 걸 그 자리에서 알린다
function syncWholeOrderNote() {
    var sel = document.getElementById('shipmentGroup');
    var note = document.getElementById('shipmentWholeOrderNote');
    if (!sel || !note) { return; }
    note.classList.toggle('d-none', sel.value !== '');
}

function editShipment(id, companyId, invoiceNo, memo) {
    document.getElementById('shipmentEditId').value = id;
    document.getElementById('shipmentCompany').value = companyId || '';
    document.getElementById('shipmentInvoice').value = invoiceNo || '';
    document.getElementById('shipmentMemo').value = memo || '';
    document.getElementById('shipmentFormTitle').textContent = '운송장 수정 (#' + id + ')';
    document.getElementById('shipmentSubmit').innerHTML = '<i class="bi bi-check-lg"></i> 수정';
    document.getElementById('shipmentCancelEdit').classList.remove('d-none');
    var groupWrapEdit = document.getElementById('shipmentGroupWrap');
    if (groupWrapEdit) { groupWrapEdit.classList.add('d-none'); }
    document.getElementById('shipmentInvoice').focus();
}

function submitShipment() {
    var editId = document.getElementById('shipmentEditId').value;
    var companyId = document.getElementById('shipmentCompany').value;
    var invoiceNo = document.getElementById('shipmentInvoice').value.trim();
    var memo = document.getElementById('shipmentMemo').value.trim();

    if (!invoiceNo) {
        alert('송장번호를 입력해주세요.');
        return;
    }

    var data = {
        company_id: companyId ? parseInt(companyId) : null,
        invoice_no: invoiceNo,
        admin_memo: memo
    };
    var groupEl = document.getElementById('shipmentGroup');
    if (!editId && groupEl) {
        data.shipping_group_key = groupEl.value;
    }
    var url = editId
        ? '/admin/shop/orders/' + ORDER_NO + '/shipments/' + editId
        : '/admin/shop/orders/' + ORDER_NO + '/shipments';

    MubloRequest.requestJson(url, data).then(function() {
        location.reload();
    });
}

function deleteShipment(id) {
    if (!confirm('이 운송장을 삭제하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/shipments/' + id + '/delete', {}).then(function() {
        location.reload();
    });
}

// ===== 관리자 메모 =====
function submitMemo() {
    var content = document.getElementById('memoContent').value;
    var memoType = document.getElementById('memoType').value;

    if (!content.trim()) {
        alert('메모 내용을 입력해주세요.');
        return;
    }

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/memos', {
        content: content,
        memo_type: memoType
    }).then(function() {
        location.reload();
    });
}

function deleteMemo(memoId) {
    if (!confirm('메모를 삭제하시겠습니까?')) return;

    MubloRequest.requestJson('/admin/shop/orders/' + ORDER_NO + '/memos/' + memoId + '/delete', {}).then(function() {
        location.reload();
    });
}
</script>
