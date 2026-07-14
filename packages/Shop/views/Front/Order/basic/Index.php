<?php
/**
 * 주문 목록 (프론트)
 *
 * @var array $orders 주문 목록
 * @var array $pagination 페이지네이션 정보
 * @var array $allStates 전체 상태 목록 (라벨 매핑용)
 */

$orders = $orders ?? [];
$pagination = $pagination ?? [];
$allStates = $allStates ?? [];
$keyword = (string) ($keyword ?? '');
$isGuest = (bool) ($isGuest ?? false);

// 상세→목록 복귀 시 현재 페이지·검색어 보존용 쿼리
$listPage = (int) ($pagination['currentPage'] ?? 1);
$listParams = [];
if ($listPage > 1) { $listParams['page'] = $listPage; }
if ($keyword !== '') { $listParams['keyword'] = $keyword; }
$listQs = $listParams ? '?' . http_build_query($listParams) : '';

// 상태 id → {label, action} 매핑
$statusMap = [];
foreach ($allStates as $s) {
    $statusMap[$s['id'] ?? ''] = $s;
}

// 상태 action → 배지 색상
function badgeColor(string $action): string
{
    return match ($action) {
        'received'                           => '#f59e0b',
        'paid'                               => '#3b82f6',
        'preparing', 'shipping'              => '#667eea',
        'delivered', 'confirmed'             => '#22c55e',
        'cancelled', 'returned'              => '#ef4444',
        'cancel_requested', 'return_requested' => '#f97316',
        default                              => '#6b7280',
    };
}

$this->assets->addCss('/serve/package/Shop/views/Front/Order/basic/_assets/css/order-index.css');
?>


<div class="shop-order-list">

    <h2 class="shop-order-list__title">주문내역</h2>

    <?php if (!$isGuest): ?>
        <form method="get" action="/shop/orders" class="shop-order-list__search" role="search">
            <input type="text" name="keyword" value="<?= e($keyword) ?>" placeholder="상품명 검색 (띄어쓰기로 여러 단어)" class="shop-order-list__search-input" autocomplete="off">
            <?php if ($keyword !== ''): ?>
                <a href="/shop/orders" class="shop-order-list__search-clear" title="검색 초기화" aria-label="검색 초기화"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
            <button type="submit" class="shop-order-list__search-btn">검색</button>
        </form>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="shop-order-list__empty">
            <?php if ($keyword !== ''): ?>
                <p>"<?= e($keyword) ?>" 검색 결과가 없습니다.</p>
                <a href="/shop/orders" class="shop-order-list__empty-btn">전체 주문 보기</a>
            <?php else: ?>
                <p>주문 내역이 없습니다.</p>
                <a href="/shop/products" class="shop-order-list__empty-btn">쇼핑하러 가기</a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <?php foreach ($orders as $order):
            $orderNo = $order['order_no'] ?? '';
            $createdAt = $order['created_at'] ?? '';
            $dateText = '';
            if ($createdAt) {
                $ts = strtotime($createdAt);
                $dateText = $ts ? date('n.j H:i', $ts) : $createdAt;
            }

            // 상태 라벨/색상
            $st = $statusMap[$order['order_status'] ?? ''] ?? null;
            $label = $st['label'] ?? ($order['order_status'] ?? '-');
            $action = $st['action'] ?? 'custom';
            // 뱃지 색은 뷰 자체 구현(action별 고정색, 백엔드 color 무시)
            $color = badgeColor($action);

            // 최종 결제 금액
            $finalAmount = (int) ($order['total_price'] ?? 0)
                         - (int) ($order['coupon_discount'] ?? 0)
                         - (int) ($order['point_used'] ?? 0)
                         + (int) ($order['shipping_fee'] ?? 0)
                         + (int) ($order['tax_amount'] ?? 0);

            // 대표 상품 (첫 번째 아이템)
            $items = $order['items'] ?? [];
            $firstItem = $items[0] ?? null;
            $itemCount = count($items);
            $productName = $firstItem['goods_name'] ?? '';
            $thumbImage = $firstItem['goods_image'] ?? '';
        ?>
        <div class="shop-order-list__card">
            <div class="shop-order-list__card-top">
                <span class="shop-order-list__card-no">주문번호: <?= e($orderNo) ?></span>
                <span class="shop-order-list__card-badge" style="color:<?= $color ?>;background:color-mix(in srgb, <?= $color ?> 14%, var(--card))"><?= e($label) ?></span>
            </div>
            <div class="shop-order-list__card-main">
                <?php if ($thumbImage): ?>
                    <img src="<?= e($thumbImage) ?>" alt="" class="shop-order-list__card-thumb">
                <?php else: ?>
                    <span class="shop-order-list__card-thumb--empty">&#128230;</span>
                <?php endif; ?>
                <div class="shop-order-list__card-info">
                    <?php if ($dateText): ?>
                        <div class="shop-order-list__card-date"><?= e($dateText) ?> 주문</div>
                    <?php endif; ?>
                    <div class="shop-order-list__card-name"><?= e($productName ?: '상품 정보 없음') ?><?php if ($itemCount > 1): ?> <span class="shop-order-list__card-extra">외 <?= $itemCount - 1 ?>건</span><?php endif; ?></div>
                    <div class="shop-order-list__card-price"><?= number_format($finalAmount) ?>원</div>
                </div>
                <div class="shop-order-list__card-aside">
                    <a class="shop-order-list__card-btn" href="/shop/order/<?= e($orderNo) ?><?= $listQs ?>">상세보기</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
            <?php
            // pagination() 헬퍼가 CSS 등록 + queryString(현재 검색어 포함) 처리까지 담당한다.
            echo $this->pagination($pagination);
            ?>
        <?php endif; ?>

    <?php endif; ?>

</div>
