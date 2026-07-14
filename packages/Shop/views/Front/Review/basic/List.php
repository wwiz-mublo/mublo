<?php
/**
 * 상품별 구매후기 목록 (프론트)
 *
 * @var array $items
 * @var array $pagination
 * @var int $goodsId
 * @var float $avgRating
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$goodsId = $goodsId ?? 0;
$avgRating = $avgRating ?? 0.0;
$totalItems = $pagination['totalItems'] ?? 0;
// 작성자 표시명 (닉네임 원본 > 아이디, 마스킹 안 함). 비회원(닉네임 없음)은 빈값 → 미표시.
$authorLabel = static function (array $item): string {
    $name = trim((string) ($item['nickname'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($item['user_id'] ?? ''));
    }
    return $name;
};

$this->assets->addCss('/serve/package/Shop/views/Front/Review/basic/_assets/css/review-list.css');
?>

<div class="shop-review-list">
    <h2 class="shop-review-list__title">구매후기</h2>

    <div class="shop-review-list__summary">
        <div class="shop-review-list__score">
            <div class="shop-review-list__avg"><?= number_format($avgRating, 1) ?></div>
            <div class="shop-review-list__stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="bi bi-star-fill <?= $i <= round($avgRating) ? '' : 'shop-review-list__star-empty' ?>"></i>
                <?php endfor; ?>
            </div>
        </div>
        <div class="shop-review-list__count">총 <?= number_format($totalItems) ?>개의 후기</div>
    </div>

    <?php if (empty($items)): ?>
    <div class="shop-review-list__empty">
        <i class="bi bi-chat-square-text"></i>
        <p>아직 작성된 후기가 없습니다.</p>
    </div>
    <?php else: ?>
    <div class="shop-review-list__items">
    <?php foreach ($items as $item):
        $rating = (int) ($item['rating'] ?? 5);
        $createdAt = $item['created_at'] ?? '';
        $content = $item['content'] ?? '';
        $adminReply = $item['admin_reply'] ?? '';
        $reviewType = $item['review_type'] ?? 'TEXT';
        $authorName = $authorLabel($item);

        $itemGoodsId = (int) ($item['goods_id'] ?? 0);
        $goodsName = (string) ($item['goods_name'] ?? '');
        $goodsSlug = (string) ($item['goods_slug'] ?? '');
        $thumb = (string) ($item['product_thumbnail'] ?? '');
        $productUrl = $itemGoodsId > 0
            ? '/shop/products/' . $itemGoodsId . ($goodsSlug !== '' ? '/' . rawurlencode($goodsSlug) : '')
            : '';
    ?>
    <article class="shop-review-item">
        <div class="shop-review-item__header">
            <span class="shop-review-item__rating">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="bi bi-star<?= $i <= $rating ? '-fill' : '' ?>"></i>
                <?php endfor; ?>
            </span>
            <?php if ($reviewType === 'PHOTO'): ?>
            <span class="shop-review-item__badge">포토</span>
            <?php endif; ?>
            <?php if ($authorName !== ''): ?>
            <span class="shop-review-item__author"><?= e($authorName) ?></span>
            <?php endif; ?>
            <span class="shop-review-item__date"><?= e(substr($createdAt, 0, 10)) ?></span>
        </div>
        <div class="shop-review-item__content"><?= e($content) ?></div>

        <?php
        $images = array_filter([$item['image1'] ?? null, $item['image2'] ?? null, $item['image3'] ?? null]);
        if (!empty($images)):
        ?>
        <div class="shop-review-item__images">
            <?php foreach ($images as $img): ?>
            <a href="<?= e($img) ?>" target="_blank" rel="noopener noreferrer">
                <img src="<?= e($img) ?>" alt="후기 이미지" class="shop-review-item__img">
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($adminReply): ?>
        <div class="shop-review-item__reply">
            <div class="shop-review-item__reply-label">판매자 답변</div>
            <div class="shop-review-item__reply-text"><?= e($adminReply) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($productUrl !== '' && $goodsName !== ''): ?>
        <a href="<?= e($productUrl) ?>" class="shop-review-item__product">
            <?php if ($thumb !== ''): ?>
            <img src="<?= e($thumb) ?>" alt="" class="shop-review-item__product-thumb">
            <?php endif; ?>
            <span class="shop-review-item__product-info">
                <span class="shop-review-item__product-name"><?= e($goodsName) ?></span>
                <?php $displayPrice = (int) ($item['display_price'] ?? 0); ?>
                <?php if ($displayPrice > 0): ?>
                <span class="shop-review-item__product-price"><?= number_format($displayPrice) ?>원</span>
                <?php endif; ?>
            </span>
            <i class="bi bi-chevron-right shop-review-item__product-arrow"></i>
        </a>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    </div>

    <?= $this->pagination($pagination) ?>
    <?php endif; ?>
</div>
