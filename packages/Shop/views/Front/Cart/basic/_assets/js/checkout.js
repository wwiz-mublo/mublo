/**
 * 체크아웃 (basic 스킨) 뷰 스크립트 — 결제 플로우.
 * 서버데이터는 뷰의 <script type="application/json" id="checkout-data"> 아일랜드에서 로드.
 * PG 스크립트($pgScript)·다음 우편번호 CDN은 뷰에 인라인 유지(동적/외부).
 */
var __d = (function () {
    try { return JSON.parse(document.getElementById('checkout-data').textContent || '{}'); }
    catch (e) { return {}; }
})();
(function() {
    var $ = function(id) { return document.getElementById(id); };
    var isGuest = __d.isGuest;

    // 모달 오버레이를 body 직속으로 이동 (.mublo-main의 stacking context 탈출)
    ['couponModalOverlay', 'addressModalOverlay'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentElement !== document.body) document.body.appendChild(el);
    });

    // ============================
    // 체크아웃 모달 시스템 (쿠폰 / 배송지 / 테스트결제 공통)
    // .is-open 토글 + ref-count 스크롤락(스크롤바 폭 보정) + ESC/백드롭 클릭 닫기를 일원화한다.
    // 각 모달이 제각각 굴리던 오버레이 토글·닫기 로직을 여기로 통합.
    // ============================
    var CheckoutModal = (function() {
        var MODAL_IDS = ['couponModalOverlay', 'addressModalOverlay'];
        var lockCount = 0;

        function lockScroll() {
            if (lockCount === 0) {
                var sbw = window.innerWidth - document.documentElement.clientWidth;
                document.body.style.overflow = 'hidden';
                if (sbw > 0) document.body.style.paddingRight = sbw + 'px';
            }
            lockCount++;
        }
        function unlockScroll() {
            lockCount = Math.max(0, lockCount - 1);
            if (lockCount === 0) {
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        }
        function open(id, onClose) {
            var el = document.getElementById(id);
            if (!el || el.classList.contains('is-open')) return;
            el._onClose = typeof onClose === 'function' ? onClose : null;
            el.classList.add('is-open');
            lockScroll();
        }
        function close(id, skipCallback) {
            var el = document.getElementById(id);
            if (!el || !el.classList.contains('is-open')) return;
            el.classList.remove('is-open');
            unlockScroll();
            var cb = el._onClose;
            el._onClose = null;
            if (cb && !skipCallback) cb();
        }

        // 백드롭(오버레이 자체) 클릭 → 닫기 / ESC → 최상위 열린 모달 닫기 (한 곳에서 처리)
        MODAL_IDS.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('click', function(e) { if (e.target === el) close(id); });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            for (var i = MODAL_IDS.length - 1; i >= 0; i--) {
                var el = document.getElementById(MODAL_IDS[i]);
                if (el && el.classList.contains('is-open')) { close(MODAL_IDS[i]); break; }
            }
        });

        return { open: open, close: close };
    })();

    // ============================
    // 다음 우편번호 공용 함수
    // ============================
    function openPostcode(zipEl, address1El, focusEl, onDone) {
        new daum.Postcode({
            oncomplete: function(data) {
                zipEl.value = data.zonecode;
                address1El.value = data.roadAddress || data.jibunAddress;
                if (focusEl) focusEl.focus();
                if (typeof onDone === 'function') onDone();
            }
        }).open();
    }

    $('btnZipMain').addEventListener('click', function() {
        openPostcode($('shippingZip'), $('shippingAddress1'), $('shippingAddress2'), recalcShipping);
    });

    // 우편번호가 외부(서버 프리셋/자동입력 스크립트 등)에서 바뀌어도 표시를 갱신.
    // 단, JS 직접 value 대입은 change 를 안 띄우므로, 그런 코드는 값 세팅 후
    // shippingZip.dispatchEvent(new Event('change')) 를 호출해야 트리거된다.
    $('shippingZip').addEventListener('change', recalcShipping);

    // 배송지 = 주문인 정보 (회원·비회원 공통): 체크 시 복사, 해제 시 비우기
    $('chkSameAsOrderer').addEventListener('change', function() {
        if (this.checked) {
            $('recipientName').value = $('ordererName').value;
            $('recipientPhone').value = $('ordererPhone').value;
        } else {
            $('recipientName').value = '';
            $('recipientPhone').value = '';
        }
    });

    // ============================
    // 모달 열기/닫기 (회원 전용)
    // ============================
    if (!isGuest) {
    $('btnOpenAddressModal').addEventListener('click', function() {
        CheckoutModal.open('addressModalOverlay', hideForm);
        loadAddressList();
    });

    $('addressModalClose').addEventListener('click', closeModal);

    } // end !isGuest

    function hideForm() {
        var form = $('addressForm');
        var toggle = $('addressFormToggle');
        if (form) form.style.display = 'none';
        if (toggle) toggle.style.display = '';
    }

    function closeModal() {
        CheckoutModal.close('addressModalOverlay');
    }

    // ============================
    // 모달 폼: 새 배송지 / 수정 (회원 전용)
    // ============================
    if (!isGuest) {
    $('addressFormToggle').addEventListener('click', function() {
        resetForm();
        $('addressFormTitle').textContent = '새 배송지 추가';
        $('addressForm').style.display = '';
        this.style.display = 'none';
    });

    $('addressFormCancel').addEventListener('click', hideForm);

    $('btnZipModal').addEventListener('click', function() {
        openPostcode($('mZip'), $('mAddress1'), $('mAddress2'));
    });

    function resetForm() {
        $('mAddressId').value = 0;
        $('mAddressName').value = '';
        $('mRecipient').value = '';
        $('mPhone').value = '';
        $('mZip').value = '';
        $('mAddress1').value = '';
        $('mAddress2').value = '';
        $('mIsDefault').checked = false;
    }

    function fillModalForm(address) {
        $('mAddressId').value = address.address_id;
        $('mAddressName').value = address.address_name || '';
        $('mRecipient').value = address.recipient_name || '';
        $('mPhone').value = address.recipient_phone || '';
        $('mZip').value = address.zip_code || '';
        $('mAddress1').value = address.address1 || '';
        $('mAddress2').value = address.address2 || '';
        $('mIsDefault').checked = !!address.is_default;
    }

    // ============================
    // 저장 (추가 or 수정)
    // ============================
    $('addressFormSave').addEventListener('click', function() {
        var addressId = parseInt($('mAddressId').value) || 0;
        var recipient = $('mRecipient').value.trim();
        var zip = $('mZip').value.trim();
        var address1 = $('mAddress1').value.trim();

        if (!recipient) { MubloRequest.showAlert('수령인을 입력해주세요.', 'warning'); return; }
        if (!zip || !address1) { MubloRequest.showAlert('우편번호와 주소를 입력해주세요.', 'warning'); return; }

        var payload = {
            address_name: $('mAddressName').value.trim(),
            recipient_name: recipient,
            recipient_phone: $('mPhone').value.trim(),
            zip_code: zip,
            address1: address1,
            address2: $('mAddress2').value.trim(),
            is_default: $('mIsDefault').checked ? 1 : 0
        };

        if (addressId > 0) {
            payload.address_id = addressId;
            MubloRequest.requestJson('/shop/address/update', payload).then(function() {
                loadAddressList();
                hideForm();
            });
        } else {
            MubloRequest.requestJson('/shop/address/store', payload).then(function() {
                loadAddressList();
                hideForm();
            });
        }
    });

    // ============================
    // 주소 목록 로드 (AJAX)
    // ============================
    function loadAddressList() {
        MubloRequest.requestQuery('/shop/address/list').then(function(res) {
            var list = (res.data || {}).addresses || [];
            renderList(list);
        });
    }

    function renderList(list) {
        var container = $('addressList');
        if (!list.length) {
            container.innerHTML = '<div class="shop-checkout__address-modal-empty">저장된 배송지가 없습니다.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < list.length; i++) {
            var a = list[i];
            html += '<div class="shop-checkout__address-modal-card" data-id="' + a.address_id + '">';
            html += '<div class="shop-checkout__address-modal-card-top">';
            html += '<span class="shop-checkout__address-modal-card-name">' + esc(a.address_name || a.recipient_name) + '</span>';
            if (a.is_default) html += ' <span class="shop-checkout__address-modal-card-badge">기본</span>';
            html += '</div>';
            html += '<div class="shop-checkout__address-modal-card-info">';
            html += esc(a.recipient_name) + ' / ' + esc(a.recipient_phone) + '<br>';
            html += '[' + esc(a.zip_code) + '] ' + esc(a.address1) + ' ' + esc(a.address2);
            html += '</div>';
            html += '<div class="shop-checkout__address-modal-card-actions">';
            html += '<button class="shop-checkout__address-modal-card-btn shop-checkout__address-modal-card-btn--primary" data-action="select" data-address=\'' + esc(JSON.stringify(a)) + '\'>선택</button>';
            html += '<button class="shop-checkout__address-modal-card-btn" data-action="edit" data-address=\'' + esc(JSON.stringify(a)) + '\'>수정</button>';
            if (!a.is_default) html += '<button class="shop-checkout__address-modal-card-btn shop-checkout__address-modal-card-btn--green" data-action="default" data-id="' + a.address_id + '">기본설정</button>';
            html += '<button class="shop-checkout__address-modal-card-btn shop-checkout__address-modal-card-btn--danger" data-action="delete" data-id="' + a.address_id + '">삭제</button>';
            html += '</div></div>';
        }
        container.innerHTML = html;
    }

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // ============================
    // 목록 카드 버튼 이벤트 (위임)
    // ============================
    $('addressList').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');

        if (action === 'select') {
            var address = JSON.parse(btn.getAttribute('data-address'));
            // 체크아웃 폼에 적용
            $('recipientName').value = address.recipient_name || '';
            $('recipientPhone').value = address.recipient_phone || '';
            $('shippingZip').value = address.zip_code || '';
            $('shippingAddress1').value = address.address1 || '';
            $('shippingAddress2').value = address.address2 || '';
            $('chkSetDefault').checked = !!address.is_default;
            closeModal();
            recalcShipping();
        }

        if (action === 'edit') {
            var address = JSON.parse(btn.getAttribute('data-address'));
            fillModalForm(address);
            $('addressFormTitle').textContent = '배송지 수정';
            $('addressForm').style.display = '';
            $('addressFormToggle').style.display = 'none';
        }

        if (action === 'default') {
            var id = btn.getAttribute('data-id');
            MubloRequest.requestJson('/shop/address/default', { address_id: parseInt(id) }).then(function() {
                loadAddressList();
            });
        }

        if (action === 'delete') {
            var id = btn.getAttribute('data-id');
            MubloRequest.showConfirm('이 배송지를 삭제하시겠습니까?', function() {
                MubloRequest.requestJson('/shop/address/delete', { address_id: parseInt(id) }).then(function() {
                    loadAddressList();
                });
            }, { type: 'warning' });
        }
    });

    } // end if (!isGuest) — 모달/주소 관련 코드 끝

    // ============================
    // 쿠폰 적용
    // ============================
    var selectedCoupons = [];
    var originalGrandTotal = __d.totals.grandTotal;
    var originalProductTotal = __d.totals.productTotal;
    var originalShippingFee = __d.totals.shippingFee;

    // 합계 공유 상태 (쿠폰 + 포인트) — 결제금액 = 원금 - 쿠폰할인 - 포인트사용
    var appliedCouponDiscount = 0;
    var appliedPointUsed = 0;
    var reclampPoint = function() {}; // 포인트 사용 활성 시 실제 클램프 함수로 교체됨

    function recalcSummary() {
        var couponRow = $('summCouponRow');
        if (couponRow) {
            if (appliedCouponDiscount > 0) {
                couponRow.style.display = '';
                $('summCouponAmount').textContent = '-' + appliedCouponDiscount.toLocaleString() + '원';
            } else {
                couponRow.style.display = 'none';
            }
        }
        var pointRow = $('summPointRow');
        if (pointRow) {
            if (appliedPointUsed > 0) {
                pointRow.style.display = '';
                $('summPointAmount').textContent = '-' + appliedPointUsed.toLocaleString() + '원';
            } else {
                pointRow.style.display = 'none';
            }
        }
        var total = Math.max(0, originalGrandTotal - appliedCouponDiscount - appliedPointUsed);
        $('summGrandTotal').textContent = total.toLocaleString() + '원';
    }

    // 배송지 우편번호 기준 배송비 재계산 (도서산간 추가배송비 반영)
    // 청구는 결제 시 서버가 동일하게 재계산하므로, 이 호출은 표시 갱신용이다.
    function recalcShipping() {
        var zip = ($('shippingZip').value || '').trim();
        if (!zip) return;
        MubloRequest.requestJson('/shop/checkout/recalculate-shipping', {
            checkout_mode: __d.checkoutMode,
            cart_item_ids: __d.cartItemIds,
            shipping_zip: zip
        }).then(function(res) {
            if (!res || res.result !== 'success' || !res.data || res.data.unresolved) return;
            var d = res.data;
            var totalShip = parseInt(d.shippingFee) || 0;   // 기본+추가 합계
            var extra = parseInt(d.extraShippingFee) || 0;
            var base = totalShip - extra;                    // 기본배송비

            // 합계 상태 갱신 후 쿠폰/포인트 차감을 다시 반영
            // (originalShippingFee/GrandTotal 은 기본+추가 합계 기준 — 총액엔 둘 다 포함)
            originalShippingFee = totalShip;
            originalGrandTotal = originalProductTotal + totalShip;

            // 배송비 줄은 기본배송비만, 추가배송비는 별도 줄(같은 레벨)
            $('summShippingFee').textContent = base.toLocaleString() + '원';
            var exRow = $('summExtraShipRow');
            if (exRow) {
                if (extra > 0) {
                    $('summExtraShipAmount').textContent = extra.toLocaleString() + '원';
                    exRow.style.display = '';
                } else {
                    exRow.style.display = 'none';
                }
            }
            recalcSummary();
        });
    }

    // 진입 시 배송지(기본배송지 등)가 채워져 있으면 한 번 재계산하여 추가비를 반영.
    // MubloRequest 는 body 끝에서 로드되므로, 인라인 즉시 실행이 아니라 load 이후 호출한다.
    window.addEventListener('load', recalcShipping);

    if (!isGuest) {
        var couponModalBody = $('couponModalBody');
        var allApplicable = []; // 적용 가능 쿠폰 원본 목록

        $('btnSelectCoupon').addEventListener('click', function() {
            CheckoutModal.open('couponModalOverlay');
            loadApplicableCoupons();
        });

        $('couponModalClose').addEventListener('click', function() {
            CheckoutModal.close('couponModalOverlay');
        });

        function isSelected(couponId) {
            return selectedCoupons.some(function(s) { return s.coupon_id === couponId; });
        }

        // c를 적용할 때 충돌하는, 이미 선택된 쿠폰들 (서버 교차검증과 동일 규칙)
        // 같은 유형 1장 제한 등 충돌 시, 비활성으로 막지 않고 이들을 빼고 교체한다.
        function conflictingSelected(c) {
            return selectedCoupons.filter(function(s) {
                if (s.coupon_id === c.coupon_id) return false;
                if (s.duplicate_policy === 'DENY_ALL' || c.duplicate_policy === 'DENY_ALL') return true;
                if ((s.duplicate_policy === 'DENY_SAME_METHOD' || c.duplicate_policy === 'DENY_SAME_METHOD')
                    && s.coupon_method === c.coupon_method) return true;
                return false;
            });
        }

        function loadApplicableCoupons() {
            couponModalBody.innerHTML = '<div class="shop-checkout__modal-loading">불러오는 중...</div>';

            // 주문금액(상품금액)·배송비를 분리해 전달 — 배송비 쿠폰은 배송비 기준으로 추정해야 함
            MubloRequest.requestQuery('/shop/api/coupons/applicable', {
                order_amount: originalProductTotal,
                shipping_fee: originalShippingFee
            }).then(function(res) {
                allApplicable = (res.data && res.data.coupons) || [];
                renderModal();
            });
        }

        function renderModal() {
            if (allApplicable.length === 0) {
                couponModalBody.innerHTML = '<div class="shop-checkout__coupon-empty">적용 가능한 쿠폰이 없습니다.</div>';
                return;
            }
            var methodLabel = { ORDER: '주문 할인', GOODS: '상품', CATEGORY: '카테고리', SHIPPING: '배송비' };
            couponModalBody.innerHTML = allApplicable.map(function(c) {
                var sel = isSelected(c.coupon_id);
                // 이미 적용된 쿠폰만 비활성(✓). 충돌 쿠폰은 막지 않고 "교체"로 안내한다.
                var conflicts = sel ? [] : conflictingSelected(c);
                var cond = [];
                if (c.min_order_amount > 0) cond.push(Number(c.min_order_amount).toLocaleString() + '원 이상');
                if (c.valid_until) cond.push(c.valid_until.substring(0, 10) + '까지');
                var desc = (methodLabel[c.coupon_method] || '') + (cond.length ? ' · ' + cond.join(' · ') : '');

                // 상태/교체 안내는 유효기간과 같은 줄이 아니라 별도 줄로 표시
                var note = '';
                if (sel) note = '적용됨';
                else if (conflicts.length) note = '선택 시 \'' + (conflicts[0].name || '쿠폰') + '\' 교체';

                var cls = 'shop-checkout__coupon-item'
                    + (sel ? ' shop-checkout__coupon-item--selected shop-checkout__coupon-item--disabled' : '');
                return '<div class="' + cls + '" data-coupon-id="' + c.coupon_id + '">'
                    + '<div class="shop-checkout__coupon-item-info">'
                    + '<div class="shop-checkout__coupon-item-name">' + (sel ? '✓ ' : '') + (c.name || '쿠폰') + '</div>'
                    + '<div class="shop-checkout__coupon-item-desc">' + desc + '</div>'
                    + (note ? '<div class="shop-checkout__coupon-item-note">' + note + '</div>' : '')
                    + '</div>'
                    + '<div class="shop-checkout__coupon-item-discount">-' + Number(c.estimated_discount).toLocaleString() + '원</div>'
                    + '</div>';
            }).join('');
        }

        couponModalBody.addEventListener('click', function(e) {
            var item = e.target.closest('.shop-checkout__coupon-item');
            if (!item || item.classList.contains('shop-checkout__coupon-item--disabled')) return;
            var couponId = parseInt(item.dataset.couponId, 10);
            // 한 번에 한 장씩 선택 — 고르면 바로 닫는다. 같은 유형 등 충돌하는 기존 적용 쿠폰은
            // 자동으로 빼고 이 쿠폰으로 교체한다(× 제거 후 재오픈 불필요). 삭제는 칩 × 버튼.
            var c = allApplicable.filter(function(x) { return x.coupon_id === couponId; })[0];
            if (c) {
                var dropIds = conflictingSelected(c).map(function(s) { return s.coupon_id; });
                if (dropIds.length) {
                    selectedCoupons = selectedCoupons.filter(function(s) { return dropIds.indexOf(s.coupon_id) === -1; });
                }
                selectedCoupons.push(c);
                refreshCoupons(); // 칩·합계 갱신
            }
            CheckoutModal.close('couponModalOverlay');
        });

        // 적용 쿠폰 칩 렌더 + 합계(서버와 동일한 풀별 그리디 클램프) 갱신
        function refreshCoupons() {
            var remaining = { SHIPPING: originalShippingFee, PRODUCT: originalProductTotal };
            var total = 0;
            selectedCoupons.forEach(function(c) {
                var pool = c.coupon_method === 'SHIPPING' ? 'SHIPPING' : 'PRODUCT';
                var d = Math.min(Number(c.estimated_discount) || 0, Math.max(0, remaining[pool]));
                remaining[pool] -= d;
                c._applied = d;
                total += d;
            });

            $('couponSelectedList').innerHTML = selectedCoupons.map(function(c) {
                return '<div class="shop-checkout__coupon-applied">'
                    + '<div class="shop-checkout__coupon-applied-info">'
                    + '<span class="shop-checkout__coupon-applied-name">' + (c.name || '쿠폰') + '</span>'
                    + '<span class="shop-checkout__coupon-applied-discount">-' + Number(c._applied).toLocaleString() + '원</span>'
                    + '</div>'
                    + '<button type="button" class="shop-checkout__coupon-cancel-btn" data-remove="' + c.coupon_id + '" aria-label="쿠폰 적용 취소"><i class="bi bi-x-lg"></i></button>'
                    + '</div>';
            }).join('');

            var badge = $('couponApplied');
            if (selectedCoupons.length > 0) {
                badge.textContent = selectedCoupons.length + '개 적용됨';
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }

            appliedCouponDiscount = total;
            recalcSummary();
            reclampPoint(); // 쿠폰 변동 시 포인트 상한 재적용
        }

        // 칩 삭제
        $('couponSelectedList').addEventListener('click', function(e) {
            var btn = e.target.closest('[data-remove]');
            if (!btn) return;
            var couponId = parseInt(btn.dataset.remove, 10);
            selectedCoupons = selectedCoupons.filter(function(s) { return s.coupon_id !== couponId; });
            refreshCoupons();
        });
    }

    // ── 무통장입금 선택 시 입금계좌 안내 표시 ──
    (function() {
        var wrap = document.getElementById('bankAccountWrap');
        if (!wrap) return;
        function toggle() {
            var sel = document.querySelector('input[name="payment_gateway"]:checked');
            wrap.style.display = (sel && sel.value === 'bank') ? '' : 'none';
        }
        document.querySelectorAll('input[name="payment_gateway"]').forEach(function(r) {
            r.addEventListener('change', toggle);
        });
        toggle();
    })();

    // ============================
    // 포인트 사용 (회원 + 설정 ON)
    // ============================
    if (__d.pointUsage.enabled) {
    (function() {
        var input = $('pointUseInput');
        if (!input) return;
        var unit = __d.pointUsage.unit;
        var minP = __d.pointUsage.min;
        var maxP = __d.pointUsage.max;
        var balance = __d.pointUsage.balance;

        // 사용 가능 상한 = min(보유잔액, 쿠폰 적용 후 결제금액, 최대한도)
        function maxUsable() {
            var payable = Math.max(0, originalGrandTotal - appliedCouponDiscount);
            var lim = Math.min(balance, payable);
            if (maxP > 0) lim = Math.min(lim, maxP);
            return Math.max(0, lim);
        }

        function applyPoint() {
            var v = parseInt(input.value, 10) || 0;
            if (v < 0) v = 0;
            var msg = '';
            var cap = maxUsable();
            if (v > cap) v = cap;
            if (unit > 1) v = Math.floor(v / unit) * unit; // 단위 내림
            if (v > 0 && minP > 0 && v < minP) {
                msg = '최소 ' + minP.toLocaleString() + 'P 이상 사용할 수 있습니다.';
                v = 0;
            }
            if (String(v) !== input.value) input.value = v;
            appliedPointUsed = v;
            $('pointUseMsg').textContent = msg;
            recalcSummary();
        }

        reclampPoint = applyPoint; // 쿠폰 변동 시 외부에서 호출
        input.addEventListener('input', applyPoint);
        input.addEventListener('change', applyPoint);
        $('pointUseAllBtn').addEventListener('click', function() {
            var v = maxUsable();
            if (unit > 1) v = Math.floor(v / unit) * unit;
            input.value = v;
            applyPoint();
        });
    })();
    }

    // ============================
    // 결제하기
    // ============================
    var payBtn = $('btnPay');
    var isPaying = false;

    // 약관 동의 UI: 전체 동의 ↔ 개별 동기화, 약관 보기 토글
    (function() {
        var all = document.getElementById('agreeAll');
        var checks = document.querySelectorAll('.shop-checkout__agree-check');
        if (all) {
            all.addEventListener('change', function() {
                checks.forEach(function(c) { c.checked = all.checked; });
            });
        }
        checks.forEach(function(c) {
            c.addEventListener('change', function() {
                if (!all) return;
                var every = true;
                checks.forEach(function(x) { if (!x.checked) every = false; });
                all.checked = every;
            });
        });
        document.querySelectorAll('.shop-checkout__agree-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var el = document.getElementById(btn.getAttribute('data-target'));
                if (el) { el.style.display = (el.style.display === 'none') ? 'block' : 'none'; }
            });
        });
    })();

    payBtn.addEventListener('click', function() {
        if (isPaying) return;

        // 주문인 정보 검증 (회원·비회원 공통)
        var ordererName = $('ordererName').value.trim();
        var ordererPhone = $('ordererPhone').value.trim();

        if (!ordererName) { MubloRequest.showAlert('주문인을 입력해주세요.', 'warning'); return; }
        if (!ordererPhone) { MubloRequest.showAlert('주문인 연락처를 입력해주세요.', 'warning'); return; }

        var recipientName = $('recipientName').value.trim();
        var recipientPhone = $('recipientPhone').value.trim();
        var shippingZip = $('shippingZip').value.trim();
        var shippingAddress1 = $('shippingAddress1').value.trim();
        var shippingAddress2 = $('shippingAddress2').value.trim();
        var orderMemo = $('orderMemo').value.trim();

        if (!recipientName) { MubloRequest.showAlert('수령인을 입력해주세요.', 'warning'); return; }
        if (!recipientPhone) { MubloRequest.showAlert('연락처를 입력해주세요.', 'warning'); return; }
        if (!shippingZip || !shippingAddress1) { MubloRequest.showAlert('배송 주소를 입력해주세요.', 'warning'); return; }

        var selectedGateway = document.querySelector('input[name="payment_gateway"]:checked');
        if (!selectedGateway) { MubloRequest.showAlert('결제 수단을 선택해주세요.', 'warning'); return; }

        // 필수 약관 동의 검증 (서버가 최종 권위, 여기선 UX 차원 사전 차단)
        var agreeChecks = document.querySelectorAll('.shop-checkout__agree-check');
        var agreedIds = [];
        var allAgreed = true;
        agreeChecks.forEach(function(chk) {
            if (chk.checked) { agreedIds.push(parseInt(chk.getAttribute('data-policy-id'), 10)); }
            else { allAgreed = false; }
        });
        if (agreeChecks.length > 0 && !allAgreed) { MubloRequest.showAlert('필수 약관에 모두 동의해주세요.', 'warning'); return; }

        isPaying = true;
        payBtn.disabled = true;
        payBtn.textContent = '주문 처리 중...';

        var cartItemIds = __d.cartItemIds;

        // 주문 추가 필드 수집
        var orderFieldValues = {};
        document.querySelectorAll('[name^="orderFields["]').forEach(function(el) {
            var match = el.name.match(/orderFields\[(\d+)\](\[(\w+)\])?/);
            if (!match) return;
            var fid = match[1];
            var subKey = match[3] || null;

            if (el.type === 'checkbox' && !subKey) {
                // 체크박스 배열: orderFields[id][]
                if (!orderFieldValues[fid]) orderFieldValues[fid] = [];
                if (el.checked) orderFieldValues[fid].push(el.value);
            } else if (el.type === 'radio') {
                if (el.checked) orderFieldValues[fid] = el.value;
            } else if (subKey) {
                // address 서브필드: orderFields[id][zipcode] etc.
                if (!orderFieldValues[fid] || typeof orderFieldValues[fid] !== 'object') orderFieldValues[fid] = {};
                orderFieldValues[fid][subKey] = el.value;
            } else {
                orderFieldValues[fid] = el.value;
            }
        });
        // hidden meta (file 타입)
        document.querySelectorAll('input[id$="_meta"][type="hidden"]').forEach(function(el) {
            var match = el.id.match(/of_(\d+)_meta/);
            if (match && el.value) orderFieldValues[match[1]] = el.value;
        });

        // Step 1: 주문 생성 + PG 결제 준비
        var paymentData = {
            payment_gateway: selectedGateway.value,
            payment_method: (selectedGateway.value === 'bank') ? 'BANK' : 'CARD',
            checkout_mode: __d.checkoutMode,
            cart_item_ids: cartItemIds,
            recipient_name: recipientName,
            recipient_phone: recipientPhone,
            shipping_zip: shippingZip,
            shipping_address1: shippingAddress1,
            shipping_address2: shippingAddress2,
            order_memo: orderMemo,
            order_fields: orderFieldValues,
            agreements: agreedIds
        };

        // 무통장입금: 선택한 입금계좌 인덱스 전송 (서버에서 shop config의 계좌 목록과 매칭)
        if (selectedGateway.value === 'bank') {
            var bankSel = document.getElementById('bankAccountSelect');
            if (bankSel && bankSel.value !== '') {
                paymentData.bank_account_index = parseInt(bankSel.value, 10);
            }
        }

        // 쿠폰 적용 정보 추가 (복수)
        if (selectedCoupons.length > 0) {
            paymentData.coupon_ids = selectedCoupons.map(function(c) { return c.coupon_id; });
        }

        // 포인트 사용 정보 추가
        if (appliedPointUsed > 0) {
            paymentData.point_used = appliedPointUsed;
        }

        // 주문인 정보 추가 (회원·비회원 공통)
        paymentData.orderer_name = ordererName;
        paymentData.orderer_phone = ordererPhone;
        if (isGuest) {
            paymentData.is_guest = true;
        }

        // 회원: "기본 배송지로 설정" 체크 시 서버에 전달 (주문 생성 후 주소록에 기본 저장)
        var chkDefault = $('chkSetDefault');
        if (chkDefault && chkDefault.checked) {
            paymentData.set_default = 1;
        }

        MubloRequest.requestJson('/shop/checkout/payment', paymentData).then(function(res) {
            var data = res.data || {};
            // 0원 결제(쿠폰/포인트 전액 상계, data.paid) 또는 무통장입금:
            // PG 결제창 없이 완료/접수 → 완료 페이지로 이동
            if (data.paid || paymentData.payment_method === 'BANK') {
                if (data.redirect) { location.href = data.redirect; }
                else { resetPayBtn(); }
                return;
            }
            // Step 2: PG 결제창 처리
            handlePaymentGateway(data);
        }).catch(function() {
            resetPayBtn();
        });
    });

    // ============================
    // PG 신호 구독 (계약: document 'mublo:pay')
    //
    // 결제 플러그인은 소비처를 모른다. 결제 진행 중 일어난 사실만 알리고,
    // 알릴지 말지·무엇을 보여줄지·버튼을 되돌릴지는 이 페이지가 정한다.
    // detail: { gateway, status: 'cancel'|'fail', message, code }
    // ============================
    var lastPayment = null;

    document.addEventListener('mublo:pay', function(e) {
        var detail = (e && e.detail) || {};

        // 결제 완료 — 이동할지 그 자리에서 처리할지는 이 페이지가 정한다.
        // 장바구니 주문서는 완료 페이지로 보낸다.
        if (detail.status === 'done') {
            var done = lastPayment && lastPayment.success_url;
            if (done) { location.href = done; return; }
            resetPayBtn();
            return;
        }

        // 사용자가 스스로 결제창을 닫은 것(cancel)은 오류가 아니므로 알리지 않는다.
        if (detail.status === 'fail') {
            MubloRequest.showAlert(detail.message || '결제를 진행할 수 없습니다. 잠시 후 다시 시도해주세요.');
        }

        resetPayBtn();
    });

    // 리다이렉트형 PG: 결제창이 페이지를 통째로 이동시키므로 이벤트를 쓸 수 없다.
    // 콜백이 pay_error 쿼리에 실어 보낸 사유를 여기서 표시한다(표시 여부는 소비처 결정).
    (function showCallbackError() {
        var params = new URLSearchParams(location.search);
        var message = params.get('pay_error');
        if (!message) return;

        MubloRequest.showAlert(message);

        // 새로고침·뒤로가기에서 같은 알림이 반복되지 않도록 쿼리를 지운다.
        params.delete('pay_error');
        var qs = params.toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
    })();

    // 하위 호환: 신호 규약 이전 방식으로 만들어진 외부 플러그인이 부를 수 있다.
    // 코어 플러그인은 더 이상 호출하지 않는다. (deprecated)
    window.MubloPayReset = resetPayBtn;

    /**
     * PG 유형에 따라 결제창 처리
     *
     * 우선순위:
     * 1. PG 플러그인이 등록한 window.MubloPayHandlers[gateway] 핸들러
     *    (TestPay·PayApp 등 자체 결제창/모달을 가진 PG)
     * 2. 폴백: 핸들러 없는 PG → 바로 verify
     */
    function handlePaymentGateway(data) {
        var gateway = data.gateway || '';
        lastPayment = data;   // 'done' 신호를 받았을 때 어디로 보낼지 이 페이지가 결정한다

        if (window.MubloPayHandlers && window.MubloPayHandlers[gateway]) {
            window.MubloPayHandlers[gateway](data);
            return;
        }

        // 폴백: 별도 결제창이 필요 없는 PG → 즉시 검증
        verifyPayment(data);
    }

    /**
     * Step 3: 결제 검증 요청
     */
    function verifyPayment(data) {
        MubloRequest.requestJson('/shop/checkout/verify', {
            order_no: data.order_no,
            payment_gateway: data.gateway,
            transaction_id: data.transaction_id
        }).then(function(res) {
            if (res.data && res.data.redirect) {
                location.href = res.data.redirect;
            }
        }).catch(function() {
            resetPayBtn();
        });
    }

    function resetPayBtn() {
        isPaying = false;
        payBtn.disabled = false;
        payBtn.textContent = '결제하기';
    }
})();
