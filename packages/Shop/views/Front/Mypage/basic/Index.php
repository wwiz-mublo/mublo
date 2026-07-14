<?php
/**
 * 마이쇼핑 — Shop 마이페이지 허브(요약). 코어 셸(Section.php) 안에 렌더되는 전용 뷰.
 *
 * 원칙: 요약본 + 본체(/shop/*)로 가는 통로. 상세는 프레임 안에서 안 그린다(클릭 시 단독 본체로).
 *
 * @var array $recentOrders    최근 주문(최대 5)
 * @var int   $orderCount      전체 주문 수
 * @var int   $couponCount     사용가능 쿠폰 수
 * @var int   $wishlistCount   찜한상품 수
 * @var array $recentReviews   최근 구매후기(최대 5)
 * @var int   $reviewCount     내 구매후기 수
 * @var array $recentInquiries 최근 상품문의(최대 5)
 * @var int   $inquiryCount    내 상품문의 수
 * @var array $allStates       주문상태 라벨 매핑
 */
$recentOrders    = $recentOrders ?? [];
$orderCount      = (int) ($orderCount ?? 0);
$couponCount     = (int) ($couponCount ?? 0);
$wishlistCount   = (int) ($wishlistCount ?? 0);
$recentReviews   = $recentReviews ?? [];
$reviewCount     = (int) ($reviewCount ?? 0);
$recentInquiries = $recentInquiries ?? [];
$inquiryCount    = (int) ($inquiryCount ?? 0);
$allStates       = $allStates ?? [];
// 상태 id → 라벨
$statusMap = [];
foreach ($allStates as $s) { $statusMap[$s['id'] ?? ''] = $s['label'] ?? ''; }

$fmtDate = function ($v): string { $ts = $v ? strtotime((string) $v) : false; return $ts ? date('Y.m.d', $ts) : ''; };

$this->assets->addCss('/serve/package/Shop/views/Front/Mypage/basic/_assets/css/mypage.css');
?>

<div class="myshop">
    <div class="mypage-header">
        <h1 class="mypage-header__title">마이쇼핑</h1>
        <p class="mypage-header__desc">주문·리뷰·문의 내역을 한눈에 봅니다.</p>
    </div>

    <div class="myshop__stats">
        <a href="/shop/orders" class="myshop__stat">
            <span class="myshop__stat-num"><?= number_format($orderCount) ?></span>
            <span class="myshop__stat-label">주문내역</span>
        </a>
        <a href="/shop/coupons" class="myshop__stat">
            <span class="myshop__stat-num"><?= number_format($couponCount) ?></span>
            <span class="myshop__stat-label">할인쿠폰</span>
        </a>
        <a href="/shop/wishlist" class="myshop__stat">
            <span class="myshop__stat-num"><?= number_format($wishlistCount) ?></span>
            <span class="myshop__stat-label">찜한상품</span>
        </a>
    </div>

    <!-- 최근 주문 -->
    <div class="myshop__section">
        <div class="myshop__section-head">
            <h3 class="myshop__section-title">최근 주문</h3>
            <a href="/shop/orders" class="myshop__more">전체 보기 ›</a>
        </div>
        <?php if (empty($recentOrders)): ?>
            <p class="myshop__empty">주문 내역이 없습니다.</p>
        <?php else: ?>
            <ul class="myshop__list">
                <?php foreach ($recentOrders as $order):
                    $orderNo   = (string) ($order['order_no'] ?? '');
                    $label     = $statusMap[$order['order_status'] ?? ''] ?? ($order['order_status'] ?? '-');
                    $items     = $order['items'] ?? [];
                    $firstName = $items[0]['goods_name'] ?? '상품 정보 없음';
                    $more      = count($items) > 1 ? ' 외 ' . (count($items) - 1) . '건' : '';
                ?>
                <li>
                    <a href="/shop/order/<?= e($orderNo) ?>" class="myshop__row">
                        <span class="myshop__row-date"><?= e($fmtDate($order['created_at'] ?? '')) ?></span>
                        <span class="myshop__row-main"><?= e($firstName) ?><?= e($more) ?></span>
                        <span class="myshop__row-meta"><?= e($label) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- 최근 구매후기 -->
    <div class="myshop__section">
        <div class="myshop__section-head">
            <h3 class="myshop__section-title">최근 구매후기</h3>
            <span class="myshop__count">총 <?= number_format($reviewCount) ?>건</span>
        </div>
        <?php if (empty($recentReviews)): ?>
            <p class="myshop__empty">작성한 구매후기가 없습니다.</p>
        <?php else: ?>
            <ul class="myshop__list">
                <?php foreach ($recentReviews as $rv):
                    $gid    = (int) ($rv['goods_id'] ?? 0);
                    $gname  = $rv['goods_name'] ?? '상품';
                    $rating = (int) ($rv['rating'] ?? 0);
                ?>
                <li>
                    <a href="/shop/products/<?= $gid ?>" class="myshop__row">
                        <span class="myshop__row-date"><?= e($fmtDate($rv['created_at'] ?? '')) ?></span>
                        <span class="myshop__row-main"><?= e($gname) ?></span>
                        <span class="myshop__row-meta">★ <?= $rating ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- 최근 상품문의 -->
    <div class="myshop__section">
        <div class="myshop__section-head">
            <h3 class="myshop__section-title">최근 상품문의</h3>
            <span class="myshop__count">총 <?= number_format($inquiryCount) ?>건</span>
        </div>
        <?php if (empty($recentInquiries)): ?>
            <p class="myshop__empty">작성한 상품문의가 없습니다.</p>
        <?php else: ?>
            <ul class="myshop__list">
                <?php foreach ($recentInquiries as $iq):
                    $gid   = (int) ($iq['goods_id'] ?? 0);
                    $title = $iq['title'] ?? '';
                    if ($title === '') { $title = $iq['goods_name'] ?? '상품문의'; }
                ?>
                <li>
                    <a href="/shop/products/<?= $gid ?>" class="myshop__row">
                        <span class="myshop__row-date"><?= e($fmtDate($iq['created_at'] ?? '')) ?></span>
                        <span class="myshop__row-main"><?= e($title) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
