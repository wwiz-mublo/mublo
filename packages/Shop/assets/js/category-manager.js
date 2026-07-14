/**
 * CategoryManager — 캐스케이딩 드롭다운 카테고리 셀렉터
 *
 * Shop 패키지 공용 컴포넌트.
 * 상품 폼, 상품 목록 필터, 프론트 카테고리 네비게이션 등에서 재사용 가능.
 *
 * @example
 * // 기본 사용
 * const cm = new CategoryManager({
 *     wrapperId: 'category-primary',
 *     categoryTree: treeData,            // [{category_code, name, children[]}, ...]
 *     hiddenInput: 'categoryCodeInput',  // hidden input ID (string) 또는 Element
 *     selectedValue: 'xK9mL3nR',        // 수정 모드 시 기존 선택값 (leaf code)
 *     placeholder: '카테고리 선택',
 *     selectClass: 'form-select',
 *     onChange: ({value, name, path}) => { ... }
 * });
 *
 * // 초기화 후 제어
 * cm.reset();           // 선택 초기화
 * cm.getValue();        // 현재 선택된 category_code
 * cm.getPath();         // 선택 경로 [{code, name}, ...]
 */
class CategoryManager {
    constructor(config) {
        this.wrapperId = config.wrapperId;
        this.wrapper = document.getElementById(config.wrapperId);
        this.tree = config.categoryTree || [];
        this.hiddenInput = config.hiddenInput || null;
        this.selectedValue = config.selectedValue || '';
        this.onChange = config.onChange || null;
        this.placeholder = config.placeholder || '선택';
        this.selectClass = config.selectClass || 'form-select';
        this.selectStyle = config.selectStyle || 'min-width:160px; max-width:220px';
        this.selects = [];

        this.init();
    }

    init() {
        if (!this.wrapper || this.tree.length === 0) return;

        // 루트 레벨 셀렉트 생성
        this.createSelect(this.tree, 0);

        // 수정 모드: 기존 선택값 복원
        if (this.selectedValue) {
            this.restoreSelection();
        }
    }

    /**
     * 특정 레벨에 셀렉트 생성
     */
    createSelect(items, level) {
        // 기존 하위 셀렉트 제거
        while (this.selects.length > level) {
            const old = this.selects.pop();
            old.remove();
        }

        if (!items || items.length === 0) return;

        const select = document.createElement('select');
        select.className = this.selectClass;
        if (this.selectStyle) {
            select.style.cssText = this.selectStyle;
        }
        select.dataset.level = level;

        // 기본 옵션
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = this.placeholder;
        select.appendChild(defaultOpt);

        // 카테고리 옵션
        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.category_code;
            opt.textContent = item.name;
            opt.dataset.hasChildren = (item.children && item.children.length > 0) ? '1' : '0';
            select.appendChild(opt);
        });

        // 변경 이벤트
        select.addEventListener('change', () => this.onSelectChange(select, items, level));

        this.wrapper.appendChild(select);
        this.selects.push(select);
    }

    /**
     * 셀렉트 변경 핸들러
     */
    onSelectChange(select, items, level) {
        const code = select.value;

        // 하위 셀렉트 제거
        while (this.selects.length > level + 1) {
            const old = this.selects.pop();
            old.remove();
        }

        if (!code) {
            this.updateValue();
            return;
        }

        // 선택된 항목 찾기
        const selected = items.find(item => item.category_code === code);

        // 하위 카테고리가 있으면 다음 셀렉트 생성
        if (selected && selected.children && selected.children.length > 0) {
            this.createSelect(selected.children, level + 1);
        }

        // hidden input 값 업데이트
        this.updateValue();
    }

    /**
     * 가장 깊은 선택값으로 hidden input 갱신 + 콜백
     */
    updateValue() {
        let value = '';
        let name = '';

        for (let i = this.selects.length - 1; i >= 0; i--) {
            if (this.selects[i].value) {
                value = this.selects[i].value;
                const selectedOption = this.selects[i].options[this.selects[i].selectedIndex];
                name = selectedOption ? selectedOption.textContent : '';
                break;
            }
        }

        // 경로 수집
        const path = [];
        this.selects.forEach(s => {
            if (s.value) {
                const opt = s.options[s.selectedIndex];
                path.push({ code: s.value, name: opt ? opt.textContent : '' });
            }
        });

        // hidden input 업데이트
        if (this.hiddenInput) {
            const input = typeof this.hiddenInput === 'string'
                ? document.getElementById(this.hiddenInput)
                : this.hiddenInput;
            if (input) input.value = value;
        }

        // 콜백
        if (this.onChange) {
            this.onChange({ value, name, path });
        }
    }

    /**
     * 수정 모드: 기존 선택값의 경로를 추적하여 각 레벨 셀렉트 복원
     */
    restoreSelection() {
        const path = this.findPath(this.tree, this.selectedValue);
        if (!path || path.length === 0) return;

        let currentItems = this.tree;
        path.forEach((code, level) => {
            const select = this.selects[level];
            if (!select) return;

            select.value = code;

            const item = currentItems.find(i => i.category_code === code);
            if (item && item.children && item.children.length > 0 && level < path.length - 1) {
                this.createSelect(item.children, level + 1);
                currentItems = item.children;
            }
        });
    }

    /**
     * 트리에서 targetCode까지의 경로(코드 배열)를 DFS로 탐색
     */
    findPath(nodes, targetCode, currentPath = []) {
        for (const node of nodes) {
            const newPath = [...currentPath, node.category_code];
            if (node.category_code === targetCode) {
                return newPath;
            }
            if (node.children && node.children.length > 0) {
                const found = this.findPath(node.children, targetCode, newPath);
                if (found) return found;
            }
        }
        return null;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * 현재 선택된 category_code 반환
     */
    getValue() {
        for (let i = this.selects.length - 1; i >= 0; i--) {
            if (this.selects[i].value) return this.selects[i].value;
        }
        return '';
    }

    /**
     * 선택 경로 반환
     */
    getPath() {
        const path = [];
        this.selects.forEach(s => {
            if (s.value) {
                const opt = s.options[s.selectedIndex];
                path.push({ code: s.value, name: opt ? opt.textContent : '' });
            }
        });
        return path;
    }

    /**
     * 선택 초기화
     */
    reset() {
        while (this.selects.length > 1) {
            const old = this.selects.pop();
            old.remove();
        }
        if (this.selects[0]) {
            this.selects[0].value = '';
        }
        if (this.hiddenInput) {
            const input = typeof this.hiddenInput === 'string'
                ? document.getElementById(this.hiddenInput)
                : this.hiddenInput;
            if (input) input.value = '';
        }
    }

    /**
     * 새 트리 데이터로 교체 (예: AJAX 로드 후)
     */
    setTree(tree) {
        this.tree = tree || [];
        this.wrapper.innerHTML = '';
        this.selects = [];
        if (this.tree.length > 0) {
            this.createSelect(this.tree, 0);
        }
    }

    /**
     * 프로그래밍 방식으로 값 설정
     */
    setValue(categoryCode) {
        this.selectedValue = categoryCode;
        // 셀렉트 초기화 후 복원
        this.wrapper.innerHTML = '';
        this.selects = [];
        this.createSelect(this.tree, 0);
        if (categoryCode) {
            this.restoreSelection();
        }
    }
}
