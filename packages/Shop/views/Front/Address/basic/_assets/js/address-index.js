/**
 * 배송지 관리 (basic 스킨) 뷰 스크립트.
 *
 * 저장·삭제·기본 설정 뒤에는 서버가 그린 목록을 다시 받도록 새로 고친다.
 */
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };
    var form = $('addressForm');
    var addBtn = $('addressAddBtn');

    function openForm(address) {
        var a = address || {};
        $('aAddressId').value = a.address_id || 0;
        $('aAddressName').value = a.address_name || '';
        $('aRecipient').value = a.recipient_name || '';
        $('aPhone').value = a.recipient_phone || '';
        $('aZip').value = a.zip_code || '';
        $('aAddress1').value = a.address1 || '';
        $('aAddress2').value = a.address2 || '';
        $('aIsDefault').checked = !!a.is_default;
        // 기본 배송지는 수정 화면에서 해제할 수 없다 — 다른 배송지를 기본으로 정하면 바뀐다
        $('aDefaultWrap').hidden = !!a.is_default;
        $('addressFormTitle').textContent = a.address_id ? '배송지 수정' : '새 배송지';
        form.hidden = false;
        if (addBtn) addBtn.hidden = true;
        $('aRecipient').focus();
    }

    function closeForm() {
        form.hidden = true;
        if (addBtn) addBtn.hidden = false;
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () { openForm(null); });
    }
    $('addressCancelBtn').addEventListener('click', closeForm);

    $('aZipBtn').addEventListener('click', function () {
        new daum.Postcode({
            oncomplete: function (data) {
                $('aZip').value = data.zonecode;
                $('aAddress1').value = data.roadAddress || data.jibunAddress;
                $('aAddress2').focus();
            }
        }).open();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var addressId = parseInt($('aAddressId').value, 10) || 0;
        var payload = {
            address_name: $('aAddressName').value.trim(),
            recipient_name: $('aRecipient').value.trim(),
            recipient_phone: $('aPhone').value.trim(),
            zip_code: $('aZip').value.trim(),
            address1: $('aAddress1').value.trim(),
            address2: $('aAddress2').value.trim()
        };

        if (!payload.recipient_name) { MubloRequest.showAlert('받는 분을 입력해주세요.', 'warning'); return; }
        if (!payload.zip_code || !payload.address1) { MubloRequest.showAlert('주소 검색으로 주소를 입력해주세요.', 'warning'); return; }

        var makeDefault = $('aIsDefault').checked && !$('aDefaultWrap').hidden;
        var request;
        if (addressId > 0) {
            payload.address_id = addressId;
            request = MubloRequest.requestJson('/shop/address/update', payload).then(function () {
                return makeDefault
                    ? MubloRequest.requestJson('/shop/address/default', { address_id: addressId })
                    : null;
            });
        } else {
            payload.is_default = makeDefault ? 1 : 0;
            request = MubloRequest.requestJson('/shop/address/store', payload);
        }
        request.then(function () { location.reload(); });
    });

    var list = $('addressList');
    if (!list) return;

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');

        if (action === 'edit') {
            openForm(JSON.parse(btn.getAttribute('data-address')));
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (action === 'default') {
            MubloRequest.requestJson('/shop/address/default', { address_id: parseInt(btn.getAttribute('data-id'), 10) })
                .then(function () { location.reload(); });
        }

        if (action === 'delete') {
            var id = parseInt(btn.getAttribute('data-id'), 10);
            MubloRequest.showConfirm('이 배송지를 삭제하시겠습니까?', function () {
                MubloRequest.requestJson('/shop/address/delete', { address_id: id })
                    .then(function () { location.reload(); });
            }, { type: 'warning' });
        }
    });
})();
