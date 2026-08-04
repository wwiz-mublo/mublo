/**
 * 찜 목록 (basic 스킨) 뷰 스크립트.
 */
const ShopWishlist = {
    remove(e, goodsId) {
        e.preventDefault();
        e.stopPropagation();
        MubloRequest.showConfirm('찜을 해제하시겠습니까?', () => {
            MubloRequest.requestJson('/shop/api/wishlist/toggle', { goods_id: goodsId })
                .then(() => location.reload());
        }, { type: 'warning' });
    }
};
