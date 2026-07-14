/**
 * ============================================================
 * MubloForm.js - Mublo Framework 폼 처리 + 입력 마스크
 * ============================================================
 *
 * 폼 데이터 채우기, 검증, 입력 마스크, 파일 미리보기를 제공한다.
 *
 * 의존: MubloCore.js (numberFormat, phoneFormat, birthFormat 등)
 *
 * API:
 *   MubloForm.fill(formData, formName, formIdOrCallback, callback)
 *   MubloForm.validate(form)
 *   MubloForm.toObject(formData)
 *   MubloForm.util.clearSelect(id)
 *   MubloForm.util.populateSelect(id, options)
 *   MubloForm.util.populateRadio(containerId, name, options)
 *   MubloForm.util.populateCheckbox(containerId, name, options)
 *
 * 자동 초기화 (DOMContentLoaded):
 *   - 입력 마스크 (mask-num, mask-hp, mask-birth, number, number-format)
 *   - 파일 미리보기
 *   - 이벤트 위임
 *
 * ============================================================
 */

const MubloForm = (() => {
    'use strict';

    /* =========================================================
     * 폼 데이터 채우기
     * ========================================================= */

    /**
     * 폼 데이터를 채우는 함수
     *
     * @param {Object}   formData          채울 데이터 객체
     * @param {string}   formName          필드 이름 접두사 (예: 'formData')
     * @param {string|Function} [formIdOrCallback] 폼 ID 또는 콜백
     * @param {Function} [callback]        완료 콜백
     */
    function fill(formData, formName = '', formIdOrCallback = null, callback = null) {
        if (!formData || Object.keys(formData).length === 0) return;

        let formId = '';
        let finalCallback = callback;

        if (typeof formIdOrCallback === 'function') {
            finalCallback = formIdOrCallback;
        } else if (typeof formIdOrCallback === 'string') {
            formId = formIdOrCallback;
        }

        let form = formId ? document.getElementById(formId) : null;
        if (!form) {
            form = document.querySelector('form');
            if (!form) return;
        }

        Object.entries(formData).forEach(([key, value]) => {
            const baseName = formName ? `${formName}[${key}]` : key;
            let inputs = [];
            [`[name="${baseName}"]`, `[name="${baseName}[]"]`].forEach(sel => {
                inputs = inputs.concat(Array.from(form.querySelectorAll(sel)));
            });

            if (inputs.length === 0) return;

            // text + [] + 다중 input → 자동 분해 처리
            if (
                inputs.length > 1 &&
                typeof value === 'string' &&
                inputs[0].name.endsWith('[]') &&
                inputs[0].type === 'text'
            ) {
                const delimiters = ['-', '.', '/'];
                let splitValues = [value];
                for (const d of delimiters) {
                    if (value.includes(d)) { splitValues = value.split(d); break; }
                }
                inputs.forEach((input, index) => {
                    input.value = splitValues[index] ?? '';
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
                return;
            }

            inputs.forEach(input => {
                let currentValue = value;
                if (input.classList.contains('mask-num') && input.type === 'text') {
                    currentValue = MubloCore.numberFormat(currentValue);
                }

                switch (input.type) {
                    case 'select-one': {
                        const option = input.querySelector(`option[value="${currentValue}"]`);
                        if (option) option.selected = true;
                        break;
                    }
                    case 'radio':
                        input.checked = input.value == currentValue;
                        break;
                    case 'checkbox': {
                        let checkValues = currentValue;
                        if (!Array.isArray(checkValues)) {
                            checkValues = typeof checkValues === 'string'
                                ? checkValues.split(',').map(v => v.trim())
                                : [String(checkValues)];
                        }
                        input.checked = checkValues.includes(input.value);
                        break;
                    }
                    default:
                        input.value = currentValue;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                }

                // 에디터 동기화
                if (typeof tinymce !== 'undefined' && tinymce.get && tinymce.get(input.id)) {
                    tinymce.get(input.id).setContent(currentValue);
                }
            });
        });

        if (typeof finalCallback === 'function') finalCallback();
    }

    /* =========================================================
     * 폼 검증
     * ========================================================= */

    function showValidationError(message, element) {
        MubloRequest.showAlert(message, 'warning');
        return element;
    }

    /**
     * .require 클래스 기반 폼 검증
     *
     * @param {HTMLFormElement} form
     * @returns {boolean}
     */
    function validate(form) {
        const EMAIL_REGEX = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const PHONE_REGEX = /^010-\d{4}-\d{4}$/;
        let firstInvalidElement = null;

        form.querySelectorAll('.require').forEach(element => {
            if (firstInvalidElement) return;

            const type = element.getAttribute('data-type');
            const message = element.getAttribute('data-message');
            const regex = element.getAttribute('data-regex');
            const value = element.value ? element.value.trim() : '';

            switch (type) {
                case 'text':
                    if (value === '' || (regex && !new RegExp(regex).test(value)))
                        firstInvalidElement = showValidationError(message, element);
                    break;
                case 'email':
                    if (!EMAIL_REGEX.test(value))
                        firstInvalidElement = showValidationError(message, element);
                    break;
                case 'phone':
                    if (!PHONE_REGEX.test(value))
                        firstInvalidElement = showValidationError(message, element);
                    break;
                case 'radio':
                case 'checkbox':
                case 'radio-class': {
                    const selector = type === 'radio-class'
                        ? `.${element.getAttribute('data-class')}:checked`
                        : `[name="${element.getAttribute('name')}"]:checked`;
                    if (!form.querySelector(selector))
                        firstInvalidElement = showValidationError(message, element);
                    break;
                }
                case 'select':
                    if (value === '')
                        firstInvalidElement = showValidationError(message, element);
                    break;
                default:
                    if (value === '')
                        firstInvalidElement = showValidationError(message, element);
            }
        });

        if (firstInvalidElement) {
            firstInvalidElement.focus();
            return false;
        }

        return true;
    }

    /* =========================================================
     * FormData → Object 변환
     * ========================================================= */

    /**
     * FormData를 Object로 변환 (동일 name 배열 변환)
     */
    function toObject(formData) {
        const obj = {};
        for (const [key, value] of formData.entries()) {
            const cleanKey = key.replace(/\[\]$/, '');
            if (obj[cleanKey]) {
                if (!Array.isArray(obj[cleanKey])) obj[cleanKey] = [obj[cleanKey]];
                obj[cleanKey].push(value);
            } else {
                obj[cleanKey] = value;
            }
        }
        return obj;
    }

    /* =========================================================
     * 폼 유틸리티 (select, radio, checkbox 동적 생성)
     * ========================================================= */

    const util = {
        clearSelect(selectId) {
            const select = document.getElementById(selectId);
            if (select) select.innerHTML = '<option value="">선택</option>';
        },

        populateSelect(selectId, options) {
            const select = document.getElementById(selectId);
            if (!select) return;
            select.innerHTML = '<option value="">선택</option>';
            options.forEach(option => {
                const opt = document.createElement('option');
                opt.value = option.value;
                opt.textContent = option.text;
                if (option.dataset) Object.entries(option.dataset).forEach(([k, v]) => opt.dataset[k] = v);
                select.appendChild(opt);
            });
        },

        populateRadio(containerId, name, options) {
            this._populateChoice(containerId, name, options, 'radio', 'radio-item');
        },

        populateCheckbox(containerId, name, options) {
            this._populateChoice(containerId, name, options, 'checkbox', 'checkbox-item');
        },

        // 라디오/체크박스 공통 생성 — value/text를 DOM 속성·textContent로 넣어 XSS를 원천 차단한다
        // (innerHTML 템플릿 보간 대신 createElement 사용).
        _populateChoice(containerId, name, options, inputType, wrapClass) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            options.forEach((option, idx) => {
                const id = `${name}_${idx}`;
                const wrap = document.createElement('div');
                wrap.className = wrapClass;

                const input = document.createElement('input');
                input.type = inputType;
                input.id = id;
                input.name = name;
                input.value = option.value;          // 속성 대입은 HTML로 해석되지 않음
                if (option.checked) input.checked = true;

                const label = document.createElement('label');
                label.setAttribute('for', id);
                label.textContent = option.text;      // 텍스트 노드로 삽입 → 스크립트 주입 불가

                wrap.appendChild(input);
                wrap.appendChild(label);
                container.appendChild(wrap);
            });
        }
    };

    /* =========================================================
     * 입력 마스크
     * ========================================================= */

    function formatMaskNumInput(input) {
        const decimalMatch = input.className.match(/decimal-(\d+)/);
        const decimalPlaces = decimalMatch ? parseInt(decimalMatch[1], 10) : 0;
        input.value = MubloCore.numberFormat(input.value, decimalPlaces);
    }

    function formatMaskHpInput(input) {
        input.value = MubloCore.phoneFormat(input.value);
    }

    /* =========================================================
     * 파일 미리보기
     * ========================================================= */

    function handleFilePreview(e) {
        if (!e.target || !e.target.matches('.frm-file input[type="file"]')) return;

        const wrap = e.target.closest('.file-wrap');
        if (!wrap || !e.target.files || !e.target.files[0]) return;

        const file = e.target.files[0];

        if (file.type && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function (ev) {
                const imgPreview = wrap.querySelector('.image-preview');
                if (imgPreview) imgPreview.setAttribute('src', ev.target.result);
                const imgBg = wrap.querySelector('.image-background');
                if (imgBg) imgBg.style.backgroundImage = 'url(' + ev.target.result + ')';
            };
            reader.readAsDataURL(file);
        } else {
            const imgPreview = wrap.querySelector('.image-preview');
            if (imgPreview) imgPreview.removeAttribute('src');
            const imgBg = wrap.querySelector('.image-background');
            if (imgBg) imgBg.style.backgroundImage = 'none';
        }

        const fileNameEl = wrap.querySelector('.file-name');
        if (fileNameEl) fileNameEl.textContent = file.name;
    }

    /* =========================================================
     * 초기화 (DOMContentLoaded)
     * ========================================================= */

    function init() {
        // ---- 초기 마스크 적용 (정적 input) ----
        document.querySelectorAll('input.mask-num').forEach(formatMaskNumInput);
        document.querySelectorAll('input.mask-hp').forEach(formatMaskHpInput);
        document.querySelectorAll('input.mask-birth').forEach(input => {
            input.dataset.prev = input.value;
            input.value = MubloCore.birthFormat(input.value);
        });

        // ---- 실시간 마스크 적용 (동적 포함, 이벤트 위임) ----
        document.addEventListener('input', function (e) {
            const t = e.target;

            // 휴대폰/전화번호
            if (t.matches('input.mask-phone, input.mask-tel, input.mask-fax, input.mask-hp')) {
                t.value = MubloCore.phoneFormat(t.value);
            }

            // 숫자 포맷
            if (t.matches('input.mask-num')) {
                let val = t.value.replace(/[^0-9.\-]/g, '');
                const neg = val.charAt(0) === '-';
                val = val.replace(/-/g, '');
                if (neg) val = '-' + val;
                const parts = val.split('.');
                if (parts[0].startsWith('-')) {
                    parts[0] = '-' + parts[0].substring(1).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                } else {
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                }
                t.value = parts.join('.');
            }

            // 생년월일
            if (t.matches('input.mask-birth')) {
                t.value = MubloCore.birthFormat(t.value);
                if (t.value.length === 10 && !MubloCore.isValidBirthDate(t.value)) {
                    MubloRequest.showAlert('잘못된 날짜입니다.\n다시 입력하세요.', 'warning');
                    t.value = '';
                    t.focus();
                }
            }

            // 숫자만 허용
            if (t.matches('input.number')) {
                const pos = t.selectionStart;
                t.value = t.value.replace(/[^0-9]/g, '');
                t.setSelectionRange(pos, pos);
            }

            // 숫자 포맷 (number-format)
            if (t.matches('input.number-format')) {
                const pos = t.selectionStart;
                if (t.value !== '') t.value = MubloCore.numberFormat(t.value);
                t.setSelectionRange(pos, pos);
            }
        });

        // blur 시 소수점 자릿수 확정
        document.addEventListener('blur', function (e) {
            if (e.target.matches('input.mask-num')) {
                const match = e.target.className.match(/decimal-(\d+)/);
                const places = match ? parseInt(match[1], 10) : 0;
                e.target.value = MubloCore.numberFormat(e.target.value, places);
            }
        }, true);

        // 생년월일 포커스 복구
        document.addEventListener('focusin', function (e) {
            if (e.target.matches('input.mask-birth')) e.target.dataset.prev = e.target.value;
        });
        document.addEventListener('focusout', function (e) {
            if (e.target.matches('input.mask-birth')) {
                const val = e.target.value;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(val) || !MubloCore.isValidBirthDate(val)) {
                    e.target.value = e.target.dataset.prev || '';
                }
            }
        });

        // 숫자만 입력 키 제한
        document.addEventListener('keydown', function (e) {
            if (!e.target.matches('input.number')) return;
            const allowed = ['Backspace', 'ArrowLeft', 'ArrowRight', 'Delete', 'Tab', 'Enter'];
            if (!allowed.includes(e.key) && !(e.key >= '0' && e.key <= '9')) e.preventDefault();
        });

        // ---- 파일 미리보기 ----
        document.addEventListener('change', handleFilePreview);
    }

    document.addEventListener('DOMContentLoaded', init);

    /* =========================================================
     * 외부 노출 API
     * ========================================================= */

    return {
        fill,
        validate,
        toObject,
        util,
    };
})();
