<?php
/**
 * 문의 작성 폼 (독립 페이지)
 *
 * @var int $goodsId
 */
$goodsId = $goodsId ?? 0;
$this->assets->addCss('/serve/package/Shop/views/Front/Inquiry/basic/_assets/css/inquiry-form.css');
?>

<div class="shop-inquiry-form">
    <h2 class="shop-inquiry-form__title">상품 문의</h2>

    <form class="Mublo-submit-form" data-target="/shop/inquiries/store" data-success-msg="문의가 등록되었습니다." data-redirect="/shop/inquiries/my">
        <input type="hidden" name="formData[goods_id]" value="<?= (int)$goodsId ?>">

        <div class="mb-3">
            <label class="form-label">문의 유형</label>
            <select name="formData[inquiry_type]" class="form-select">
                <option value="PRODUCT">상품문의</option>
                <option value="STOCK">재고문의</option>
                <option value="DELIVERY">배송문의</option>
                <option value="OTHER">기타</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">제목 <span class="text-danger">*</span></label>
            <input type="text" name="formData[title]" class="form-control" required maxlength="100">
        </div>

        <div class="mb-3">
            <label class="form-label">내용 <span class="text-danger">*</span></label>
            <textarea name="formData[content]" class="form-control" rows="5" required></textarea>
        </div>

        <div class="mb-4 form-check">
            <input type="checkbox" class="form-check-input" name="formData[is_secret]" value="1" id="isSecretPage">
            <label class="form-check-label" for="isSecretPage">비밀글로 등록</label>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1">문의 등록</button>
            <a href="javascript:history.back()" class="btn btn-default">취소</a>
        </div>
    </form>
</div>
