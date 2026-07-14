/**
 * menu/tree — active·펼침 재동기화
 *
 * 행 HTML 캐시는 row_id 단위라, 공용 위치(position)에 놓인 트리 블록은
 * 최초 렌더 시점의 active/펼침 상태가 캐시에 남을 수 있다. 현재 경로
 * 기준으로 다시 계산해 보정한다. (서버 판정과 같은 규칙)
 */
(function () {
    'use strict';

    function normalize(path) {
        var p = (path || '/').split('?')[0].replace(/\/+$/, '');
        return p === '' ? '/' : p;
    }

    function sync(root) {
        var current = normalize(window.location.pathname);
        var links = root.querySelectorAll('.bmtr-link');
        var best = null;
        var bestScore = 0;

        links.forEach(function (a) {
            var url = normalize(a.getAttribute('href'));
            if (url === '/' || url === '#') return;
            var score = 0;
            if (url === current) score = url.length * 2 + 1;
            else if (current.indexOf(url + '/') === 0) score = url.length;
            if (score > bestScore) { bestScore = score; best = a; }
        });

        if (!best) return; // 판정 불가면 서버 렌더 결과 유지

        links.forEach(function (a) { a.classList.remove('is-active'); });
        root.querySelectorAll('.bmtr-item--branch.is-open').forEach(function (li) {
            li.classList.remove('is-open');
        });

        best.classList.add('is-active');

        // 조상 가지 펼침
        var li = best.closest('.bmtr-item');
        while (li) {
            if (li.classList.contains('bmtr-item--branch')) {
                li.classList.add('is-open');
            }
            li = li.parentElement ? li.parentElement.closest('.bmtr-item') : null;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-menu-tree]').forEach(sync);
    });
})();
