/**
 * 매뉴얼 목차 트리 관리
 *
 * SortableJS(전역 Sortable)를 각 하위 목록(.manual-tree__list)에 부착하고,
 * 같은 group 으로 묶어 항목을 다른 계층으로 이동할 수 있게 한다.
 * serialize()는 DOM을 순회해 각 페이지의 parent_id / sort_order 를 추출한다.
 */
window.ManualTree = (function () {
    'use strict';

    /**
     * 트리 초기화
     * @param {HTMLElement} root  #manualTree
     * @param {Function} onChange 순서/계층 변경 시 콜백 (저장 버튼 노출용)
     */
    function init(root, onChange) {
        if (!root || typeof Sortable === 'undefined') {
            return;
        }

        var lists = root.querySelectorAll('.manual-tree__list');
        lists.forEach(function (list) {
            Sortable.create(list, {
                group: 'manual-tree',
                handle: '.manual-tree__handle',
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.65,
                // 드래그 중에만 빈 자식 목록을 드롭존으로 노출 (CSS .is-dragging)
                onStart: function () {
                    root.classList.add('is-dragging');
                },
                onEnd: function () {
                    root.classList.remove('is-dragging');
                },
                onSort: function () {
                    if (typeof onChange === 'function') {
                        onChange();
                    }
                }
            });
        });
    }

    /**
     * DOM → 평탄한 노드 배열 [{page_id, parent_id, sort_order}, ...]
     * @param {HTMLElement} root #manualTree
     */
    function serialize(root) {
        var nodes = [];
        if (!root) {
            return nodes;
        }

        // 최상위 목록만 골라 재귀 (root 바로 아래 첫 .manual-tree__list)
        var topLists = [];
        root.childNodes.forEach(function (child) {
            if (child.nodeType === 1 && child.classList.contains('manual-tree__list')) {
                topLists.push(child);
            }
        });

        topLists.forEach(function (list) {
            walk(list, null, nodes);
        });

        return nodes;
    }

    /**
     * 하나의 <ol> 을 순회하며 직계 <li> 를 수집하고, 각 <li> 의 자식 <ol> 로 재귀.
     */
    function walk(listEl, parentId, out) {
        var order = 0;
        listEl.childNodes.forEach(function (li) {
            if (li.nodeType !== 1 || !li.classList.contains('manual-tree__item')) {
                return;
            }
            var pageId = parseInt(li.dataset.pageId, 10);
            if (!pageId) {
                return;
            }
            out.push({
                page_id: pageId,
                parent_id: parentId,
                sort_order: order++
            });

            // 이 <li> 의 직계 자식 목록
            li.childNodes.forEach(function (childList) {
                if (childList.nodeType === 1 && childList.classList.contains('manual-tree__list')) {
                    walk(childList, pageId, out);
                }
            });
        });
    }

    return {
        init: init,
        serialize: serialize
    };
})();
