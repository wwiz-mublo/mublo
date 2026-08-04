/**
 * Boardgroup 탭 전환 (basic-tab / gallery-tab / thumb-tab 공용)
 *
 * 부트스트랩 비의존. 탭 버튼(.block-boardgroup__tab[data-target]) 클릭 시
 * 같은 블록(.block-boardgroup) 안에서 해당 패널만 .active 로 토글한다.
 * 이벤트 위임(document) → 블록이 여러 개여도 각자 루트 범위에서 독립 동작.
 * toggle은 멱등이라 tab 스킨이 한 페이지에 여럿 있어 핸들러가 중복 등록돼도 안전.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var tab = e.target.closest('.block-boardgroup__tab');
        if (!tab) {
            return;
        }

        var root = tab.closest('.block-boardgroup');
        if (!root) {
            return;
        }

        var targetId = tab.getAttribute('data-target');

        root.querySelectorAll('.block-boardgroup__tab').forEach(function (t) {
            t.classList.toggle('active', t === tab);
        });

        root.querySelectorAll('.block-boardgroup__panel').forEach(function (p) {
            p.classList.toggle('active', p.id === targetId);
        });
    });
})();
