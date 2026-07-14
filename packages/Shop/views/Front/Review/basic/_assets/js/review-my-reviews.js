/**
 * 내 후기 목록 (basic 스킨) 뷰 스크립트.
 */
const MyReviews = {
    delete(reviewId) {
        MubloRequest.showConfirm('후기를 삭제하시겠습니까?', () => {
            MubloRequest.requestJson('/shop/reviews/delete', { review_id: reviewId })
                .then(() => {
                    document.getElementById(`review-${reviewId}`)?.remove();
                    MubloRequest.showToast('후기가 삭제되었습니다.', 'success');
                });
        }, { type: 'warning' });
    }
};
