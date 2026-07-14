/**
 * Review Auto Display — Block Config UI
 *
 * 쇼핑몰 "구매후기" 콘텐츠 타입의 관리자 설정 UI.
 * 후기를 직접 고르지 않고 진열 조건(정렬 기준 + 표시 개수 + 필터)만 선택한다.
 *
 * 계약:
 *   window.MubloBlockReviewAuto = {
 *       init(containerEl, data),   // 초기화 (data.config로 기존값 복원)
 *       getConfig(),               // content_config에 병합될 설정 반환
 *       getSelectedItems(),        // 항상 [] (수동 선택 없음)
 *       destroy()                  // 정리
 *   }
 *
 * 카테고리 트리 같은 외부 데이터가 필요 없어 AJAX 없이 동기 렌더한다.
 */
(function () {
    'use strict';

    let state = {
        sortOrder: 'newest',
        displayCount: 4,
        photoOnly: false,
        bestOnly: false,
    };

    let labelEl = null;
    let helpEl = null;
    let originalLabel = '';

    const SORT_OPTIONS = [
        { value: 'newest', label: '최신순' },
        { value: 'rating_high', label: '평점 높은순' },
        { value: 'best', label: '베스트순' },
    ];

    window.MubloBlockReviewAuto = {
        init(containerEl, data) {
            const config = data.config || {};
            state.sortOrder = config.sort_order || 'newest';
            state.displayCount = parseInt(config.display_count) || 4;
            state.photoOnly = !!config.photo_only;
            state.bestOnly = !!config.best_only;

            adjustSurroundingLabels(containerEl);

            containerEl.innerHTML = '';
            containerEl.appendChild(buildSortSection());
            containerEl.appendChild(buildCountSection());
            containerEl.appendChild(buildFilterSection());
        },

        getConfig() {
            return {
                sort_order: state.sortOrder,
                display_count: state.displayCount,
                photo_only: state.photoOnly,
                best_only: state.bestOnly,
            };
        },

        getSelectedItems() {
            // 자동 진열은 후기를 직접 저장하지 않는다.
            return [];
        },

        destroy() {
            restoreSurroundingLabels();
            state = { sortOrder: 'newest', displayCount: 4, photoOnly: false, bestOnly: false };
            labelEl = null;
            helpEl = null;
            originalLabel = '';
        },
    };

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
    // 필터 (포토후기만 / 베스트만)
    // =========================================================================

    function buildFilterSection() {
        const wrapper = document.createElement('div');
        wrapper.className = 'mb-1';

        const label = document.createElement('label');
        label.className = 'form-label small text-muted d-block';
        label.textContent = '필터';
        wrapper.appendChild(label);

        wrapper.appendChild(buildCheck('photo', '포토 후기만', state.photoOnly, function (checked) {
            state.photoOnly = checked;
        }));
        wrapper.appendChild(buildCheck('best', '베스트 후기만', state.bestOnly, function (checked) {
            state.bestOnly = checked;
        }));

        return wrapper;
    }

    function buildCheck(key, labelText, checked, onChange) {
        const div = document.createElement('div');
        div.className = 'form-check form-check-inline';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input';
        input.checked = !!checked;

        const lbl = document.createElement('label');
        lbl.className = 'form-check-label small';
        lbl.textContent = labelText;

        // label↔input 연결 (key로 고유 id 생성 — 한글 라벨 충돌 방지)
        const id = 'rv_chk_' + key;
        input.id = id;
        lbl.htmlFor = id;

        input.addEventListener('change', function () {
            onChange(this.checked);
        });

        div.appendChild(input);
        div.appendChild(lbl);
        return div;
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
