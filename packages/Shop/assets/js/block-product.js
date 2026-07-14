/**
 * Product Block Item Selector
 *
 * Core의 MubloDualListbox 컴포넌트를 재사용하여 상품을 선택합니다.
 * 단계별 카테고리 필터, 상품명 검색, 페이지네이션을 지원합니다.
 *
 * 계약:
 *   window.MubloBlockProduct = {
 *       init(containerEl, data),      // 초기화
 *       getSelectedItems(),           // 선택된 아이템 반환
 *       destroy()                     // 정리
 *   }
 *
 * 의존성:
 *   - window.MubloDualListbox  (Core: blockrow-form.js에서 제공)
 *   - window.MubloRequest      (Core: 전역 AJAX 유틸리티)
 *
 * 엔드포인트:
 *   - /admin/shop/block-items  (Shop 자체 라우트)
 */
(function() {
    'use strict';

    /** @type {DualListbox|null} */
    let dualListbox = null;

    /** @type {Object} 현재 로드된 상품 데이터 (id → item) */
    let productMap = {};

    /** @type {Array<Object>} 카테고리 트리 [{code, name, parent_code, depth}, ...] */
    let categories = [];

    /** @type {Object} 현재 필터/페이징 상태 */
    let currentState = {
        domainId: 1,
        categoryCode: '',
        keyword: '',
        page: 1,
        totalPages: 1,
    };

    /** @type {HTMLElement|null} 필터 영역 */
    let filterEl = null;

    /** @type {HTMLElement|null} 페이징 영역 */
    let paginationEl = null;

    window.MubloBlockProduct = {
        livePreview: false,
        /**
         * 초기화 — Core가 Plugin JS 로드 후 호출
         */
        async init(containerEl, data) {
            const selectedItems = data.selectedItems || [];
            currentState.domainId = data.domainId || 1;
            const maxItems = data.maxItems || 0;

            // 기존 선택값에서 ID 추출
            const selectedIds = selectedItems.map(item =>
                typeof item === 'object' ? String(item.id) : String(item)
            );

            // 기존 선택값 → productMap에 보존 (페이지 전환해도 유지)
            selectedItems.forEach(item => {
                if (typeof item === 'object' && item.id) {
                    productMap[String(item.id)] = item;
                }
            });

            containerEl.innerHTML =
                '<div class="text-center text-muted py-3">' +
                '<div class="spinner-border spinner-border-sm"></div> 상품 목록 로딩 중...</div>';

            try {
                const response = await loadItems(1, true);

                if (response) {
                    containerEl.innerHTML = '';

                    // 필터 영역 (단계별 카테고리 셀렉트 + 검색)
                    filterEl = buildFilterSection();
                    containerEl.appendChild(filterEl);

                    // 카테고리 셀렉트 렌더링 (filterEl 생성 후)
                    if (categories.length > 0) {
                        renderCategorySelects();
                    }

                    // DualListbox 영역
                    const listboxEl = document.createElement('div');
                    listboxEl.className = 'block-product-listbox';
                    containerEl.appendChild(listboxEl);

                    // DualListbox 초기화
                    dualListbox = new MubloDualListbox(listboxEl, {
                        available: response.items,
                        selected: selectedIds,
                        maxItems: maxItems,
                        leftTitle: '사용 가능한 상품',
                        rightTitle: '선택된 상품',
                        onChanged: data.onChanged || null,
                    });
                    this._dualListbox = dualListbox;

                    // 페이징 영역
                    paginationEl = document.createElement('div');
                    paginationEl.className = 'block-product-pagination mt-2';
                    containerEl.appendChild(paginationEl);
                    renderPagination();
                } else {
                    containerEl.innerHTML =
                        '<p class="text-muted">선택 가능한 상품이 없습니다.</p>';
                }
            } catch (error) {
                console.error('상품 목록 로드 실패:', error);
                containerEl.innerHTML =
                    '<p class="text-danger">상품 목록을 불러오는데 실패했습니다.</p>';
            }
        },

        /**
         * 선택된 아이템 반환
         */
        getSelectedItems() {
            if (!dualListbox) return [];

            const selectedIds = dualListbox.getSelected();
            return selectedIds.map(id => {
                return productMap[id] || { id: id };
            });
        },

        /**
         * 정리
         */
        destroy() {
            dualListbox = null;
            productMap = {};
            categories = [];
            currentState = { domainId: 1, categoryCode: '', keyword: '', page: 1, totalPages: 1 };
            filterEl = null;
            paginationEl = null;
        }
    };

    /**
     * 서버에서 상품 목록 로드
     *
     * @param {number} page 페이지 번호
     * @param {boolean} includeCategories 카테고리 트리 포함 여부 (첫 로드 시)
     */
    async function loadItems(page, includeCategories) {
        currentState.page = page;

        let url = '/admin/shop/block-items?domain_id=' + currentState.domainId
            + '&page=' + page;

        if (includeCategories) {
            url += '&include_categories=1';
        }

        if (currentState.categoryCode) {
            url += '&category_code=' + encodeURIComponent(currentState.categoryCode);
        }
        if (currentState.keyword) {
            url += '&keyword=' + encodeURIComponent(currentState.keyword);
        }

        const response = await MubloRequest.requestJson(url);

        if (response.success && response.data) {
            const items = response.data.items || [];
            const pagination = response.data.pagination || {};

            // productMap에 축적 (선택된 아이템 정보 유지)
            items.forEach(item => {
                productMap[String(item.id)] = item;
            });

            // 카테고리 (첫 로드 시만 저장, 렌더링은 init에서)
            if (response.data.categories && response.data.categories.length > 0) {
                categories = response.data.categories;
            }

            currentState.totalPages = pagination.totalPages || 1;

            return { items: items, pagination: pagination };
        }

        return null;
    }

    // =========================================================================
    // 카테고리 단계별 셀렉트
    // =========================================================================

    /**
     * 필터 영역 생성
     */
    function buildFilterSection() {
        const wrapper = document.createElement('div');
        wrapper.className = 'block-product-filters mb-3';

        // 카테고리 셀렉트 컨테이너 (단계별 셀렉트가 여기 추가됨)
        const catRow = document.createElement('div');
        catRow.className = 'd-flex gap-2 mb-2 flex-wrap';
        catRow.id = 'block-product-cat-row';
        wrapper.appendChild(catRow);

        // 검색 입력
        const searchRow = document.createElement('div');
        searchRow.className = 'd-flex gap-2';
        searchRow.innerHTML =
            '<div class="input-group input-group-sm" style="max-width:280px;">' +
            '<input type="text" class="form-control" id="block-product-keyword" placeholder="상품명 검색">' +
            '<button class="btn btn-outline-secondary" type="button" id="block-product-search-btn">검색</button>' +
            '</div>';
        wrapper.appendChild(searchRow);

        // 검색 이벤트 바인딩
        const keywordInput = searchRow.querySelector('#block-product-keyword');
        const searchBtn = searchRow.querySelector('#block-product-search-btn');

        if (searchBtn) {
            searchBtn.addEventListener('click', function() {
                currentState.keyword = keywordInput ? keywordInput.value.trim() : '';
                currentState.page = 1;
                applyFilter();
            });
        }

        if (keywordInput) {
            keywordInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    currentState.keyword = this.value.trim();
                    currentState.page = 1;
                    applyFilter();
                }
            });
        }

        return wrapper;
    }

    /**
     * 자식 카테고리 반환
     *
     * parent_code는 부모의 path_code를 참조함
     * 루트 노드는 parent_code가 null
     *
     * @param {string|null} parentPathCode 부모의 path_code (null = 루트)
     * @returns {Array<Object>}
     */
    function getChildCategories(parentPathCode) {
        return categories.filter(function(c) {
            if (parentPathCode === null) {
                return c.parent_code === null;
            }
            return c.parent_code === parentPathCode;
        });
    }

    /**
     * 카테고리 단계별 셀렉트 렌더링
     *
     * 초기 로드 시 루트 셀렉트만 생성
     */
    function renderCategorySelects() {
        const catRow = filterEl ? filterEl.querySelector('#block-product-cat-row') : null;
        if (!catRow) return;

        catRow.innerHTML = '';

        // 루트 카테고리 (parent_code가 null인 노드)
        const roots = getChildCategories(null);
        if (roots.length === 0) return;

        appendCategorySelect(catRow, roots, 0);
    }

    /**
     * 셀렉트 요소 추가
     *
     * @param {HTMLElement} container 셀렉트를 추가할 컨테이너
     * @param {Array<Object>} items 카테고리 항목 배열
     * @param {number} depth 현재 depth
     */
    function appendCategorySelect(container, items, depth) {
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.dataset.depth = depth;
        select.style.maxWidth = '180px';

        // 기본 옵션
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = depth === 0 ? '카테고리 전체' : '전체';
        select.appendChild(defaultOption);

        items.forEach(function(cat) {
            const option = document.createElement('option');
            option.value = cat.code;
            option.textContent = cat.name;
            select.appendChild(option);
        });

        // 변경 이벤트
        select.addEventListener('change', function() {
            onCategorySelectChange(container, this, depth);
        });

        container.appendChild(select);
    }

    /**
     * 카테고리 셀렉트 변경 핸들러
     *
     * 1. 현재 depth 이후의 셀렉트 모두 제거
     * 2. 선택된 값에 하위 카테고리가 있으면 다음 셀렉트 추가
     * 3. 가장 깊은 선택값으로 필터 적용
     */
    function onCategorySelectChange(container, selectEl, depth) {
        const selectedCode = selectEl.value;

        // 현재 depth 이후의 셀렉트 제거
        var selects = container.querySelectorAll('select');
        selects.forEach(function(s) {
            if (parseInt(s.dataset.depth) > depth) {
                container.removeChild(s);
            }
        });

        // 하위 카테고리가 있으면 다음 셀렉트 추가
        // 선택된 카테고리의 path_code로 자식 매칭
        if (selectedCode) {
            const selectedCat = categories.find(function(c) { return c.code === selectedCode; });
            if (selectedCat) {
                const children = getChildCategories(selectedCat.path_code);
                if (children.length > 0) {
                    appendCategorySelect(container, children, depth + 1);
                }
            }
        }

        // 가장 깊은 선택값으로 필터 적용
        currentState.categoryCode = getDeepestSelectedCode(container);
        currentState.page = 1;
        applyFilter();
    }

    /**
     * 셀렉트 체인에서 가장 깊은 선택값 반환
     *
     * depth 역순으로 탐색하여 첫 번째로 값이 있는 셀렉트의 값 반환
     */
    function getDeepestSelectedCode(container) {
        var selects = container.querySelectorAll('select');
        var deepest = '';

        selects.forEach(function(s) {
            if (s.value) {
                deepest = s.value;
            }
        });

        return deepest;
    }

    // =========================================================================
    // 필터/페이징
    // =========================================================================

    /**
     * 필터/페이징 적용 → 서버 재조회
     */
    async function applyFilter() {
        const result = await loadItems(currentState.page);
        if (result && dualListbox) {
            dualListbox.setAvailable(result.items);
            renderPagination();
        }
    }

    /**
     * 페이징 UI 렌더링
     */
    function renderPagination() {
        if (!paginationEl) return;

        const page = currentState.page;
        const total = currentState.totalPages;

        if (total <= 1) {
            paginationEl.innerHTML = '';
            return;
        }

        let html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';

        // 이전
        html += '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="#" data-page="' + (page - 1) + '">&laquo;</a></li>';

        // 페이지 번호 (최대 5개 표시)
        const start = Math.max(1, page - 2);
        const end = Math.min(total, start + 4);

        for (let i = start; i <= end; i++) {
            html += '<li class="page-item' + (i === page ? ' active' : '') + '">';
            html += '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }

        // 다음
        html += '<li class="page-item' + (page >= total ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="#" data-page="' + (page + 1) + '">&raquo;</a></li>';

        html += '</ul></nav>';
        paginationEl.innerHTML = html;

        // 페이지 클릭 이벤트
        paginationEl.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetPage = parseInt(this.dataset.page);
                if (targetPage >= 1 && targetPage <= total && targetPage !== page) {
                    currentState.page = targetPage;
                    applyFilter();
                }
            });
        });
    }

    /**
     * HTML 이스케이프
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
