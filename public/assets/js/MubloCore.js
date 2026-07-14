/**
 * ============================================================
 * MubloCore.js - Mublo Framework 순수 유틸리티
 * ============================================================
 *
 * 포맷팅, 숫자 변환, 날짜 등 순수 함수를 제공한다.
 * DOM 조작 없이 입력 → 출력만 수행하는 함수들로 구성.
 *
 * API:
 *   MubloCore.safeInt(v)
 *   MubloCore.parseNumber(val)
 *   MubloCore.numberFormat(number, decimals, decPoint, thousandsSep)
 *   MubloCore.phoneFormat(value)
 *   MubloCore.birthFormat(value)
 *   MubloCore.isValidBirthDate(dateStr)
 *   MubloCore.formatDateYMD(date)
 *
 * ============================================================
 */

const MubloCore = (() => {

    /* =========================================================
     * 숫자 변환
     * ========================================================= */

    /**
     * 안전한 정수 변환 (NaN → 0)
     */
    function safeInt(v) {
        const n = parseInt(v);
        return isNaN(n) ? 0 : n;
    }

    /**
     * 쉼표·원 단위 등을 제거하고 숫자로 변환
     */
    function parseNumber(val) {
        if (!val) return 0;
        return Number(String(val).replace(/[^0-9.-]/g, '')) || 0;
    }

    /* =========================================================
     * 포맷팅
     * ========================================================= */

    /**
     * 숫자를 포맷팅하여 문자열로 반환 (천단위 구분, 소수점 유지)
     *
     * @param {number|string} number   포맷팅할 숫자
     * @param {number}        decimals 소수점 자릿수 (기본 0)
     * @param {string}        decPoint      소수점 구분자 (기본 '.')
     * @param {string}        thousandsSep  천 단위 구분자 (기본 ',')
     * @returns {string}
     */
    function numberFormat(number, decimals = 0, decPoint = '.', thousandsSep = ',') {
        const isNegative = (number + '').charAt(0) === '-';
        number = (number + '').replace(/[^0-9+\-Ee.]/g, '');

        if (number === '.' || /\.$/.test(number)) return number;

        let n = !isFinite(+number) ? 0 : +number;
        let prec = !isFinite(+decimals) ? 0 : Math.abs(decimals);
        const hasDecimal = (number + '').includes('.');
        const originalStr = Math.abs(n).toString();
        const originalDecimalPart = hasDecimal ? (Math.abs(+number).toString().split('.')[1] || '') : '';

        function toFixedFix(n, prec) {
            let k = Math.pow(10, prec);
            return '' + Math.round(Math.abs(n) * k) / k;
        }

        let s;
        if (hasDecimal && prec === 0) {
            s = originalStr.split('.');
        } else if (hasDecimal && prec > 0) {
            s = toFixedFix(n, prec).split('.');
        } else {
            s = (prec ? toFixedFix(n, prec) : '' + Math.round(Math.abs(n))).split('.');
        }

        if (s[0].length > 3) {
            s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, thousandsSep);
        }

        if (hasDecimal && prec === 0) {
            s[1] = originalDecimalPart;
        } else if (prec > 0) {
            s[1] = s[1] || '';
            s[1] += new Array(prec - s[1].length + 1).join('0');
        }

        const result = s[1] !== undefined ? s.join(decPoint) : s[0];
        return isNegative ? '-' + result : result;
    }

    /**
     * 휴대폰 번호를 010-1234-5678 형식으로 변환
     */
    function phoneFormat(value) {
        value = value.replace(/\D/g, '');
        if (value.length > 11) value = value.slice(0, 11);

        if (value.length <= 3) return value;
        if (value.length <= 7) return value.replace(/(\d{3})(\d+)/, '$1-$2');
        return value.replace(/(\d{3})(\d{4})(\d+)/, '$1-$2-$3');
    }

    /**
     * 생년월일을 YYYY-MM-DD 형식으로 변환
     */
    function birthFormat(value) {
        const onlyDigits = value.replace(/\D/g, '');
        if (onlyDigits.length <= 4) return onlyDigits;
        if (onlyDigits.length <= 6) return onlyDigits.replace(/(\d{4})(\d{1,2})/, '$1-$2');
        return onlyDigits.replace(/(\d{4})(\d{2})(\d{1,2})/, '$1-$2-$3');
    }

    /**
     * 생년월일 유효성 검사
     */
    function isValidBirthDate(dateStr) {
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return false;
        const [yyyy, mm, dd] = dateStr.split('-').map(Number);
        if (yyyy === 0 || mm === 0 || dd === 0) return false;
        return dd <= new Date(yyyy, mm, 0).getDate();
    }

    /**
     * Date 객체를 YYYY-MM-DD 문자열로 변환
     */
    function formatDateYMD(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    /* =========================================================
     * 외부 노출 API
     * ========================================================= */

    return {
        safeInt,
        parseNumber,
        numberFormat,
        phoneFormat,
        birthFormat,
        isValidBirthDate,
        formatDateYMD,
    };
})();
