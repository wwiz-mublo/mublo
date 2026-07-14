/**
 * MubloTracking — 전환 추적 유틸리티
 *
 * 사이트에 설정된 외부 픽셀에 전환 이벤트를 전송한다.
 * Head.php에서 각 픽셀 SDK가 로드된 상태에서만 동작.
 *
 * 사용법:
 *   MubloTracking.trackConversion('lead');
 *   MubloTracking.trackConversion('purchase', { value: 50000, currency: 'KRW' });
 */
const MubloTracking = (() => {

    /**
     * 전환 이벤트 전송
     * @param {string} type - 전환 유형: 'lead', 'purchase', 'signup', 'booking'
     * @param {object} params - 추가 파라미터 (value, currency 등)
     */
    function trackConversion(type, params) {
        params = params || {};
        _trackGA4(type, params);
        _trackMetaPixel(type, params);
        _trackKakaoPixel(type, params);
        _trackNaverAnalytics(type, params);

        // 프론트 이벤트 버스 — 외부 JS(Heatmap, AB Test, CRM 등)가 구독 가능
        window.dispatchEvent(new CustomEvent('mublo:conversion', {
            detail: { type: type, params: params }
        }));
    }

    // Google Analytics 4
    function _trackGA4(type, params) {
        if (typeof gtag !== 'function') return;

        var eventMap = {
            'lead':     'generate_lead',
            'purchase': 'purchase',
            'signup':   'sign_up',
            'booking':  'begin_checkout'
        };
        gtag('event', eventMap[type] || type, params);
    }

    // Meta (Facebook) Pixel
    function _trackMetaPixel(type, params) {
        if (typeof fbq !== 'function') return;

        var eventMap = {
            'lead':     'Lead',
            'purchase': 'Purchase',
            'signup':   'CompleteRegistration',
            'booking':  'Schedule'
        };
        fbq('track', eventMap[type] || type, params);
    }

    // 카카오 픽셀
    function _trackKakaoPixel(type, params) {
        if (typeof kakaoPixel !== 'function') return;

        var el = document.querySelector('[data-kakao-pixel]');
        if (!el) return;
        var pixelId = el.dataset.kakaoPixel;
        if (!pixelId) return;

        var methodMap = {
            'lead':     'participation',
            'purchase': 'purchase',
            'signup':   'completeRegistration',
            'booking':  'participation'
        };
        var method = methodMap[type] || 'participation';
        try { kakaoPixel(pixelId)[method](); } catch(e) { /* 무시 */ }
    }

    // 네이버 애널리틱스
    function _trackNaverAnalytics(type, params) {
        if (typeof wcs === 'undefined' || typeof wcs_do !== 'function') return;

        var typeMap = {
            'lead':     '3',
            'purchase': '1',
            'signup':   '2',
            'booking':  '3'
        };
        try {
            var _nasa = {};
            if (window.wcs) {
                _nasa['cnv'] = wcs.cnv(typeMap[type] || '3', params.value || '0');
            }
            // 전환 전송 — 이 호출이 없으면 _nasa만 만들고 실제로 전송되지 않는다.
            wcs_do(_nasa);
        } catch(e) { /* 무시 */ }
    }

    return { trackConversion: trackConversion };
})();
