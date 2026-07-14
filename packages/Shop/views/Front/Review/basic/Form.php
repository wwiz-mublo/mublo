<?php
/**
 * 구매후기 작성 폼 (프론트)
 *
 * @var int $orderDetailId
 */
$orderDetailId = $orderDetailId ?? 0;
$this->assets->addCss('/serve/package/Shop/views/Front/Review/basic/_assets/css/review-form.css');
?>

<div class="shop-review-form">
    <h2 class="shop-review-form__title">구매후기 작성</h2>

    <form class="Mublo-submit-form" data-target="/shop/reviews/store" data-success-msg="후기가 등록되었습니다.">
        <input type="hidden" name="formData[order_detail_id]" value="<?= (int)$orderDetailId ?>">

        <div class="mb-4">
            <label class="form-label fw-semibold">별점</label>
            <div class="shop-review-form__stars" id="starContainer">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="bi bi-star-fill shop-review-form__star" data-value="<?= $i ?>"
                   onclick="ReviewForm.setRating(<?= $i ?>)"></i>
                <?php endfor; ?>
            </div>
            <input type="hidden" name="formData[rating]" id="ratingInput" value="5">
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">후기 내용 <span class="text-danger">*</span></label>
            <textarea name="formData[content]" class="form-control" rows="5" placeholder="상품에 대한 솔직한 후기를 남겨주세요." required minlength="10"></textarea>
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">사진 첨부 (최대 3장)</label>
            <div class="shop-review-form__images">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="shop-review-form__img-slot" id="imgSlot<?= $i ?>" onclick="document.getElementById('imgFile<?= $i ?>').click()">
                    <i class="bi bi-plus-lg" style="font-size:1.5rem;color:#9ca3af"></i>
                    <input type="file" id="imgFile<?= $i ?>" name="fileData[image<?= $i ?>]" accept="image/*" style="display:none" onchange="ReviewForm.previewImage(<?= $i ?>, this)">
                </div>
                <?php endfor; ?>
            </div>
            <div class="text-muted mt-1" style="font-size:0.8rem">JPG, PNG, WEBP 파일 (각 5MB 이하)</div>
        </div>

        <button type="submit" class="btn btn-primary w-100">후기 등록하기</button>
    </form>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Review/basic/_assets/js/review-form.js') ?>"></script>
