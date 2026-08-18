/**
 * 주문 상세 (basic 스킨) 뷰 스크립트 #2.
 */
(function() {
    'use strict';
    var overlay = document.getElementById('spvRvViewOverlay');
    if (!overlay) return;

    // .mublo-main(z-index:1) stacking context 탈출
    document.body.appendChild(overlay);

    var currentReviewId = 0;
    var locked = false;

    function lock(on) {
        if (on === locked) return;
        locked = on;
        if (on) {
            var sbw = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (sbw > 0) document.body.style.paddingRight = sbw + 'px';
        } else {
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function stars(n) {
        n = Math.max(0, Math.min(5, parseInt(n, 10) || 0));
        return '★★★★★☆☆☆☆☆'.substr(5 - n, 5);
    }

    function open(review, goodsName) {
        currentReviewId = parseInt(review.review_id, 10) || 0;
        document.getElementById('spvRvViewProduct').textContent = goodsName || '';
        document.getElementById('spvRvViewStars').textContent = stars(review.rating);
        document.getElementById('spvRvViewDate').textContent = review.created_at || '';
        document.getElementById('spvRvViewContent').textContent = review.content || '';

        var imgWrap = document.getElementById('spvRvViewImages');
        var imgs = Array.isArray(review.images) ? review.images : [];
        imgWrap.innerHTML = imgs.map(function(u) {
            return '<a href="' + encodeURI(u) + '" target="_blank" rel="noopener">'
                + '<img src="' + encodeURI(u) + '" alt="후기 이미지"></a>';
        }).join('');

        var replyWrap = document.getElementById('spvRvViewReply');
        if (review.admin_reply) {
            replyWrap.innerHTML = '<div class="spv-rv-view__reply">'
                + '<div class="spv-rv-view__reply-label">판매자 답변</div>'
                + '<div class="spv-rv-view__reply-text">' + esc(review.admin_reply) + '</div></div>';
        } else {
            replyWrap.innerHTML = '';
        }

        overlay.classList.add('is-open');
        lock(true);
    }

    function close() {
        overlay.classList.remove('is-open');
        lock(false);
    }

    document.querySelectorAll('.shop-order-view__review-btn--view').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var data = {};
            try { data = JSON.parse(this.getAttribute('data-review') || '{}'); } catch (e) {}
            open(data, this.getAttribute('data-goods-name'));
        });
    });

    document.getElementById('spvRvViewClose').addEventListener('click', close);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) close();
    });

    document.getElementById('spvRvViewDelete').addEventListener('click', function() {
        if (!currentReviewId) return;
        MubloRequest.showConfirm('이 후기를 삭제하시겠습니까?', function() {
            MubloRequest.requestJson('/shop/reviews/delete', { review_id: currentReviewId })
                .then(function(res) {
                    MubloRequest.showToast(res.message || '후기가 삭제되었습니다.', 'success');
                    close();
                    setTimeout(function() { location.reload(); }, 600);
                }).catch(function() {});
        }, { type: 'warning' });
    });
})();

// 구매확정 (배송중/배송완료 → 구매확정) — 확정 후 항목별 구매후기 버튼이 노출된다.
// 항상 로드되는 스크립트에 두어야 함(취소 전용 order-view-3.js는 배송중 주문에서 미로드).
(function () {
    var orderNo = (function () {
        try { return JSON.parse(document.getElementById('order-view-data').textContent || '{}').orderNo || ''; }
        catch (e) { return ''; }
    })();

    // 구매확정은 상품 단위다. 부분 배송에서 먼저 도착한 상품만 확정할 수 있어야 하고,
    // 주문 전체는 그 상품들이 다 확정되면 따라 올라간다.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-detail]');
        if (!btn || !orderNo) return;

        var name = btn.dataset.goodsName || '이 상품';
        MubloRequest.showConfirm(name + '을(를) 구매확정하시겠습니까?\n확정하면 구매후기를 작성할 수 있습니다.', function () {
            btn.disabled = true;
            MubloRequest.requestJson('/shop/order/' + encodeURIComponent(orderNo) + '/items/' + btn.dataset.confirmDetail + '/confirm', {})
                .then(function (res) {
                    MubloRequest.showToast(res.message || '구매확정되었습니다.', 'success');
                    setTimeout(function () { location.reload(); }, 700);
                })
                .catch(function () { btn.disabled = false; });
        });
    });
})();
