/**
 * ShopProductOption.js
 *
 * 상품 옵션/조합 선택 + 가격 계산 공용 모듈
 * 사용처: 상품 상세, 장바구니 옵션 변경 모달
 *
 * 사용법:
 * ```js
 * const handler = new ShopProductOption({
 *     container: '#shop-option-area',
 *     basePrice: 29000,
 *     optionMode: 'SINGLE',      // 'NONE' | 'SINGLE' | 'COMBINATION'
 *     options: [...],             // 서버 옵션 배열
 *     combos: [...],             // 서버 조합 배열
 *     onUpdate: ({ totalPrice, totalQuantity, selectedItems }) => { ... }
 * });
 * handler.init();
 * ```
 *
 * 데이터 구조 (서버 → JS):
 *   options: [{
 *     option_id, option_name, option_type ('BASIC'|'EXTRA'),
 *     is_required, sort_order,
 *     values: [{ value_id, value_name, extra_price, stock_quantity, is_active }]
 *   }]
 *   combos: [{
 *     combo_id, combination_key ('빨강/XL'), extra_price, stock_quantity, is_active
 *   }]
 */
class ShopProductOption {
    /**
     * @param {Object} config
     * @param {string|HTMLElement} config.container - 옵션 영역 컨테이너
     * @param {number} config.basePrice - 기본 판매가 (sales_price)
     * @param {string} config.optionMode - 'NONE' | 'SINGLE' | 'COMBINATION'
     * @param {Array} config.options - 옵션 그룹 배열
     * @param {Array} config.combos - 조합 배열 (COMBINATION 모드)
     * @param {Function} config.onUpdate - 상태 변경 콜백
     */
    constructor(config) {
        this.container = typeof config.container === 'string'
            ? document.querySelector(config.container)
            : config.container;

        this.basePrice = config.basePrice || 0;
        this.optionMode = config.optionMode || 'NONE';
        this.options = config.options || [];
        this.combos = config.combos || [];
        this.onUpdate = config.onUpdate || null;

        // 상태
        this.selectedItems = [];  // 선택된 옵션 목록
        this.quantity = 1;        // NONE 모드 수량

        // COMBINATION 모드: 현재 선택 중인 그룹 값
        this._comboSelections = {};

        // 내부 DOM 참조
        this._groupEls = {};
        this._selectedEl = null;
        this._qtyEl = null;
    }

    /* ==========================================================
     * 초기화
     * ========================================================== */

    init() {
        if (!this.container) return;
        this.render();
        this._fireUpdate();
    }

    render() {
        this.container.innerHTML = '';

        if (this.optionMode === 'NONE') {
            this._renderNoneMode();
        } else if (this.optionMode === 'SINGLE') {
            this._renderSingleMode();
        } else if (this.optionMode === 'COMBINATION') {
            this._renderCombinationMode();
        }

        // 선택된 옵션 표시 영역
        this._selectedEl = this._createElement('div', 'shop-option__selected');
        this.container.appendChild(this._selectedEl);
    }

    /* ==========================================================
     * NONE 모드 — 옵션 없음, 수량만
     * ========================================================== */

    _renderNoneMode() {
        const group = this._createElement('div', 'shop-option__group shop-option__group--qty');
        group.innerHTML = `
            <label class="shop-option__label">수량</label>
            <div class="shop-option__qty-control">
                <button type="button" class="shop-option__qty-btn shop-option__qty-btn--minus" disabled><i class="bi bi-dash-lg"></i></button>
                <input type="number" class="shop-option__qty-input" value="1" min="1" max="999">
                <button type="button" class="shop-option__qty-btn shop-option__qty-btn--plus"><i class="bi bi-plus-lg"></i></button>
            </div>
        `;
        this.container.appendChild(group);

        this._qtyEl = group.querySelector('.shop-option__qty-input');
        group.querySelector('.shop-option__qty-btn--minus').addEventListener('click', () => this._changeNoneQty(-1));
        group.querySelector('.shop-option__qty-btn--plus').addEventListener('click', () => this._changeNoneQty(1));
        this._qtyEl.addEventListener('change', () => {
            this.quantity = Math.max(1, parseInt(this._qtyEl.value) || 1);
            this._qtyEl.value = this.quantity;
            this._updateQtyBtnState(group);
            this._fireUpdate();
        });
    }

    _changeNoneQty(delta) {
        this.quantity = Math.max(1, this.quantity + delta);
        this._qtyEl.value = this.quantity;
        this._updateQtyBtnState(this._qtyEl.closest('.shop-option__group'));
        this._fireUpdate();
    }

    /* ==========================================================
     * SINGLE 모드 — 독립 옵션 선택
     * ========================================================== */

    _renderSingleMode() {
        this.options.forEach(opt => {
            const group = this._renderOptionGroup(opt);
            this.container.appendChild(group);
            this._groupEls[opt.option_id] = group;

            const select = group.querySelector('select');
            if (select) {
                select.addEventListener('change', () => this._onSingleSelect(opt, select));
            }
        });
    }

    _onSingleSelect(optionDef, selectEl) {
        const valueId = parseInt(selectEl.value);
        if (!valueId) return;

        const valueDef = (optionDef.values || []).find(v => parseInt(v.value_id) === valueId);
        if (!valueDef) return;

        const extraPrice = parseInt(valueDef.extra_price) || 0;
        const displayPrice = this.basePrice + extraPrice;
        const optionCode = `opt-${optionDef.option_id}-${valueDef.value_id}`;
        const maxStock = this._parseStock(valueDef.stock_quantity);

        // 같은 값 중복 선택 → 수량 증가
        const existing = this.selectedItems.find(item => item.id === optionCode);
        if (existing) {
            existing.quantity = Math.min(existing.quantity + 1, maxStock);
            selectEl.value = '';
            this._renderSelected();
            this._fireUpdate();
            return;
        }

        // 새 값 → 별도 행 추가
        this.selectedItems.push({
            id: optionCode,
            optionId: optionDef.option_id,
            optionName: optionDef.option_name,
            optionType: optionDef.option_type || 'BASIC',
            valueId: valueDef.value_id,
            valueName: valueDef.value_name,
            extraPrice: extraPrice,
            displayPrice: displayPrice,
            stockQuantity: maxStock,
            quantity: 1,
            optionCode: optionCode
        });

        selectEl.value = '';
        this._renderSelected();
        this._fireUpdate();
    }

    /* ==========================================================
     * COMBINATION 모드 — 순차 선택 → 조합 매칭
     * ========================================================== */

    _renderCombinationMode() {
        // BASIC 옵션만 조합 대상
        const basicOptions = this.options.filter(o => (o.option_type || 'BASIC') === 'BASIC');

        basicOptions.forEach((opt, index) => {
            const group = this._renderOptionGroup(opt, index > 0);
            this.container.appendChild(group);
            this._groupEls[opt.option_id] = group;

            const select = group.querySelector('select');
            if (select) {
                select.addEventListener('change', () => this._onComboSelect(basicOptions, opt, select));
            }
        });

        // EXTRA 옵션은 SINGLE처럼 독립
        const extraOptions = this.options.filter(o => o.option_type === 'EXTRA');
        extraOptions.forEach(opt => {
            const group = this._renderOptionGroup(opt);
            this.container.appendChild(group);
            this._groupEls[opt.option_id] = group;

            const select = group.querySelector('select');
            if (select) {
                select.addEventListener('change', () => this._onSingleSelect(opt, select));
            }
        });
    }

    _onComboSelect(basicOptions, currentOpt, selectEl) {
        const valueId = parseInt(selectEl.value);
        if (!valueId) return;

        const valueDef = (currentOpt.values || []).find(v => parseInt(v.value_id) === valueId);
        if (!valueDef) return;

        // 현재 그룹 선택 기록
        this._comboSelections[currentOpt.option_id] = {
            valueId: valueDef.value_id,
            valueName: valueDef.value_name
        };

        const currentIdx = basicOptions.findIndex(o => o.option_id === currentOpt.option_id);

        // 이후 그룹 초기화
        for (let i = currentIdx + 1; i < basicOptions.length; i++) {
            delete this._comboSelections[basicOptions[i].option_id];
            const nextGroup = this._groupEls[basicOptions[i].option_id];
            if (nextGroup) {
                const nextSelect = nextGroup.querySelector('select');
                if (nextSelect) nextSelect.value = '';
            }
        }

        // 모든 BASIC 그룹 선택 완료 시 → 조합 매칭
        const allSelected = basicOptions.every(o => this._comboSelections[o.option_id]);
        if (allSelected) {
            this._matchCombo(basicOptions);
        } else {
            // 다음 그룹 활성화 + 필터링
            if (currentIdx + 1 < basicOptions.length) {
                this._filterNextComboGroup(basicOptions, currentIdx + 1);
            }
        }
    }

    _matchCombo(basicOptions) {
        // combination_key 구성: 값이름을 옵션 순서대로 "/" 연결
        const keyParts = basicOptions.map(o => this._comboSelections[o.option_id]?.valueName || '');
        const combinationKey = keyParts.join('/');

        const combo = this.combos.find(c =>
            c.combination_key === combinationKey && this._isOn(c.is_active)
        );

        if (!combo) {
            this._showMessage('해당 조합은 현재 판매하지 않습니다.');
            this._resetComboSelections(basicOptions);
            return;
        }

        const comboStock = this._parseStock(combo.stock_quantity);
        if (combo.stock_quantity !== null && combo.stock_quantity !== '' && parseInt(combo.stock_quantity) <= 0) {
            this._showMessage('해당 조합은 품절입니다.');
            this._resetComboSelections(basicOptions);
            return;
        }

        const extraPrice = parseInt(combo.extra_price) || 0;
        const displayPrice = this.basePrice + extraPrice;
        const optionCode = `combo-${combo.combo_id}`;

        // 중복 체크 → 수량 증가
        const existing = this.selectedItems.find(item => item.id === optionCode);
        if (existing) {
            existing.quantity = Math.min(existing.quantity + 1, comboStock);
        } else {
            this.selectedItems.push({
                id: optionCode,
                comboId: combo.combo_id,
                optionType: 'BASIC',
                valueName: combinationKey,
                extraPrice: extraPrice,
                displayPrice: displayPrice,
                stockQuantity: comboStock,
                quantity: 1,
                optionCode: optionCode
            });
        }

        this._resetComboSelections(basicOptions);
        this._renderSelected();
        this._fireUpdate();
    }

    _filterNextComboGroup(basicOptions, nextIdx) {
        const nextOpt = basicOptions[nextIdx];
        const nextGroup = this._groupEls[nextOpt.option_id];
        if (!nextGroup) return;

        const select = nextGroup.querySelector('select');
        if (!select) return;

        // 현재까지 선택된 값 기반으로 가능한 조합 필터링
        const selectedParts = [];
        for (let i = 0; i < nextIdx; i++) {
            const sel = this._comboSelections[basicOptions[i].option_id];
            selectedParts.push(sel ? sel.valueName : '');
        }

        // 가능한 조합에서 다음 그룹의 값 추출
        const possibleValues = new Set();
        this.combos.forEach(combo => {
            if (!this._isOn(combo.is_active)) return;
            const parts = combo.combination_key.split('/');
            const match = selectedParts.every((part, i) => parts[i] === part);
            if (match && parts[nextIdx]) {
                possibleValues.add(parts[nextIdx]);
            }
        });

        // 셀렉트박스 옵션 필터
        Array.from(select.options).forEach(option => {
            if (option.value === '') return; // placeholder
            const valueName = option.dataset.name || option.textContent.split(' (+')[0].trim();
            option.disabled = !possibleValues.has(valueName);
            option.style.display = possibleValues.has(valueName) ? '' : 'none';
        });

        // 그룹 활성화
        select.disabled = false;
        nextGroup.classList.remove('shop-option__group--disabled');
    }

    _resetComboSelections(basicOptions) {
        this._comboSelections = {};
        basicOptions.forEach((opt, index) => {
            const group = this._groupEls[opt.option_id];
            if (!group) return;
            const select = group.querySelector('select');
            if (select) {
                select.value = '';
                // 모든 옵션 표시 복원
                Array.from(select.options).forEach(option => {
                    option.disabled = false;
                    option.style.display = '';
                });
                // 초기 캐스케이딩 상태 복원: 첫 그룹만 활성, 이후 그룹은 다시 비활성화
                select.disabled = index > 0;
            }
            group.classList.toggle('shop-option__group--disabled', index > 0);
        });
    }

    /* ==========================================================
     * 선택된 옵션 목록 렌더링
     * ========================================================== */

    _renderSelected() {
        if (!this._selectedEl) return;
        this._selectedEl.innerHTML = '';

        this.selectedItems.forEach(item => {
            const el = this._createElement('div', 'shop-option__item');
            el.dataset.itemId = item.id;

            const label = item.optionName
                ? `${item.optionName}: ${item.valueName}`
                : item.valueName;

            const priceLabel = item.extraPrice !== 0
                ? ` (${item.extraPrice > 0 ? '+' : ''}${item.extraPrice.toLocaleString()}원)`
                : '';

            el.innerHTML = `
                <div class="shop-option__item-info">
                    <span class="shop-option__item-name">${this._escapeHtml(label)}${priceLabel}</span>
                    <button type="button" class="shop-option__item-remove" aria-label="삭제"><i class="bi bi-x"></i></button>
                </div>
                <div class="shop-option__item-controls">
                    <div class="shop-option__qty-control">
                        <button type="button" class="shop-option__qty-btn shop-option__qty-btn--minus" ${item.quantity <= 1 ? 'disabled' : ''}><i class="bi bi-dash-lg"></i></button>
                        <input type="number" class="shop-option__qty-input" value="${item.quantity}" min="1" max="${item.stockQuantity || 999}">
                        <button type="button" class="shop-option__qty-btn shop-option__qty-btn--plus"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <span class="shop-option__item-price">${(item.displayPrice * item.quantity).toLocaleString()}원</span>
                </div>
            `;

            // 수량 이벤트
            const minusBtn = el.querySelector('.shop-option__qty-btn--minus');
            const plusBtn = el.querySelector('.shop-option__qty-btn--plus');
            const qtyInput = el.querySelector('.shop-option__qty-input');

            minusBtn.addEventListener('click', () => this._changeItemQty(item, -1));
            plusBtn.addEventListener('click', () => this._changeItemQty(item, 1));
            qtyInput.addEventListener('change', () => {
                const val = Math.max(1, Math.min(parseInt(qtyInput.value) || 1, item.stockQuantity || 999));
                item.quantity = val;
                this._renderSelected();
                this._fireUpdate();
            });

            // 삭제 이벤트
            el.querySelector('.shop-option__item-remove').addEventListener('click', () => {
                this.selectedItems = this.selectedItems.filter(i => i.id !== item.id);
                this._renderSelected();
                this._fireUpdate();
            });

            this._selectedEl.appendChild(el);
        });
    }

    _changeItemQty(item, delta) {
        const maxQty = item.stockQuantity || 999;
        item.quantity = Math.max(1, Math.min(item.quantity + delta, maxQty));
        this._renderSelected();
        this._fireUpdate();
    }

    /* ==========================================================
     * 상태 갱신 → 콜백
     * ========================================================== */

    _fireUpdate() {
        const result = this.getState();
        if (this.onUpdate) {
            this.onUpdate(result);
        }
    }

    /**
     * 현재 상태 반환
     * @returns {{ totalPrice: number, totalQuantity: number, selectedItems: Array, extraPrice: number }}
     */
    getState() {
        let totalPrice = 0;
        let extraPrice = 0;
        let totalQuantity = 0;

        if (this.optionMode === 'NONE') {
            // NONE 모드 → 기본가 × 수량
            totalPrice = this.basePrice * this.quantity;
            totalQuantity = this.quantity;
        } else if (this.selectedItems.length === 0) {
            // 옵션 미선택 → 0원, 0개
            totalPrice = 0;
            totalQuantity = 0;
        } else {
            this.selectedItems.forEach(item => {
                if (item.optionType === 'EXTRA') {
                    extraPrice += item.extraPrice * item.quantity;
                } else {
                    totalPrice += item.displayPrice * item.quantity;
                }
                totalQuantity += item.quantity;
            });
        }

        return {
            totalPrice,
            extraPrice,
            totalQuantity,
            grandTotal: totalPrice + extraPrice,
            selectedItems: this.selectedItems.map(item => ({...item}))
        };
    }

    /**
     * 장바구니/구매 요청용 데이터 추출
     * @returns {Object}
     */
    getSubmitData() {
        if (this.optionMode === 'NONE') {
            return {
                optionMode: 'NONE',
                quantity: this.quantity,
                selectedOptions: [],
                selectedExtras: []
            };
        }

        const options = [];
        const extras = [];

        this.selectedItems.forEach(item => {
            const entry = {
                optionCode: item.optionCode,
                quantity: item.quantity
            };

            if (item.comboId) {
                entry.comboId = item.comboId;
            }
            if (item.optionId) {
                entry.optionId = item.optionId;
            }
            if (item.valueId) {
                entry.valueId = item.valueId;
            }

            if (item.optionType === 'EXTRA') {
                extras.push(entry);
            } else {
                options.push(entry);
            }
        });

        return {
            optionMode: this.optionMode,
            quantity: this.selectedItems.reduce((sum, i) => sum + i.quantity, 0),
            selectedOptions: options,
            selectedExtras: extras
        };
    }

    /* ==========================================================
     * 옵션 그룹 렌더링 헬퍼
     * ========================================================== */

    _renderOptionGroup(optionDef, disabled = false) {
        const group = this._createElement('div', 'shop-option__group');
        if (disabled) group.classList.add('shop-option__group--disabled');

        const required = this._isOn(optionDef.is_required);
        const requiredMark = required ? '<span class="shop-option__required">*</span>' : '';

        const values = (optionDef.values || []).filter(v => this._isOn(v.is_active));

        let optionsHtml = '<option value="">선택하세요</option>';
        values.forEach(val => {
            const price = parseInt(val.extra_price) || 0;
            const priceSuffix = price > 0 ? ` (+${price.toLocaleString()}원)` : (price < 0 ? ` (${price.toLocaleString()}원)` : '');
            const isSoldout = val.stock_quantity !== null && val.stock_quantity !== undefined && val.stock_quantity !== '' && parseInt(val.stock_quantity) <= 0;
            const stockInfo = isSoldout && this.optionMode === 'SINGLE' ? ' [품절]' : '';
            const isDisabled = isSoldout && this.optionMode === 'SINGLE' ? 'disabled' : '';

            optionsHtml += `<option value="${val.value_id}"
                data-price="${price}"
                data-stock="${val.stock_quantity || 0}"
                data-name="${this._escapeHtml(val.value_name)}"
                ${isDisabled}>
                ${this._escapeHtml(val.value_name)}${priceSuffix}${stockInfo}
            </option>`;
        });

        group.innerHTML = `
            <label class="shop-option__label">
                ${this._escapeHtml(optionDef.option_name)}${requiredMark}
            </label>
            <select class="shop-option__select" data-option-id="${optionDef.option_id}" ${disabled ? 'disabled' : ''}>
                ${optionsHtml}
            </select>
        `;

        return group;
    }

    /* ==========================================================
     * 유틸리티
     * ========================================================== */

    _createElement(tag, className) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        return el;
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    _updateQtyBtnState(group) {
        if (!group) return;
        const minus = group.querySelector('.shop-option__qty-btn--minus');
        if (minus) minus.disabled = this.quantity <= 1;
    }

    /**
     * stock_quantity → 최대 수량으로 변환
     * null/빈값 → 999 (재고 미관리), 숫자 → 해당 값
     */
    _parseStock(value) {
        if (value === null || value === undefined || value === '') return 999;
        const num = parseInt(value);
        return isNaN(num) ? 999 : (num <= 0 ? 999 : num);
    }

    /**
     * is_active / is_required 등 on-off 플래그 판정
     *
     * 공급 경로에 따라 타입이 다르다:
     * - ProductPresenter 경유(상품 상세): boolean true/false
     * - Repository raw 행 경유(장바구니 옵션 변경): 1 또는 "1"
     * 값이 없으면(undefined/null/빈값) 켜진 것으로 본다.
     */
    _isOn(value) {
        if (value === undefined || value === null || value === '') return true;
        if (typeof value === 'boolean') return value;
        if (typeof value === 'number') return value !== 0;
        const normalized = String(value).trim().toLowerCase();
        return normalized !== '0' && normalized !== 'false';
    }

    _showMessage(msg) {
        if (typeof MubloRequest !== 'undefined' && MubloRequest.showToast) {
            MubloRequest.showToast(msg, 'warning');
        } else {
            alert(msg);
        }
    }

    /**
     * 기존 선택 복원 (장바구니 옵션 변경 시)
     * @param {Array} items - 복원할 선택 항목 목록
     */
    restoreSelections(items) {
        this.selectedItems = items.map(item => ({...item}));
        this._renderSelected();
        this._fireUpdate();
    }

    /** 전체 초기화 */
    reset() {
        this.selectedItems = [];
        this._comboSelections = {};
        this.quantity = 1;
        this.render();
        this._fireUpdate();
    }
}

// 전역 등록
window.ShopProductOption = ShopProductOption;
