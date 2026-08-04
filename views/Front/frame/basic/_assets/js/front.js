/**
 * Front 프레임스킨(basic) 동작 — 스킨 소유
 *
 * - 모바일 패널(aside.mublo-panel) 토글: 래퍼의 .is-open 하나로
 *   드로어(.mublo-panel__dialog) 슬라이드 + 백드롭(.mublo-panel__backdrop) 표시를 함께 제어.
 */
(function () {
    'use strict';

    var toggle = document.getElementById('mubloPanelToggle');
    var panel = document.getElementById('mubloPanel');
    if (!toggle || !panel) return;

    var closeBtn = document.getElementById('mubloPanelClose');
    var backdrop = panel.querySelector('.mublo-panel__backdrop');

    function open() {
        panel.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }
    function shut() {
        panel.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    toggle.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', shut);
    if (backdrop) backdrop.addEventListener('click', shut);
})();
