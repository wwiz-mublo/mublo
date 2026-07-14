/**
 * 테스트 결제 확인 모달 (TestPay 플러그인 소유)
 *
 * 아래 CSS/MARKUP 의 플레이스홀더는 TestPayGateway::getCheckoutScript()가
 * checkout-modal.css / checkout-modal.html 내용을 JSON 문자열로 치환해 채운다.
 *
 * 흐름:
 *  - 확인 → status_url(verify)로 검증 → redirect 이동 (버튼은 그대로 유지)
 *  - 취소/백드롭/ESC → 모달을 닫고 'mublo:pay' cancel 신호 발행
 *
 * 계약대로 사실만 알리고 화면은 건드리지 않는다 — 표시·버튼 복원은 소비처가 정한다.
 */
(function() {
    var CSS = __TPAY_CSS__;
    var MARKUP = __TPAY_HTML__;

    var modal = null, locked = false, savedOverflow = '', savedPad = '', escHandler = null;

    // 계약: document 'mublo:pay' 로 사실만 알린다.
    function signal(status, message) {
        document.dispatchEvent(new CustomEvent('mublo:pay', {
            detail: { gateway: 'testpay', status: status, message: message || '', code: '' }
        }));
    }

    function lockScroll() {
        if (locked) return;
        locked = true;
        var sbw = window.innerWidth - document.documentElement.clientWidth;
        savedOverflow = document.body.style.overflow;
        savedPad = document.body.style.paddingRight;
        document.body.style.overflow = 'hidden';
        if (sbw > 0) document.body.style.paddingRight = sbw + 'px';
    }
    function unlockScroll() {
        if (!locked) return;
        locked = false;
        document.body.style.overflow = savedOverflow;
        document.body.style.paddingRight = savedPad;
    }
    function ensureModal() {
        if (modal) return modal;
        if (!document.getElementById('tpay-modal-styles')) {
            var style = document.createElement('style');
            style.id = 'tpay-modal-styles';
            style.textContent = CSS;
            document.head.appendChild(style);
        }
        modal = document.createElement('div');
        modal.className = 'tpay-modal__overlay';
        modal.innerHTML = MARKUP;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) abandon(); });
        return modal;
    }
    function closeModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        unlockScroll();
        if (escHandler) { document.removeEventListener('keydown', escHandler); escHandler = null; }
    }
    // 버튼 외(취소/백드롭/ESC)로 닫으면 결제 중단 — 사용자가 닫았다는 사실만 알린다
    function abandon() {
        closeModal();
        signal('cancel');
    }

    window.MubloPayHandlers = window.MubloPayHandlers || {};
    window.MubloPayHandlers['testpay'] = function(data) {
        ensureModal();
        modal.querySelector('[data-tpay="name"]').textContent = data.order_name || data.order_no || '';
        modal.querySelector('[data-tpay="orderno"]').textContent = data.order_no || '';
        modal.querySelector('[data-tpay="amount"]').textContent = (data.amount || 0).toLocaleString() + '원';

        modal.querySelector('[data-tpay="cancel"]').onclick = abandon;
        modal.querySelector('[data-tpay="confirm"]').onclick = function() {
            // 진행: 버튼 복원 없이 닫고 검증. 버튼은 verify 완료까지 '주문 처리 중...' 유지.
            closeModal();
            var statusUrl = data.status_url || '';
            if (!statusUrl) {
                if (data.success_url) location.href = data.success_url;
                else signal('cancel');
                return;
            }
            MubloRequest.requestJson(statusUrl, data.status_payload || {})
            .then(function(json) {
                var redirect = (json && json.data && json.data.redirect) || data.success_url || '';
                if (redirect) { location.href = redirect; }
                else { signal('fail', '결제 결과를 확인하지 못했습니다.'); }
            })
            .catch(function() {
                signal('fail', '결제 검증에 실패했습니다.');
            });
        };

        modal.classList.add('is-open');
        lockScroll();
        escHandler = function(e) { if (e.key === 'Escape') abandon(); };
        document.addEventListener('keydown', escHandler);
    };
})();
