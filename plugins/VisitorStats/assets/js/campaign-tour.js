/**
 * CampaignTour — 캠페인키 설정 인터랙티브 가이드
 *
 * 1) 방문통계 대시보드 등 다른 탭: "키 설정" 탭 강조
 * 2) 캠페인 설정 페이지: 키 생성 흐름 가이드
 *
 * 사용: _nav.php 또는 CampaignSettings.php 하단에서
 *   <script src="/serve/plugin/VisitorStats/assets/js/campaign-tour.js"></script>
 */
(function () {
    'use strict';

    var STORAGE_KEY_TAB  = 'vs_campaign_tab_hint_done';
    var STORAGE_KEY_TOUR = 'vs_campaign_tour_done';
    var TOUR_ID = 'vs-ctour';

    /* ============================================================
       스타일 주입
       ============================================================ */
    function injectStyles() {
        if (document.getElementById(TOUR_ID + '-css')) return;
        var s = document.createElement('style');
        s.id = TOUR_ID + '-css';
        s.textContent = [
            /* 오버레이 */
            '.' + TOUR_ID + '-overlay {',
            '  position: fixed; inset: 0; z-index: 10000;',
            '  background: rgba(0,0,0,0.45);',
            '  transition: opacity 0.25s;',
            '}',

            /* 스포트라이트 */
            '.' + TOUR_ID + '-spotlight {',
            '  position: absolute; z-index: 10001;',
            '  border-radius: 6px;',
            '  box-shadow: 0 0 0 9999px rgba(0,0,0,0.45);',
            '  transition: all 0.3s ease;',
            '  pointer-events: none;',
            '}',

            /* 말풍선 */
            '.' + TOUR_ID + '-popover {',
            '  position: absolute; z-index: 10002;',
            '  background: #fff; color: #1e293b;',
            '  border-radius: 12px;',
            '  box-shadow: 0 8px 32px rgba(0,0,0,0.18);',
            '  padding: 20px 22px 16px;',
            '  max-width: 340px; min-width: 260px;',
            '  font-family: "Pretendard",-apple-system,sans-serif;',
            '  font-size: 14px; line-height: 1.6;',
            '  animation: vsTourIn 0.25s ease;',
            '}',
            '.' + TOUR_ID + '-title {',
            '  font-weight: 700; font-size: 15px;',
            '  margin-bottom: 8px; color: #3b82f6;',
            '}',
            '.' + TOUR_ID + '-body {',
            '  color: #475569; margin-bottom: 14px;',
            '}',
            '.' + TOUR_ID + '-footer {',
            '  display: flex; align-items: center;',
            '  justify-content: space-between; gap: 8px;',
            '}',
            '.' + TOUR_ID + '-progress {',
            '  font-size: 11px; color: #94a3b8; font-weight: 600;',
            '}',
            '.' + TOUR_ID + '-btn {',
            '  padding: 6px 16px; border-radius: 6px;',
            '  font-size: 13px; font-weight: 600;',
            '  cursor: pointer; border: none;',
            '  transition: all 0.15s;',
            '}',
            '.' + TOUR_ID + '-btn-next {',
            '  background: #3b82f6; color: #fff;',
            '}',
            '.' + TOUR_ID + '-btn-next:hover { background: #2563eb; }',
            '.' + TOUR_ID + '-btn-skip {',
            '  background: transparent; color: #94a3b8;',
            '  padding: 6px 8px;',
            '}',
            '.' + TOUR_ID + '-btn-skip:hover { color: #64748b; }',

            /* 화살표 */
            '.' + TOUR_ID + '-arrow {',
            '  position: absolute; width: 14px; height: 14px;',
            '  background: #fff; transform: rotate(45deg);',
            '}',
            '.' + TOUR_ID + '-arrow-top {',
            '  top: -7px; left: 30px;',
            '  box-shadow: -2px -2px 4px rgba(0,0,0,0.06);',
            '}',
            '.' + TOUR_ID + '-arrow-bottom {',
            '  bottom: -7px; left: 30px;',
            '  box-shadow: 2px 2px 4px rgba(0,0,0,0.06);',
            '}',
            '.' + TOUR_ID + '-arrow-left {',
            '  left: -7px; top: 24px;',
            '  box-shadow: -2px 2px 4px rgba(0,0,0,0.06);',
            '}',

            /* 탭 강조 펄스 */
            '.' + TOUR_ID + '-tab-pulse {',
            '  position: absolute; z-index: 9999;',
            '  background: #3b82f6; color: #fff;',
            '  font-size: 12px; font-weight: 700;',
            '  padding: 8px 16px; border-radius: 10px;',
            '  box-shadow: 0 4px 16px rgba(59,130,246,0.35);',
            '  cursor: pointer;',
            '  animation: vsTourPulse 2s infinite;',
            '}',
            '.' + TOUR_ID + '-tab-pulse::after {',
            '  content: ""; position: absolute;',
            '  top: -6px; left: 24px;',
            '  border: 6px solid transparent;',
            '  border-bottom-color: #3b82f6;',
            '}',
            '.' + TOUR_ID + '-tab-pulse-close {',
            '  position: absolute; top: -6px; right: -6px;',
            '  width: 18px; height: 18px; border-radius: 50%;',
            '  background: #ef4444; color: #fff;',
            '  font-size: 10px; line-height: 18px; text-align: center;',
            '  cursor: pointer; border: none;',
            '}',

            '@keyframes vsTourIn {',
            '  from { opacity: 0; transform: translateY(8px); }',
            '  to { opacity: 1; transform: translateY(0); }',
            '}',
            '@keyframes vsTourPulse {',
            '  0%, 100% { transform: scale(1); }',
            '  50% { transform: scale(1.05); }',
            '}',
        ].join('\n');
        document.head.appendChild(s);
    }

    /* ============================================================
       1) 탭 강조 — 대시보드 등 다른 페이지에서
       ============================================================ */
    function showTabHint() {
        // "키 설정" 탭 찾기
        var tabLink = document.querySelector('.nav-tabs .nav-link[href*="campaign-settings"]');
        if (!tabLink) return;

        // 이미 키 설정 페이지에 있으면 표시 안함
        if (tabLink.classList.contains('active')) return;

        var rect = tabLink.getBoundingClientRect();
        var hint = document.createElement('div');
        hint.className = TOUR_ID + '-tab-pulse';
        hint.id = TOUR_ID + '-tab-hint';
        hint.innerHTML = '💡 캠페인키를 먼저 만들어 두세요!';

        // 닫기 버튼
        var closeBtn = document.createElement('button');
        closeBtn.className = TOUR_ID + '-tab-pulse-close';
        closeBtn.textContent = '✕';
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            hint.remove();
            localStorage.setItem(STORAGE_KEY_TAB, '1');
        });
        hint.appendChild(closeBtn);

        // 클릭하면 키 설정 페이지로 이동
        hint.addEventListener('click', function () {
            localStorage.setItem(STORAGE_KEY_TAB, '1');
            window.location.href = tabLink.href;
        });

        document.body.appendChild(hint);

        // 탭 아래에 위치
        hint.style.top = (rect.bottom + window.scrollY + 8) + 'px';
        hint.style.left = (rect.left + window.scrollX) + 'px';

        // 스크롤 시 위치 갱신
        function reposition() {
            var r = tabLink.getBoundingClientRect();
            hint.style.top = (r.bottom + window.scrollY + 8) + 'px';
            hint.style.left = (r.left + window.scrollX) + 'px';
        }
        window.addEventListener('scroll', reposition);
        window.addEventListener('resize', reposition);
    }

    /* ============================================================
       2) 캠페인 설정 페이지 투어
       ============================================================ */
    var currentStep = 0;
    var overlay = null;
    var spotlight = null;
    var popover = null;

    function getSteps() {
        return [
            {
                target: '#ck-key',
                title: '캠페인키 입력',
                body: '영문, 숫자, 하이픈(-), 밑줄(_)로 캠페인키를 입력하세요. 채널별로 구분하기 쉬운 이름이 좋습니다.<br><span style="color:#94a3b8;font-size:12px">예: naver-ad, kakao-0326, insta-spring</span>',
                position: 'bottom'
            },
            {
                target: '#ck-group',
                title: '그룹 (선택)',
                body: '같은 캠페인끼리 묶고 싶을 때 그룹 이름을 입력하세요. 통계에서 그룹별로 모아볼 수 있습니다.<br><span style="color:#94a3b8;font-size:12px">예: 블로그 홍보, 3월 이벤트</span>',
                position: 'bottom'
            },
            {
                target: '#ck-add-btn',
                title: '추가 버튼',
                body: '캠페인키를 입력한 뒤 이 버튼을 누르면 바로 등록됩니다.',
                position: 'bottom'
            },
            {
                target: function () {
                    // 테이블에 행이 있으면 첫 번째 행의 복사 버튼
                    var btn = document.querySelector('#ck-tbody .ck-copy-btn');
                    return btn;
                },
                title: 'URL 복사',
                body: '이 버튼을 누르면 캠페인키가 포함된 URL이 클립보드에 복사됩니다. 그대로 SNS나 광고에 붙여넣으세요.',
                position: 'left',
                skip: function () {
                    return !document.querySelector('#ck-tbody .ck-copy-btn');
                }
            }
        ];
    }

    function createOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = TOUR_ID + '-overlay';
        document.body.appendChild(overlay);

        spotlight = document.createElement('div');
        spotlight.className = TOUR_ID + '-spotlight';
        document.body.appendChild(spotlight);
    }

    function removeAll() {
        if (overlay) { overlay.remove(); overlay = null; }
        if (spotlight) { spotlight.remove(); spotlight = null; }
        if (popover) { popover.remove(); popover = null; }
    }

    function positionSpotlight(el) {
        if (!el || !spotlight) return;
        var rect = el.getBoundingClientRect();
        var pad = 6;
        spotlight.style.top = (rect.top + window.scrollY - pad) + 'px';
        spotlight.style.left = (rect.left + window.scrollX - pad) + 'px';
        spotlight.style.width = (rect.width + pad * 2) + 'px';
        spotlight.style.height = (rect.height + pad * 2) + 'px';
    }

    function showPopover(el, step, idx, total) {
        if (popover) popover.remove();

        var rect = el.getBoundingClientRect();
        popover = document.createElement('div');
        popover.className = TOUR_ID + '-popover';

        var arrowClass = step.position === 'bottom'
            ? TOUR_ID + '-arrow-top'
            : step.position === 'top'
                ? TOUR_ID + '-arrow-bottom'
                : TOUR_ID + '-arrow-left';

        popover.innerHTML = [
            '<div class="' + TOUR_ID + '-arrow ' + arrowClass + '"></div>',
            '<div class="' + TOUR_ID + '-title">💡 ' + step.title + '</div>',
            '<div class="' + TOUR_ID + '-body">' + step.body + '</div>',
            '<div class="' + TOUR_ID + '-footer">',
            '  <span class="' + TOUR_ID + '-progress">' + (idx + 1) + ' / ' + total + '</span>',
            '  <div>',
            '    <button class="' + TOUR_ID + '-btn ' + TOUR_ID + '-btn-skip" id="' + TOUR_ID + '-skip">그만보기</button>',
            '    <button class="' + TOUR_ID + '-btn ' + TOUR_ID + '-btn-next" id="' + TOUR_ID + '-next">',
                  (idx === total - 1 ? '완료' : '다음'),
            '    </button>',
            '  </div>',
            '</div>'
        ].join('');

        document.body.appendChild(popover);

        var popRect = popover.getBoundingClientRect();
        var top, left;

        if (step.position === 'bottom') {
            top = rect.bottom + window.scrollY + 14;
            left = rect.left + window.scrollX;
        } else if (step.position === 'top') {
            top = rect.top + window.scrollY - popRect.height - 14;
            left = rect.left + window.scrollX;
        } else {
            top = rect.top + window.scrollY - 10;
            left = rect.left + window.scrollX - popRect.width - 14;
        }

        var maxLeft = window.innerWidth - popRect.width - 20;
        if (left > maxLeft) left = maxLeft;
        if (left < 10) left = 10;

        popover.style.top = top + 'px';
        popover.style.left = left + 'px';

        document.getElementById(TOUR_ID + '-next').addEventListener('click', nextStep);
        document.getElementById(TOUR_ID + '-skip').addEventListener('click', function () { finish(true); });
    }

    function resolveTarget(step) {
        return typeof step.target === 'function' ? step.target() : document.querySelector(step.target);
    }

    function showStep(idx) {
        var steps = getSteps();
        if (idx >= steps.length) { finish(true); return; }

        var step = steps[idx];
        if (step.skip && step.skip()) {
            currentStep = idx + 1;
            showStep(currentStep);
            return;
        }

        var el = resolveTarget(step);
        if (!el) { currentStep = idx + 1; showStep(currentStep); return; }

        currentStep = idx;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(function () {
            createOverlay();
            positionSpotlight(el);
            showPopover(el, step, idx, steps.length);
        }, 350);
    }

    function nextStep() {
        currentStep++;
        showStep(currentStep);
    }

    function finish(save) {
        removeAll();
        if (save) localStorage.setItem(STORAGE_KEY_TOUR, '1');
    }

    function startTour() {
        currentStep = 0;
        showStep(0);
    }

    /* ============================================================
       초기화
       ============================================================ */
    function init() {
        injectStyles();

        // 현재 페이지 판별
        var isCampaignSettingsPage = !!document.querySelector('.nav-tabs .nav-link.active[href*="campaign-settings"]');

        if (isCampaignSettingsPage) {
            // 캠페인 설정 페이지: 투어 가이드
            if (!localStorage.getItem(STORAGE_KEY_TOUR)) {
                setTimeout(startTour, 800);
            }
        } else {
            // 다른 탭: "키 설정" 탭 강조
            if (!localStorage.getItem(STORAGE_KEY_TAB)) {
                setTimeout(showTabHint, 500);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // 전역 API
    window.CampaignTour = {
        start: function () { localStorage.removeItem(STORAGE_KEY_TOUR); startTour(); },
        resetAll: function () {
            localStorage.removeItem(STORAGE_KEY_TAB);
            localStorage.removeItem(STORAGE_KEY_TOUR);
        }
    };
})();
