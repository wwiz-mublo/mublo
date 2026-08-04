/**
 * ============================================================
 * MubloAddress.js - Mublo Framework 주소 검색
 * ============================================================
 *
 * 다음(카카오) 우편번호 API를 활용한 주소 검색 기능.
 * 필요한 페이지에서만 로드한다.
 *
 * API:
 *   MubloAddress.search(fieldId)
 *   MubloAddress.open(formName, zipField, addr1Field, addr2Field, addr3Field, jibeonField)
 *
 * ============================================================
 */

const MubloAddress = (() => {
    'use strict';

    /**
     * 다음 우편번호 스크립트 동적 로드
     */
    function loadPostcodeScript(callback) {
        if (typeof kakao !== 'undefined' && kakao.Postcode) {
            callback();
            return;
        }

        const script = document.createElement('script');
        script.src = '//t1.kakaocdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
        script.async = true;
        script.onload = callback;
        script.onerror = () => alert('주소 검색 서비스를 일시적으로 사용할 수 없습니다.\n잠시 후 다시 시도해주세요.');
        document.head.appendChild(script);
    }

    /**
     * 간편 주소 검색 (ID 기반)
     *
     * 회원 추가 필드 등에서 사용하는 패턴.
     * field_{fieldId}_zipcode, field_{fieldId}_address1, field_{fieldId}_address2 를 자동으로 채운다.
     *
     * @param {string|number} fieldId 필드 ID (예: 3 → field_3_zipcode)
     */
    function search(fieldId) {
        loadPostcodeScript(() => {
            try {
                new kakao.Postcode({
                    oncomplete: function (data) {
                        const zipEl = document.getElementById('field_' + fieldId + '_zipcode');
                        const addr1El = document.getElementById('field_' + fieldId + '_address1');
                        const addr2El = document.getElementById('field_' + fieldId + '_address2');

                        if (zipEl) zipEl.value = data.zonecode;
                        if (addr1El) addr1El.value = data.roadAddress || data.jibunAddress;
                        if (addr2El) addr2El.focus();

                        // change 이벤트 발행
                        [zipEl, addr1El].forEach(el => {
                            if (el) el.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    }
                }).open();
            } catch (error) {
                alert('주소 검색창을 열지 못했습니다. 다시 시도해주세요.');
            }
        });
    }

    /**
     * 범용 주소 검색 (form name + field name 기반)
     *
     * @param {string} formName    폼의 name 속성
     * @param {string} zipField    우편번호 필드 name
     * @param {string} addr1Field  기본주소 필드 name
     * @param {string} addr2Field  상세주소 필드 name
     * @param {string} [addr3Field]  추가주소 필드 name (선택)
     * @param {string} [jibeonField] 도로명/지번 구분 필드 name (선택)
     */
    function open(formName, zipField, addr1Field, addr2Field, addr3Field, jibeonField) {
        loadPostcodeScript(() => {
            const form = document.forms[formName];
            if (!form) { alert('폼을 찾을 수 없습니다.'); return; }

            const zipEl   = form.elements[zipField];
            const addr1El = form.elements[addr1Field];
            const addr2El = form.elements[addr2Field];
            const addr3El = addr3Field  ? form.elements[addr3Field]  : null;
            const jibeonEl= jibeonField ? form.elements[jibeonField] : null;

            if (!zipEl || !addr1El || !addr2El) {
                alert('주소 입력 필드가 올바르지 않습니다.');
                return;
            }

            try {
                new kakao.Postcode({
                    oncomplete: function (data) {
                        let fullAddr = data.userSelectedType === 'R' ? data.roadAddress : data.jibunAddress;
                        let extraAddr = '';

                        if (data.userSelectedType === 'R') {
                            if (data.bname) extraAddr += data.bname;
                            if (data.buildingName) extraAddr += (extraAddr ? ', ' + data.buildingName : data.buildingName);
                            if (extraAddr) extraAddr = ` (${extraAddr})`;
                        }

                        zipEl.value   = data.zonecode;
                        addr1El.value = fullAddr;
                        if (addr3El)  addr3El.value  = extraAddr;
                        if (jibeonEl) jibeonEl.value = data.userSelectedType;

                        setTimeout(() => {
                            addr2El.focus();
                            [zipEl, addr1El].forEach(el => el.dispatchEvent(new Event('change', { bubbles: true })));
                        }, 100);
                    }
                }).open();
            } catch (error) {
                alert('주소 검색창을 열지 못했습니다. 다시 시도해주세요.');
            }
        });
    }

    /* =========================================================
     * 외부 노출 API
     * ========================================================= */

    return {
        search,
        open,
    };
})();
