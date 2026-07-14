/**
 * 상품문의 목록 (basic 스킨) 뷰 스크립트.
 */
const InquiryList = {
    toggle(id) {
        const body = document.getElementById(`inquiry-body-${id}`);
        if (body) {
            body.classList.toggle('is-open');
            body.closest('.shop-inquiry-item')?.classList.toggle('is-expanded', body.classList.contains('is-open'));
        }
    }
};
