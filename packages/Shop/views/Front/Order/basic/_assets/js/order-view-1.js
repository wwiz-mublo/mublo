/**
 * 주문 상세 (basic 스킨) 뷰 스크립트 #1.
 */
(function() {
    'use strict';
    var overlay = document.getElementById('spvRvOverlay');
    if (!overlay) return;

    // .mublo-main(z-index:1)이 stacking context를 만들어 모달이 헤더/푸터
    // 아래에 갇힘 → body 직속으로 옮겨 전역 최상위로 띄운다.
    document.body.appendChild(overlay);

    var form = document.getElementById('spvRvForm');
    var rating = 5;

    function paint(v) {
        document.querySelectorAll('#spvRvStars .spv-rv-star').forEach(function(s) {
            s.classList.toggle('on', Number(s.dataset.v) <= v);
        });
    }
    function setRating(v) {
        rating = v;
        paint(v);
    }
    setRating(5);

    var starsBox = document.getElementById('spvRvStars');
    document.querySelectorAll('#spvRvStars .spv-rv-star').forEach(function(s) {
        s.addEventListener('click', function() { setRating(Number(this.dataset.v)); });
        s.addEventListener('mouseenter', function() { paint(Number(this.dataset.v)); });
    });
    starsBox.addEventListener('mouseleave', function() { paint(rating); });

    function openModal(detailId, goodsName) {
        document.getElementById('spvRvDetailId').value = detailId;
        document.getElementById('spvRvProduct').textContent = goodsName || '';
        setRating(5);
        form.reset();
        document.getElementById('spvRvDetailId').value = detailId;
        for (var i = 1; i <= 3; i++) resetSlot(i);
        overlay.classList.add('is-open');
        lockScroll(true);
    }
    function closeModal() {
        overlay.classList.remove('is-open');
        lockScroll(false);
    }

    var _scrollLocked = false;
    function lockScroll(on) {
        if (on === _scrollLocked) return;
        _scrollLocked = on;
        if (on) {
            var sbw = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (sbw > 0) document.body.style.paddingRight = sbw + 'px';
        } else {
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }

    function resetSlot(i) {
        var slot = document.getElementById('spvRvSlot' + i);
        slot.innerHTML = '<i class="bi bi-plus-lg" style="color:#9ca3af;font-size:1.4rem"></i>'
            + '<input type="file" accept="image/*" data-slot="' + i + '">';
        bindSlot(slot.querySelector('input'));
    }
    function bindSlot(input) {
        input.addEventListener('change', function() {
            var f = this.files && this.files[0];
            if (!f) return;
            var i = this.dataset.slot;
            var reader = new FileReader();
            reader.onload = function(e) {
                var slot = document.getElementById('spvRvSlot' + i);
                slot.innerHTML = '<img src="' + e.target.result + '" alt="">';
                slot.appendChild(input);
            };
            reader.readAsDataURL(f);
        });
    }
    document.querySelectorAll('#spvRvForm input[type=file]').forEach(bindSlot);

    // 후기 작성 버튼은 data-review-detail 로 찾는다.
    // shop-order-view__review-btn 은 이 줄의 버튼들이 공유하는 표현용 클래스라
    // 동작 선택자로 쓰면 나중에 붙는 버튼(교환 신청 등)까지 후기 모달을 연다.
    document.querySelectorAll('[data-review-detail]:not([disabled])').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal(this.dataset.reviewDetail, this.dataset.goodsName);
        });
    });

    document.getElementById('spvRvCancel').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var content = document.getElementById('spvRvContent').value.trim();
        if (content.length < 10) {
            MubloRequest.showAlert('후기 내용을 10자 이상 입력해주세요.', 'warning');
            return;
        }

        var fd = new FormData();
        fd.append('formData[order_detail_id]', document.getElementById('spvRvDetailId').value);
        fd.append('formData[rating]', rating);
        fd.append('formData[content]', content);
        var slot = 0;
        document.querySelectorAll('#spvRvForm input[type=file]').forEach(function(inp) {
            slot++;
            if (inp.files && inp.files[0]) {
                fd.append('fileData[image' + slot + ']', inp.files[0]);
            }
        });

        MubloRequest.sendRequest({
            method: 'POST',
            url: '/shop/reviews/store',
            payloadType: MubloRequest.PayloadType.FORM,
            data: fd,
            loading: true
        }).then(function(res) {
            MubloRequest.showToast(res.message || '후기가 등록되었습니다.', 'success');
            closeModal();
            setTimeout(function() { location.reload(); }, 600);
        }).catch(function() {});
    });
})();
