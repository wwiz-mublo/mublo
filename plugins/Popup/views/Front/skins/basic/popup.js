/**
 * Popup 스킨 basic — 프론트 동작
 *
 * 메인 페이지(/)에서만 활성 팝업을 API로 불러와 렌더링한다.
 * 의존: MubloRequest (전역)
 */
(function () {
    'use strict';

    // === Cookie ===
    function setCookie(name, value, hours) {
        var expires = '';
        if (hours) {
            var date = new Date();
            date.setTime(date.getTime() + (hours * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var nameEQ = name + '=';
        var cookies = document.cookie.split(';');
        for (var i = 0; i < cookies.length; i++) {
            var c = cookies[i].trim();
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length));
            }
        }
        return null;
    }

    // === Device ===
    function getCurrentDevice() {
        return window.innerWidth <= 768 ? 'mo' : 'pc';
    }

    function shouldShowOnDevice(displayDevice) {
        if (displayDevice === 'all') return true;
        return displayDevice === getCurrentDevice();
    }

    // === Render ===
    function escapeAttr(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function renderPopup(popup) {
        var cookieName = 'mublo_popup_hide_' + popup.popup_id;
        if (getCookie(cookieName)) return;
        if (!shouldShowOnDevice(popup.display_device)) return;

        var heightStyle = popup.height > 0 ? 'height:' + popup.height + 'px;' : '';

        var hideLabel = popup.hide_duration >= 24
            ? Math.floor(popup.hide_duration / 24) + '일 동안 보지 않기'
            : popup.hide_duration + '시간 동안 보지 않기';

        // 팝업 DOM 생성
        var overlay = document.createElement('div');
        overlay.className = 'mublo-popup-overlay mublo-popup--' + escapeAttr(popup.position);
        overlay.setAttribute('data-popup-id', popup.popup_id);
        overlay.setAttribute('data-display-device', popup.display_device || 'all');

        overlay.innerHTML =
            '<div class="mublo-popup-container"' +
            ' style="width:' + popup.width + 'px;' + heightStyle + '">' +
                '<button class="mublo-popup__close" type="button" aria-label="닫기">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                        '<line x1="18" y1="6" x2="6" y2="18"></line>' +
                        '<line x1="6" y1="6" x2="18" y2="18"></line>' +
                    '</svg>' +
                '</button>' +
                '<div class="mublo-popup__content">' + (popup.html_content || '') + '</div>' +
                '<div class="mublo-popup__footer">' +
                    '<button class="mublo-popup__hide-btn" type="button" data-duration="' + popup.hide_duration + '">' +
                        hideLabel +
                    '</button>' +
                    '<button class="mublo-popup__close-btn" type="button">닫기</button>' +
                '</div>' +
            '</div>';

        // 이벤트 바인딩
        overlay.querySelector('.mublo-popup__close').addEventListener('click', function () {
            overlay.remove();
        });
        overlay.querySelector('.mublo-popup__close-btn').addEventListener('click', function () {
            overlay.remove();
        });
        overlay.querySelector('.mublo-popup__hide-btn').addEventListener('click', function () {
            var duration = parseInt(this.getAttribute('data-duration'), 10) || 24;
            setCookie(cookieName, '1', duration);
            overlay.remove();
        });

        document.body.appendChild(overlay);
    }

    // === Load (메인 페이지 전용) ===
    function loadPopups() {
        if (window.location.pathname !== '/') return;

        MubloRequest.requestJson('/popup/api/active', {}, { method: 'GET' })
            .then(function (response) {
                var popups = response.data && response.data.popups;
                if (popups) {
                    popups.forEach(renderPopup);
                }
            })
            .catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadPopups);
    } else {
        loadPopups();
    }

    // 리사이즈 시 디바이스별 표시/숨김
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            document.querySelectorAll('.mublo-popup-overlay').forEach(function (el) {
                var device = el.getAttribute('data-display-device');
                if (device && device !== 'all') {
                    el.style.display = shouldShowOnDevice(device) ? '' : 'none';
                }
            });
        }, 250);
    });
})();
