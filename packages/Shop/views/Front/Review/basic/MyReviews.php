<?php
/**
 * 내 구매후기 목록 (프론트)
 *
 * @var array $items
 * @var array $pagination
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$totalItems = $pagination['totalItems'] ?? 0;

$this->assets->addCss('/serve/package/Shop/views/Front/Review/basic/_assets/css/review-my-reviews.css');
?>

<div class="my-review-list">
    <h2 class="my-review-list__title">내 후기</h2>
    <p class="my-review-list__count">총 <?= number_format($totalItems) ?>건</p>

    <?php if (empty($items)): ?>
    <div class="my-review-list__empty">
        <i class="bi bi-chat-square-text" style="font-size:2.5rem;color:#ddd;display:block;margin-bottom:12px"></i>
        <p>작성한 후기가 없습니다.</p>
    </div>
    <?php else: ?>
    <?php foreach ($items as $item):
        $rating = (int) ($item['rating'] ?? 5);
        $goodsName = $item['goods_name'] ?? '상품';
        $goodsId = (int) ($item['goods_id'] ?? 0);
        $reviewId = (int) ($item['review_id'] ?? 0);
        $images = array_filter([$item['image1'] ?? null, $item['image2'] ?? null, $item['image3'] ?? null]);
    ?>
    <div class="my-review-item" id="review-<?= $reviewId ?>">
        <div class="my-review-item__product">
            <a href="/shop/products/<?= $goodsId ?>"><?= e($goodsName) ?></a>
        </div>
        <div class="my-review-item__rating">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <i class="bi bi-star<?= $i <= $rating ? '-fill' : '' ?>"></i>
            <?php endfor; ?>
        </div>
        <div class="my-review-item__content"><?= e($item['content'] ?? '') ?></div>
        <?php if (!empty($images)): ?>
        <div class="my-review-item__images">
            <?php foreach ($images as $img): ?>
            <img src="<?= e($img) ?>" alt="후기 이미지" class="my-review-item__img">
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="my-review-item__footer">
            <span class="my-review-item__date"><?= e(substr($item['created_at'] ?? '', 0, 10)) ?></span>
            <button class="my-review-item__delete" onclick="MyReviews.delete(<?= $reviewId ?>)">삭제</button>
        </div>
    </div>
    <?php endforeach; ?>

    <?= $this->pagination($pagination) ?>
    <?php endif; ?>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Review/basic/_assets/js/review-my-reviews.js') ?>"></script>
