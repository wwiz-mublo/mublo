/**
 * Product Auto Display — Block Config UI
 *
 * 상품 "자동 진열" 콘텐츠 타입의 관리자 설정 UI.
 * 수동 선택(block-product.js)과 달리 상품을 직접 고르지 않고,
 * 진열 조건(카테고리 + 진열 순서)만 선택한다.
 *
 * 계약:
 *   window.MubloBlockProductAuto = {
 *       init(containerEl, data),   // 초기화 (data.config로 기존값 복원)
 *       getConfig(),               // content_config에 병합될 설정 반환
 *       getSelectedItems(),        // 항상 [] (수동 선택 없음)
 *       destroy()                  // 정리
 *   }
 *
 * 의존성:
 *   - window.MubloRequest  (Core: 전역 AJAX 유틸리티)
 *
 * 엔드포인트:
 *   - /admin/shop/block-items?include_categories=1  (카테고리 트리 조회)
 */
(function () {
    'use strict';

    /** @type {Array<Object>} 카테고리 트리 [{code, name, path_code, parent_code}, ...] */
    let categories = [];

    /** @type {Object} 현재 선택 상태 */
    let state = {
        domainId: 1,
        sortOrder: 'newest',
        displayCount: 4,
        categoryCode: '',
    };

    /** @type {HTMLElement|null} 카테고리 셀렉트 컨테이너 */
    let catRow = null;

    /** 라벨/도움말 원복용 */
    let labelEl = null;
    let helpEl = null;
    let originalLabel = '';

    const SORT_OPTIONS = [
        { value: 'newest', label: '최신순' },
        { value: 'popular', label: '인기순' },
        { value: 'price_asc', label: '낮은 가격순' },
        { value: 'price_desc', label: '높은 가격순' },
    ];

    window.MubloBlockProductAuto = {
        async init(containerEl, data) {
            const config = data.config || {};
            state.domainId = data.domainId || 1;
            state.sortOrder = config.sort_order || 'newest';
            state.displayCount = parseInt(config.display_count) || 4;
            state.categoryCode = config.category_code || '';

            // 주변 라벨/도움말을 진열 조건용으로 조정 (DualListbox 안내문 숨김)
            adjustSurroundingLabels(containerEl);

            containerEl.innerHTML =
                '<div class="text-center text-muted py-3">' +
                '<div class="spinner-border spinner-border-sm"></div> 카테고리 로딩 중...</div>';

            try {
                categories = await loadCategories();

                containerEl.innerHTML = '';
                containerEl.appendChild(buildSortSection());
                containerEl.appendChild(buildCountSection());
                containerEl.appendChild(buildCategorySection());
                renderCategorySelects();
            } catch (error) {
                console.error('카테고리 로드 실패:', error);
                containerEl.innerHTML =
                    '<p class="text-danger">카테고리 목록을 불러오는데 실패했습니다.</p>';
            }
        },

        getConfig() {
            return {
                sort_order: state.sortOrder,
                display_count: state.displayCount,
                category_code: state.categoryCode,
            };
        },

        getSelectedItems() {
            // 자동 진열은 상품을 직접 저장하지 않는다.
            return [];
        },

        destroy() {
            restoreSurroundingLabels();
            categories = [];
            state = { domainId: 1, sortOrder: 'newest', displayCount: 4, categoryCode: '' };
            catRow = null;
            labelEl = null;
            helpEl = null;
            originalLabel = '';
        },
    };

    // =========================================================================
    // 데이터 로드
    // =========================================================================

    async function loadCategories() {
        const url = '/admin/shop/block-items?include_categories=1&domain_id=' + state.domainId;
        const response = await MubloRequest.requestJson(url);

        if (response.success && response.data && Array.isArray(response.data.categories)) {
            return response.data.categories;
        }
        return [];
    }

    // =========================================================================
    // 정렬 기준
    // =========================================================================

    function buildSortSection() {
        const wrapper = document.createElement('div');
        wrapper.className = 'mb-3';

        const label = document.createElement('label');
        label.className = 'form-label small text-muted';
        label.textContent = '정렬 기준';
        wrapper.appendChild(label);

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.style.maxWidth = '200px';

        SORT_OPTIONS.forEach(function (opt) {
            const option = document.createElement('option');
            option.value = opt.value;
            option.textContent = opt.label;
            if (opt.value === state.sortOrder) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        select.addEventListener('change', function () {
            state.sortOrder = this.value;
        });

        wrapper.appendChild(select);
        return wrapper;
    }

    // =========================================================================
    // 표시 개수
    // =========================================================================

    function buildCountSection() {
        const wrapper = document.createElement('div');
        wrapper.className = 'mb-3';

        const label = document.createElement('label');
        label.className = 'form-label small text-muted';
        label.textContent = '표시 개수';
        wrapper.appendChild(label);

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control form-control-sm';
        input.style.maxWidth = '120px';
        input.min = '1';
        input.max = '100';
        input.value = state.displayCount;

        // 입력 중에는 유효값만 반영, blur/change 시 1 미만은 보정
        input.addEventListener('input', function () {
            const v = parseInt(this.value);
            if (v >= 1) state.displayCount = v;
        });
        input.addEventListener('change', function () {
            let v = parseInt(this.value);
            if (!(v >= 1)) v = 1;
            state.displayCount = v;
            this.value = v;
        });

        wrapper.appendChild(input);
        return wrapper;
    }

    // =========================================================================
    // 카테고리 단계별 셀렉트
    // =========================================================================

    function buildCategorySection() {
        const wrapper = document.createElement('div');
        wrapper.className = 'mb-1';

        const label = document.createElement('label');
        label.className = 'form-label small text-muted';
        label.textContent = '카테고리 제한 (미선택 시 전체 상품)';
        wrapper.appendChild(label);

        catRow = document.createElement('div');
        catRow.className = 'd-flex gap-2 flex-wrap';
        wrapper.appendChild(catRow);

        return wrapper;
    }

    /**
     * 자식 카테고리 반환 (parent_code는 부모의 path_code 참조, 루트는 null)
     */
    function getChildCategories(parentPathCode) {
        return categories.filter(function (c) {
            if (parentPathCode === null) {
                return c.parent_code === null;
            }
            return c.parent_code === parentPathCode;
        });
    }

    /**
     * 저장된 category_code 기준으로 루트→대상 카테고리 체인 복원
     *
     * @returns {Array<Object>} 루트부터 순서대로 정렬된 카테고리 노드 배열
     */
    function buildSelectedChain() {
        if (!state.categoryCode) {
            return [];
        }

        const byCode = {};
        const byPath = {};
        categories.forEach(function (c) {
            byCode[c.code] = c;
            byPath[c.path_code] = c;
        });

        const target = byCode[state.categoryCode];
        if (!target) {
            return [];
        }

        const chain = [target];
        let cursor = target;
        // parent_code(= 부모 path_code)를 따라 위로 거슬러 올라감
        while (cursor && cursor.parent_code) {
            const parent = byPath[cursor.parent_code];
            if (!parent) {
                break;
            }
            chain.unshift(parent);
            cursor = parent;
        }
        return chain;
    }

    /**
     * 카테고리 셀렉트 렌더링
     *
     * 저장값이 있으면 해당 깊이까지 체인을 복원해 선택 상태로 표시
     */
    function renderCategorySelects() {
        if (!catRow) return;
        catRow.innerHTML = '';

        const roots = getChildCategories(null);
        if (roots.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-muted small mb-0';
            empty.textContent = '등록된 카테고리가 없습니다. (전체 상품 진열)';
            catRow.appendChild(empty);
            return;
        }

        const chain = buildSelectedChain();

        // 루트 셀렉트
        let items = roots;
        let depth = 0;

        if (chain.length === 0) {
            appendCategorySelect(catRow, items, depth, '');
            return;
        }

        // 체인을 따라 단계별 셀렉트를 선택값과 함께 생성
        for (let i = 0; i < chain.length; i++) {
            const selectedCat = chain[i];
            appendCategorySelect(catRow, items, depth, selectedCat.code);
            items = getChildCategories(selectedCat.path_code);
            depth++;
            if (items.length === 0) {
                break;
            }
        }

        // 가장 깊은 선택값에 하위가 더 있으면 빈 셀렉트 하나 더
        if (items.length > 0) {
            appendCategorySelect(catRow, items, depth, '');
        }
    }

    function appendCategorySelect(container, items, depth, selectedCode) {
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.dataset.depth = depth;
        select.style.maxWidth = '180px';

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = depth === 0 ? '카테고리 전체' : '전체';
        select.appendChild(defaultOption);

        items.forEach(function (cat) {
            const option = document.createElement('option');
            option.value = cat.code;
            option.textContent = cat.name;
            if (cat.code === selectedCode) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        select.addEventListener('change', function () {
            onCategorySelectChange(container, this, depth);
        });

        container.appendChild(select);
    }

    function onCategorySelectChange(container, selectEl, depth) {
        const selectedCode = selectEl.value;

        // 현재 depth 이후의 셀렉트 제거
        const selects = container.querySelectorAll('select');
        selects.forEach(function (s) {
            if (parseInt(s.dataset.depth) > depth) {
                container.removeChild(s);
            }
        });

        // 하위 카테고리가 있으면 다음 셀렉트 추가
        if (selectedCode) {
            const selectedCat = categories.find(function (c) { return c.code === selectedCode; });
            if (selectedCat) {
                const children = getChildCategories(selectedCat.path_code);
                if (children.length > 0) {
                    appendCategorySelect(container, children, depth + 1, '');
                }
            }
        }

        // 가장 깊은 선택값을 진열 카테고리로 사용
        state.categoryCode = getDeepestSelectedCode(container);
    }

    function getDeepestSelectedCode(container) {
        const selects = container.querySelectorAll('select');
        let deepest = '';
        selects.forEach(function (s) {
            if (s.value) {
                deepest = s.value;
            }
        });
        return deepest;
    }

    // =========================================================================
    // 주변 라벨/도움말 조정
    // =========================================================================

    function adjustSurroundingLabels(containerEl) {
        const wrapper = containerEl.closest('#content_items_container');
        if (!wrapper) return;

        labelEl = wrapper.querySelector('label.form-label');
        helpEl = wrapper.querySelector('small.text-muted');

        if (labelEl) {
            originalLabel = labelEl.textContent;
            labelEl.textContent = '진열 조건';
        }
        if (helpEl) {
            helpEl.style.display = 'none';
        }
    }

    function restoreSurroundingLabels() {
        if (labelEl && originalLabel) {
            labelEl.textContent = originalLabel;
        }
        if (helpEl) {
            helpEl.style.display = '';
        }
    }
})();
