<?php
/**
 * 내 문의 목록 (프론트)
 *
 * @var array $items
 * @var array $pagination
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$totalItems = $pagination['totalItems'] ?? 0;
$typeLabels = ['PRODUCT' => '상품문의', 'STOCK' => '재고문의', 'DELIVERY' => '배송문의', 'OTHER' => '기타'];
$this->assets->addCss('/serve/package/Shop/views/Front/Inquiry/basic/_assets/css/inquiry-my-inquiries.css');
?>

<div class="my-inquiry-list">
    <h2 class="my-inquiry-list__title">내 문의</h2>
    <p class="my-inquiry-list__count">총 <?= number_format($totalItems) ?>건</p>

    <?php if (empty($items)): ?>
    <div class="my-inquiry-list__empty">
        <i class="bi bi-question-circle" style="font-size:2.5rem;color:#ddd;display:block;margin-bottom:12px"></i>
        <p>작성한 문의가 없습니다.</p>
    </div>
    <?php else: ?>
    <?php foreach ($items as $item):
        $inquiryId = (int) ($item['inquiry_id'] ?? 0);
        $status = $item['inquiry_status'] ?? 'WAITING';
        $typeLabel = $typeLabels[$item['inquiry_type'] ?? 'PRODUCT'] ?? '문의';
        $goodsId = (int) ($item['goods_id'] ?? 0);
        $goodsName = $item['goods_name'] ?? '상품';
    ?>
    <div class="my-inquiry-item" id="inquiry-<?= $inquiryId ?>">
        <div class="my-inquiry-item__header" onclick="MyInquiries.toggle(<?= $inquiryId ?>)">
            <span class="my-inquiry-item__type"><?= e($typeLabel) ?></span>
            <span class="my-inquiry-item__title"><?= e($item['title'] ?? '') ?></span>
            <?php if ($status === 'REPLIED'): ?>
            <span class="my-inquiry-item__badge-replied">답변완료</span>
            <?php else: ?>
            <span class="my-inquiry-item__badge-waiting">답변대기</span>
            <?php endif; ?>
            <span class="my-inquiry-item__date"><?= e(substr($item['created_at'] ?? '', 0, 10)) ?></span>
        </div>
        <div class="my-inquiry-item__body" id="inquiry-body-<?= $inquiryId ?>">
            <div class="my-inquiry-item__product">
                <a href="/shop/products/<?= $goodsId ?>"><?= e($goodsName) ?></a>
            </div>
            <div class="my-inquiry-item__content"><?= e($item['content'] ?? '') ?></div>
            <?php if (!empty($item['reply'])): ?>
            <div class="my-inquiry-item__reply">
                <div class="my-inquiry-item__reply-label">판매자 답변</div>
                <div class="my-inquiry-item__reply-text"><?= e($item['reply']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($status === 'WAITING'): ?>
            <div class="my-inquiry-item__actions">
                <button class="my-inquiry-item__delete" onclick="MyInquiries.delete(<?= $inquiryId ?>)">삭제</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?= $this->pagination($pagination) ?>
    <?php endif; ?>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Inquiry/basic/_assets/js/inquiry-my-inquiries.js') ?>"></script>
