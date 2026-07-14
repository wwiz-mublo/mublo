/**
 * MubloPasswordPolicy
 *
 * 도메인 비밀번호 정책의 클라이언트 실시간 검증(UX 보조).
 * 서버 PasswordPolicy(PHP)가 authoritative이며, 이 스크립트는 규칙을 1:1로 미러링한다.
 *
 * 판정만 담당한다 — 메시지 표시(위치·색·시점)는 각 스킨의 몫이다.
 *
 * 사용:
 *   <input type="password" data-pw-min="8" data-pw-lower="1" data-pw-number="1" data-pw-special="0">
 *
 *   var r = MubloPasswordPolicy.validate(input);
 *   // r = { valid: true|false, message: '...' }
 */
(function () {
    'use strict';

    function check(v, min, needLower, needNumber, needSpecial) {
        if (Array.from(v).length < min) {
            return '비밀번호는 최소 ' + min + '자 이상이어야 합니다.';
        }
        if (needLower && !/[a-z]/.test(v)) {
            return '비밀번호에 영문 소문자를 포함해야 합니다.';
        }
        if (needNumber && !/[0-9]/.test(v)) {
            return '비밀번호에 숫자를 포함해야 합니다.';
        }
        if (needSpecial && !/[^\p{L}\p{N}\s]/u.test(v)) {
            return '비밀번호에 특수문자를 포함해야 합니다.';
        }
        return true;
    }

    window.MubloPasswordPolicy = {
        /**
         * input의 data-pw-* 속성 기준으로 현재 값을 판정한다.
         * @param {HTMLInputElement} input
         * @returns {{valid: boolean, message: string}}
         */
        validate: function (input) {
            var min = parseInt(input.getAttribute('data-pw-min') || '6', 10);
            var needLower = input.getAttribute('data-pw-lower') === '1';
            var needNumber = input.getAttribute('data-pw-number') === '1';
            var needSpecial = input.getAttribute('data-pw-special') === '1';

            var r = check(input.value, min, needLower, needNumber, needSpecial);
            return r === true
                ? { valid: true, message: '사용 가능한 비밀번호입니다.' }
                : { valid: false, message: r };
        }
    };
})();
