/**
 * Survey 프론트 동작
 *
 * 설문 ID는 #sv-form[data-survey-id] 에서 읽는다.
 * 의존: MubloRequest (전역)
 */
(function () {
    /* 라디오/체크박스 커스텀 스타일 */
    document.querySelectorAll('.sv-choice input[type="radio"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var name = this.name;
            document.querySelectorAll('.sv-choice input[name="' + name + '"]').forEach(function (r) {
                r.closest('.sv-choice').classList.toggle('checked', r.checked);
            });
        });
    });
    document.querySelectorAll('.sv-choice input[type="checkbox"]').forEach(function (input) {
        input.addEventListener('change', function () {
            this.closest('.sv-choice').classList.toggle('checked', this.checked);
        });
    });

    /* 별점 */
    document.querySelectorAll('.sv-rating').forEach(function (ratingEl) {
        var stars   = ratingEl.querySelectorAll('.sv-star');
        var qid     = ratingEl.dataset.qid;
        var hidden  = document.querySelector('.sv-rating-val[name="q_' + qid + '"]');
        var label   = ratingEl.querySelector('.sv-rating-label');
        var labels  = ['', '별로예요', '그저 그래요', '보통이에요', '좋아요', '매우 좋아요'];
        var current = 0;

        function paint(upTo, hover) {
            stars.forEach(function (s, i) {
                s.classList.toggle('on',  !hover && i < current);
                s.classList.toggle('hov', hover  && i < upTo);
            });
        }
        stars.forEach(function (star, idx) {
            star.addEventListener('mouseenter', function () { paint(idx + 1, true); });
            star.addEventListener('mouseleave', function () { paint(0, false); });
            star.addEventListener('click', function () {
                current = idx + 1;
                if (hidden) hidden.value = current;
                paint(0, false);
                if (label) label.textContent = labels[current] || '';
            });
        });
    });

    /* 폼 제출 */
    var form = document.getElementById('sv-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var answers = {};

        form.querySelectorAll('[name^="q_"]').forEach(function (el) {
            if (el.type === 'radio'  && !el.checked) return;
            if (el.type === 'hidden' && !el.value)   return;
            if (el.classList.contains('sv-cb'))         return;
            if (el.classList.contains('sv-rating-val')) return;
            var qid = el.name.replace('q_', '');
            if (el.value !== '') answers[qid] = el.value;
        });
        form.querySelectorAll('.sv-cb:checked').forEach(function (el) {
            var qid = el.dataset.qid;
            if (!answers[qid]) answers[qid] = [];
            answers[qid].push(parseInt(el.value));
        });
        form.querySelectorAll('.sv-rating-val').forEach(function (el) {
            var qid = el.name.replace('q_', '');
            if (el.value) answers[qid] = parseInt(el.value);
        });

        var btn = document.getElementById('sv-submit');
        btn.disabled = true;
        btn.classList.add('loading');

        MubloRequest.requestJson('/survey/' + form.dataset.surveyId + '/submit', { answers: answers })
            .then(function (res) {
                /* 같은 영역 안에서 form → 완료 패널로 교체 */
                form.style.display = 'none';
                document.getElementById('sv-done').style.display = '';

                /* 참여자 수 +1 반영 */
                var countEl = document.getElementById('sv-count');
                if (countEl) {
                    var n = parseInt(countEl.textContent.replace(/,/g, ''), 10) || 0;
                    countEl.textContent = (n + 1).toLocaleString();
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.classList.remove('loading');
            });
    });
})();
