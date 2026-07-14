/**
 * 내 문의 (basic 스킨) 뷰 스크립트.
 */
const MyInquiries = {
    toggle(id) {
        document.getElementById(`inquiry-body-${id}`)?.classList.toggle('is-open');
    },
    delete(inquiryId) {
        MubloRequest.showConfirm('문의를 삭제하시겠습니까?', () => {
            MubloRequest.requestJson('/shop/inquiries/delete', { inquiry_id: inquiryId })
                .then(() => {
                    document.getElementById(`inquiry-${inquiryId}`)?.remove();
                    MubloRequest.showToast('문의가 삭제되었습니다.', 'success');
                });
        }, { type: 'warning' });
    }
};
