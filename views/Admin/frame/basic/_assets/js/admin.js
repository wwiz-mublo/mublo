document.addEventListener('DOMContentLoaded', function() {
    const searchform = document.querySelector('form[name="fsearch"]');
    if (searchform) {
        searchform.addEventListener('change', function(e) {
            const target = e.target;
            if (target.tagName.toLowerCase() !== 'select') return;
            // 카테고리 셀렉트일 경우
            if (target.classList.contains('category-select')) {
                let currentValue = target.value;
                if (!currentValue) {
                    const currentLevel = parseInt(target.dataset.level, 10);
                    const upperLevel = currentLevel - 1;
                    const upperSelect = searchform.querySelector(
                        `select.category-select[data-level="${upperLevel}"]`
                    );

                    if (upperSelect && upperSelect.value) {
                        currentValue = upperSelect.value;
                    }
                }
                const hidden = searchform.querySelector('input[name="scs[category]"]');
                if (hidden) {
                    hidden.value = currentValue;
                }
            }
            // 변경되면 즉시 폼 전송
            searchform.submit();
        });
    }
    
    // id="checkAll" 체크박스를 찾아 이벤트 추가
    const checkAllBox = document.getElementById('checkAll');
    if (checkAllBox) {
        checkAllBox.addEventListener('click', function() {
            const parentForm = checkAllBox.closest('form');
            if (parentForm) {
                const isChecked = checkAllBox.checked; // checkAllBox의 상태를 가져와서 form 내 체크박스에 적용 (disabled 제외)
                parentForm.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(function(checkbox) {
                    checkbox.checked = isChecked;
                    // 체크박스의 부모 li 요소에 접근
                    const parentLi = checkbox.closest('li');
                    if (parentLi) {
                        if (isChecked) {
                            parentLi.classList.add('checked');
                        } else {
                            parentLi.classList.remove('checked');
                        }
                    }
                });
            }
        });
    }

    // list-check 클래스를 가진 개별 체크박스에 대한 이벤트 리스너
    document.querySelectorAll('.list-check').forEach(function(checkbox) {
        checkbox.addEventListener('click', function() {
            const parentLi = checkbox.closest('li');
            if (parentLi) {
                if (checkbox.checked) {
                    parentLi.classList.add('checked');
                } else {
                    parentLi.classList.remove('checked');
                }
            }
        });
    });

    // 모든 form 마다 처리
    document.addEventListener('change', function (e) {
        const element = e.target.closest('[data-proto]');
        if (!element) return; // data-proto 없는 요소는 무시
        const form = element.closest('form');
        if (!form) return;
        // 현재 element 기준으로 가장 가까운 li[data-bunch]
        let tbody = element.closest('[data-bunch]');
        if (!tbody) {
            tbody = element.closest('li');
        }
        if (!tbody) {
            tbody = element.closest('tr');
        }
        
        if (!tbody) return;
        
        // list-check 클래스를 가진 체크박스만 선택
        const check = tbody.querySelectorAll('input[type="checkbox"].list-check') || [];
        if (check.length === 0) return;
        
        let update = false;
        // 같은 tbody 안에서만 비교
        tbody.querySelectorAll('[data-proto]').forEach(function (protoElement) {
            if (protoElement.type === 'checkbox') {
                if (protoElement.checked && protoElement.value !== protoElement.dataset.proto) {
                    update = true;
                }
                if (!protoElement.checked && protoElement.value === protoElement.dataset.proto) {
                    update = true;
                }
            } else {
                if (protoElement.value !== protoElement.dataset.proto) {
                    update = true;
                }
            }
        });
        
        // list-check 체크박스만 반영
        check.forEach(function (checkbox) {
            checkbox.checked = update;
            checkbox.dispatchEvent(new Event('change'));
            const parentLi = checkbox.closest('li');
            if (parentLi) {
                parentLi.classList.toggle('checked', update);
            }
        });
    });

    // keyup → change 이벤트도 위임
    document.addEventListener('keyup', function (e) {
        const element = e.target.closest('[data-proto][type="text"]');
        if (element) {
            element.dispatchEvent(new Event('change'));
        }
    });

    // 전체 선택 (한 페이지에서 단위별로 실행할 때)
    document.addEventListener('change', function(e) {
        if (e.target.matches('input[type="checkbox"][data-check-all]')) {
            if (!e.target.dataset.checkAll) return;
            document.querySelectorAll(`input[type="checkbox"][data-check-set=${e.target.dataset.checkAll}`).forEach(function(el) {
                el.checked = e.target.checked;
            });
        }
        if (e.target.matches('input[type="checkbox"][data-check-set]')) {
            if (!e.target.dataset.checkSet) return;
            document.querySelectorAll(`input[type="checkbox"][data-check-all=${e.target.dataset.checkSet}`).forEach(function(el) {
                el.checked = document.querySelectorAll(`input[type="checkbox"][data-check-set=${e.target.dataset.checkSet}]`).length === document.querySelectorAll(`input[type="checkbox"][data-check-set=${e.target.dataset.checkSet}]:checked`).length;
            });
        }
    });

    // 모든 <table>의 <colgroup> 내 <col> width를 min-width 스타일로 적용
    const autoMinwidthTables = document.querySelectorAll('.table-responsive table');
    autoMinwidthTables.forEach(table => {
        const cols = table.querySelectorAll('colgroup col');
        if (!cols.length) return;

        cols.forEach(col => {
            let width = col.getAttribute('width');
            if (!width) return;

            // 숫자만 추출
            width = parseInt(width, 10);
            if (isNaN(width) || width <= 0) return;

            // min-width 적용
            col.style.minWidth = width + 'px';
        });
    });
});

/* jquery 사용 시 */
function initializeColorPicker() {
    $('.color-code, .color_code').minicolors('destroy').minicolors({
        change: function(hex, opacity) {
            // 색상 변경 처리
        },
        theme: 'default'
    });
}

function initializeDatepicker(onSelectCallback) {
    $(".datepicker").datepicker({
        dateFormat: 'yy-mm-dd',
        prevText: '이전 달',
        nextText: '다음 달',
        monthNames: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
        monthNamesShort: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
        dayNames: ['일', '월', '화', '수', '목', '금', '토'],
        dayNamesShort: ['일', '월', '화', '수', '목', '금', '토'],
        dayNamesMin: ['일', '월', '화', '수', '목', '금', '토'],
        showMonthAfterYear: true,
        yearSuffix: '년',
        onSelect: function(dateText) {
            if (typeof onSelectCallback === 'function') {
                onSelectCallback(dateText);
            }
        }
    });
}


function confirmAction(message, action) {
    MubloRequest.showConfirm(message, function() {
        action();
    }, { type: 'warning' });
}

async function confirmDeleteBefore(el) {
    const target   = el.dataset.target;
    const no       = el.dataset.no;
    const page     = el.dataset.page || 1;
    const callback = el.dataset.callback;
    const message  = el.dataset.message || '정말로 삭제하시겠습니까?';

    MubloRequest.showConfirm(message, async function() {
        try {
            const response = await MubloRequest.requestJson(
                target,
                { no, page },
                { loading: true }
            );

            if (!response || response.result !== 'success') {
                throw new Error(response?.message || '알 수 없는 오류가 발생했습니다.');
            }

            if (callback) {
                await MubloRequest.executeCallback(callback, response);
            }

        } catch (error) {
            console.error(error);
            MubloRequest.showAlert('삭제 작업 중 오류가 발생했습니다: ' + error.message, 'error');
        }
    }, { type: 'warning' });
}

MubloRequest.registerCallback('checkListSelected', function(el) {
    const parentForm = el.closest('form');

    if (!parentForm) {
        return false; // 부모 폼이 없으면 false 반환
    }

    // 부모 폼 하위의 .list-check 클래스의 체크박스를 선택
    const checkboxes = parentForm.querySelectorAll('.list-check[type="checkbox"]');

    // 체크된 체크박스의 갯수를 계산
    const checkedCount = Array.from(checkboxes).filter(checkbox => checkbox.checked).length;
    
    if (checkedCount === 0) {
        MubloRequest.showAlert('선택된 내역이 없습니다.', 'warning');
        return false;
    }

    return true;
});

/**
 * ==============================
 * Template Utility Functions
 * ==============================
 * - loadScriptUrl()
 * - ensurePluginAndInit()
 */

(function () {
    /**
     * 스크립트 URL 동적 로드 (중복 로딩 방지)
     * @param {string} url 
     * @returns {Promise<void>}
     */
    async function loadScriptUrl(url) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-src="${url}"]`);
            if (existing) {
                if (existing.dataset.loaded === "1") return resolve();
                existing.addEventListener("load", () => resolve());
                existing.addEventListener("error", () => reject());
                return;
            }

            const s = document.createElement("script");
            s.src = url;
            s.async = true;
            s.dataset.src = url;
            s.addEventListener("load", () => {
                s.dataset.loaded = "1";
                resolve();
            });
            s.addEventListener("error", () => reject(new Error(`Failed to load script: ${url}`)));
            document.head.appendChild(s);
        });
    }

    /**
     * 플러그인 스크립트 로드 보장 + init 실행
     * @param {string} globalName - 전역 네임스페이스 (예: "PluginMshop")
     * @param {string} scriptUrl - 플러그인 JS 경로
     * @param {HTMLElement} parentEl - 렌더링할 부모 DOM
     * @param {object} pluginData - 서버에서 내려준 items/display 데이터
     */
    async function ensurePluginAndInit(globalName, scriptUrl, parentEl, pluginData = {}, requestData = {}) {
        try {
            if (typeof window[globalName] === "undefined") {
                await loadScriptUrl(scriptUrl);
            }

            const plugin = window[globalName];
            if (plugin && typeof plugin.init === "function") {
                plugin.init(parentEl, pluginData, requestData);
            } else {
                console.warn(`Plugin "${globalName}" loaded but init() not found.`);
            }
        } catch (err) {
            console.error(`Failed to load plugin script: ${scriptUrl}`, err);
        }
    }

    // 전역으로 등록 (window 아래에 묶기)
    window.AppUtils = window.AppUtils || {};
    window.AppUtils.loadScriptUrl = loadScriptUrl;
    window.AppUtils.ensurePluginAndInit = ensurePluginAndInit;
})();

/**
 * UI Utility: Sticky State Observer
 * ---------------------------------------------------------
 * .sticky-status 요소가 화면 경계(top/bottom)에 고정되는 순간을 감지하여
 * .is-sticky 클래스를 토글합니다.
 */
const StickyObserverModule = (() => {
    // 1. 초기화 함수
    const init = () => {
        const targets = document.querySelectorAll('.sticky-status');
        if (targets.length === 0) return;

        const observer = new IntersectionObserver(handleIntersect, {
            threshold: [1],
            rootMargin: '-1px 0px -1px 0px' // 상하 1px 오차로 정밀 감지
        });

        targets.forEach(el => observer.observe(el));
    };

    // 2. 상태 변화 핸들러
    const handleIntersect = (entries) => {
        entries.forEach(entry => {
            // 요소가 화면 경계에 닿아 100% 미만으로 보이는 순간 sticky 작동으로 간주
            const isSticky = entry.intersectionRatio < 1;
            entry.target.classList.toggle('is-sticky', isSticky);
        });
    };

    // 3. 외부 노출 (DOM 로드 완료 시 자동 실행)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // 필요 시 수동 초기화를 위해 메서드 노출
    return { reinit: init };
})();

/**
 * UI Utility: ScrollSpy Nav (No Hash)
 * ---------------------------------------------------------
 * .sticky-spy .nav 의 앵커 클릭 시 기본 동작(주소창에 #anchor 추가)을
 * 막고 scrollIntoView 로 직접 이동합니다. ScrollSpy 는 스크롤 위치로
 * 동작하므로 탭 강조(.active)는 그대로 유지되며, CSS scroll-margin-top
 * 오프셋도 적용됩니다. (해시가 URL에 남아 로그아웃 리다이렉트 시
 * 로그인 페이지까지 따라붙는 문제 방지)
 */
const ScrollSpyNavModule = (() => {
    const init = () => {
        const navs = document.querySelectorAll('.sticky-spy .nav');
        if (navs.length === 0) return;

        navs.forEach(nav => nav.addEventListener('click', (e) => {
            const link = e.target.closest('.nav-link[href^="#"]');
            if (!link) return;

            const id = link.getAttribute('href').slice(1);
            const target = document.getElementById(id);
            if (!target) return;

            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth' });
        }));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { reinit: init };
})();
