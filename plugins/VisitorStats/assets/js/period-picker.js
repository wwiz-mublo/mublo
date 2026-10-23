/**
 * VisitorStats — 기간 직접 지정
 *
 * 통계 화면의 기간 select(#vs-period / #cs-period / #cv-period)에 '기간 지정' 옵션과
 * 날짜 입력을 붙인다. 화면 스크립트는 select.value 를 그대로 API 로 보내므로, [적용]을
 * 누르면 이 옵션의 값을 'custom:시작일:종료일' 로 바꾸고 change 를 발생시킨다.
 * 서버 해석은 VisitorStatsService::periodToDates() 가 맡는다.
 */
(function () {
    'use strict';

    function ymd(date) {
        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');
    }

    function warn(message) {
        if (window.MubloRequest && MubloRequest.showAlert) {
            MubloRequest.showAlert(message, 'warning');
        } else {
            alert(message);
        }
    }

    function enhance(select) {
        var today = ymd(new Date());
        var weekAgoDate = new Date();
        weekAgoDate.setDate(weekAgoDate.getDate() - 6);
        var weekAgo = ymd(weekAgoDate);

        var option = document.createElement('option');
        option.textContent = '기간 지정';
        option.value = 'custom:' + weekAgo + ':' + today;
        select.appendChild(option);

        // select 를 감싸 날짜 입력을 옆에 둔다 — 부모 flex 배치(양끝 정렬 등)를 흐트리지 않도록
        var group = document.createElement('div');
        group.className = 'd-flex align-items-center gap-1';
        select.parentNode.insertBefore(group, select);
        group.appendChild(select);

        var picker = document.createElement('div');
        picker.className = 'align-items-center gap-1 d-none';
        picker.innerHTML =
            '<input type="date" class="form-control form-control-sm" style="width:auto;" aria-label="시작일">' +
            '<span class="text-muted">~</span>' +
            '<input type="date" class="form-control form-control-sm" style="width:auto;" aria-label="종료일">' +
            '<button type="button" class="btn btn-sm btn-default text-nowrap">적용</button>';
        group.appendChild(picker);

        var inputs = picker.querySelectorAll('input');
        var startInput = inputs[0];
        var endInput = inputs[1];
        startInput.value = weekAgo;
        endInput.value = today;
        startInput.max = today;
        endInput.max = today;

        select.addEventListener('change', function () {
            var isCustom = select.value === option.value;
            picker.classList.toggle('d-none', !isCustom);
            picker.classList.toggle('d-flex', isCustom);
        });

        picker.querySelector('button').addEventListener('click', function () {
            if (!startInput.value || !endInput.value) {
                warn('시작일과 종료일을 모두 선택해주세요.');
                return;
            }
            if (startInput.value > endInput.value) {
                warn('시작일이 종료일보다 늦습니다.');
                return;
            }

            // 서버와 같은 양 끝 날짜 포함 366일 제한. UTC 날짜 연산으로 DST 영향을 피한다.
            var earliestDate = new Date(endInput.value + 'T00:00:00Z');
            earliestDate.setUTCDate(earliestDate.getUTCDate() - 365);
            var earliest = earliestDate.toISOString().slice(0, 10);
            if (startInput.value < earliest) {
                startInput.value = earliest;
                warn('조회 기간은 최대 366일입니다. 시작일을 ' + earliest + '로 조정했습니다.');
            }

            option.value = 'custom:' + startInput.value + ':' + endInput.value;
            select.value = option.value;
            select.dispatchEvent(new Event('change'));
        });
    }

    document.querySelectorAll('#vs-period, #cs-period, #cv-period').forEach(enhance);
})();
