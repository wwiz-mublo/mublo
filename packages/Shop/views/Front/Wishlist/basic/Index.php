<?php
/**
 * 찜한상품
 *
 * @var array $items
 * @var array $pagination
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$totalItems = $pagination['totalItems'] ?? 0;
$perPage = $pagination['perPage'] ?? 20;

$this->assets->addCss('/serve/package/Shop/views/Front/Wishlist/basic/_assets/css/wishlist-index.css');
?>

<div class="shop-wishlist">
    <h2 class="shop-wishlist__title">찜한상품</h2>
    <p class="shop-wishlist__count">총 <?= number_format($totalItems) ?>개의 상품</p>

    <?php if (empty($items)): ?>
    <div class="shop-wishlist__empty">
        <p>찜한 상품이 없습니다.</p>
        <a href="/shop/products" class="shop-wishlist__empty-btn">쇼핑 계속하기</a>
    </div>
    <?php else: ?>
    <div class="shop-wishlist__grid">
        <?php foreach ($items as $item):
            $goodsId = (int) ($item['goods_id'] ?? 0);
            $goodsName = $item['goods_name'] ?? '';
            $displayPrice = (int) ($item['display_price'] ?? 0);
            $mainImage = $item['main_image'] ?? null;
            $isActive = (bool) ($item['is_active'] ?? true);
            $stockQty = $item['stock_quantity'];
            $isSoldout = ($stockQty !== null && (int) $stockQty <= 0);
        ?>
        <div class="shop-wishlist__item" data-goods-id="<?= $goodsId ?>">
            <a href="/shop/products/<?= $goodsId ?>" class="shop-wishlist__thumb">
                <?php if ($mainImage): ?>
                <img src="<?= e($mainImage) ?>" alt="<?= e($goodsName) ?>" loading="lazy">
                <?php else: ?>
                <div class="shop-wishlist__thumb--empty"><i class="bi bi-image"></i></div>
                <?php endif; ?>
                <button class="shop-wishlist__remove" onclick="ShopWishlist.remove(event, <?= $goodsId ?>)" title="찜 해제">
                    <i class="bi bi-heart-fill"></i>
                </button>
            </a>
            <div class="shop-wishlist__info">
                <div class="shop-wishlist__name"><?= e($goodsName) ?></div>
                <div class="shop-wishlist__price"><?= number_format($displayPrice) ?>원</div>
                <?php if ($isSoldout): ?>
                <div class="shop-wishlist__soldout">품절</div>
                <?php endif; ?>
            </div>
            <?php if ($isActive && !$isSoldout): ?>
            <a href="/shop/products/<?= $goodsId ?>" class="shop-wishlist__cart">상품 보기</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?= $this->pagination($pagination) ?>
    <?php endif; ?>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Wishlist/basic/_assets/js/wishlist-index.js') ?>"></script>
