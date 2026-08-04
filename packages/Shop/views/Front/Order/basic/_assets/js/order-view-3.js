/**
 * 주문 상세 (basic 스킨) 뷰 스크립트 #3 — 주문 취소($canCancel 시에만 로드).
 * orderNo는 뷰의 <script type="application/json" id="order-view-data"> 아일랜드에서 로드.
 */
var __orderNo = (function () {
    try { return JSON.parse(document.getElementById('order-view-data').textContent || '{}').orderNo || ''; }
    catch (e) { return ''; }
})();
(function() {
    'use strict';
    var openBtn = document.getElementById('spvCancelOpen');
    var overlay = document.getElementById('spvCancelOverlay');
    if (!openBtn || !overlay) return;

    // .mublo-main(z-index:1) stacking context 탈출 — 전역 최상위로
    document.body.appendChild(overlay);

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
    function open() { overlay.classList.add('is-open'); lock(true); }
    function close() { overlay.classList.remove('is-open'); lock(false); }

    openBtn.addEventListener('click', open);
    document.getElementById('spvCancelClose').addEventListener('click', close);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) close();
    });

    var submitBtn = document.getElementById('spvCancelSubmit');
    submitBtn.addEventListener('click', function() {
        var sel = document.getElementById('spvCancelReason');
        var detail = document.getElementById('spvCancelDetail').value.trim();
        var reason = sel ? sel.value : '';
        if (detail) reason = reason ? (reason + ' - ' + detail) : detail;

        MubloRequest.showConfirm('정말 이 주문을 취소하시겠습니까?', function() {
            submitBtn.disabled = true;
            MubloRequest.requestJson('/shop/order/' + encodeURIComponent(__orderNo) + '/cancel', { reason: reason })
                .then(function(res) {
                    MubloRequest.showToast(res.message || '주문이 취소되었습니다.', 'success');
                    close();
                    setTimeout(function() { location.reload(); }, 700);
                })
                .catch(function() { submitBtn.disabled = false; });
        }, { type: 'warning' });
    });
})();
