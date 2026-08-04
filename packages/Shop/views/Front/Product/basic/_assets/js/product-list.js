/**
 * 상품 목록 (basic 스킨) 뷰 스크립트 — 찜 토글.
 */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.spl-card__wish');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    var goodsId = parseInt(btn.dataset.goodsId, 10);
    if (!goodsId) return;

    btn.disabled = true;
    MubloRequest.requestJson('/shop/api/wishlist/toggle', { goods_id: goodsId })
        .then(function(res) {
            var wished = !!(res.data && res.data.wishlisted);
            btn.classList.toggle('spl-card__wish--active', wished);
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-heart-fill', wished);
                icon.classList.toggle('bi-heart', !wished);
            }
        })
        .finally(function() { btn.disabled = false; });
});
