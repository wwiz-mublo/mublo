<?php
/**
 * 상품별 문의 목록 (프론트)
 *
 * @var array $items
 * @var array $pagination
 * @var int $goodsId
 * @var int $currentMemberId
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$goodsId = $goodsId ?? 0;
$currentMemberId = $currentMemberId ?? 0;
$totalItems = $pagination['totalItems'] ?? 0;
$typeLabels = [
    'PRODUCT' => '상품문의',
    'STOCK' => '재고문의',
    'DELIVERY' => '배송문의',
    'OTHER' => '기타',
];
$this->assets->addCss('/serve/package/Shop/views/Front/Inquiry/basic/_assets/css/inquiry-list.css');
?>

<div class="shop-inquiry-list">
    <div class="shop-inquiry-list__head">
        <h2 class="shop-inquiry-list__title">상품문의 <span class="shop-inquiry-list__count">(<?= number_format($totalItems) ?>)</span></h2>
    </div>

    <?php if (empty($items)): ?>
    <div class="shop-inquiry-list__empty">
        <i class="bi bi-chat-dots"></i>
        <p>아직 작성된 문의가 없습니다.</p>
    </div>
    <?php else: ?>
    <div class="shop-inquiry-list__items">
    <?php foreach ($items as $item):
        $inquiryId = (int) ($item['inquiry_id'] ?? 0);
        $isSecret = (bool) ($item['is_secret'] ?? false);
        $memberIdOfItem = (int) ($item['member_id'] ?? 0);
        $canView = !$isSecret || $memberIdOfItem === $currentMemberId;
        $status = $item['inquiry_status'] ?? 'WAITING';
        $typeLabel = $typeLabels[$item['inquiry_type'] ?? 'PRODUCT'] ?? '문의';

        $itemGoodsId = (int) ($item['goods_id'] ?? 0);
        $goodsName = (string) ($item['goods_name'] ?? '');
        $goodsSlug = (string) ($item['goods_slug'] ?? '');
        $thumb = (string) ($item['product_thumbnail'] ?? '');
        $displayPrice = (int) ($item['display_price'] ?? 0);
        $productUrl = $itemGoodsId > 0
            ? '/shop/products/' . $itemGoodsId . ($goodsSlug !== '' ? '/' . rawurlencode($goodsSlug) : '')
            : '';
    ?>
    <div class="shop-inquiry-item">
        <?php if ($productUrl !== '' && $goodsName !== ''): ?>
        <a href="<?= e($productUrl) ?>" class="shop-inquiry-item__product">
            <?php if ($thumb !== ''): ?>
            <img src="<?= e($thumb) ?>" alt="" class="shop-inquiry-item__product-thumb">
            <?php endif; ?>
            <span class="shop-inquiry-item__product-info">
                <span class="shop-inquiry-item__product-name"><?= e($goodsName) ?></span>
                <?php if ($displayPrice > 0): ?>
                <span class="shop-inquiry-item__product-price"><?= number_format($displayPrice) ?>원</span>
                <?php endif; ?>
            </span>
            <i class="bi bi-chevron-right shop-inquiry-item__product-arrow"></i>
        </a>
        <?php endif; ?>

        <div class="shop-inquiry-item__header" onclick="InquiryList.toggle(<?= $inquiryId ?>)">
            <span class="shop-inquiry-item__type"><?= e($typeLabel) ?></span>
            <?php if ($status === 'REPLIED'): ?>
            <span class="shop-inquiry-item__status-replied">답변완료</span>
            <?php else: ?>
            <span class="shop-inquiry-item__status-waiting">답변대기</span>
            <?php endif; ?>
            <?php if ($isSecret): ?>
            <i class="bi bi-lock-fill shop-inquiry-item__secret" title="비밀글"></i>
            <?php endif; ?>
            <span class="shop-inquiry-item__title">
                <?php if ($canView): ?>
                <?= e($item['title'] ?? '') ?>
                <?php else: ?>
                비밀글입니다.
                <?php endif; ?>
            </span>
            <span class="shop-inquiry-item__date"><?= e(substr($item['created_at'] ?? '', 0, 10)) ?></span>
            <?php if ($canView): ?>
            <i class="bi bi-chevron-down shop-inquiry-item__toggle"></i>
            <?php endif; ?>
        </div>
        <?php if ($canView): ?>
        <div class="shop-inquiry-item__body" id="inquiry-body-<?= $inquiryId ?>">
            <div class="shop-inquiry-item__content"><?= e($item['content'] ?? '') ?></div>
            <?php if (!empty($item['reply'])): ?>
            <div class="shop-inquiry-item__reply">
                <div class="shop-inquiry-item__reply-label">판매자 답변</div>
                <div class="shop-inquiry-item__reply-text"><?= e($item['reply']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <?= $this->pagination($pagination) ?>
    <?php endif; ?>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Inquiry/basic/_assets/js/inquiry-list.js') ?>"></script>
