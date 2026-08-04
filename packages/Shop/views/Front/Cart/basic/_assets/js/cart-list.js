/**
 * 장바구니 목록 (basic 스킨) 뷰 스크립트 — ShopCart 전역(HTML onclick에서 참조).
 * 서버데이터(productData)는 뷰의 <script type="application/json" id="cart-product-data">에서 로드.
 * ※ IIFE로 감싸지 말 것: ShopCart 전역성이 깨져 onclick 핸들러가 동작 안 함.
 */
const productData = (function () {
    try { return JSON.parse(document.getElementById('cart-product-data').textContent || '[]'); }
    catch (e) { return []; }
})();

const ShopCart = {
    updateQty(cartItemId, delta) {
        const row = document.querySelector(`[data-cart-id="${cartItemId}"]`);
        if (!row) return;
        const input = row.querySelector('.shop-cart__qty-input');
        const newQty = Math.max(1, parseInt(input.value) + delta);

        MubloRequest.requestJson('/shop/cart/update', {
            cart_item_id: cartItemId,
            quantity: newQty
        }).then(() => location.reload());
    },

    remove(cartItemId) {
        MubloRequest.showConfirm('삭제하시겠습니까?', () => {
            MubloRequest.requestJson('/shop/cart/remove', {
                cart_item_id: cartItemId
            }).then(() => location.reload());
        }, { type: 'warning' });
    },

    // 가격변동 항목을 현재가로 갱신 후 새로고침
    refreshPrice(cartItemId) {
        MubloRequest.requestJson('/shop/cart/refresh-price', {
            cart_item_id: cartItemId
        }).then(() => location.reload());
    },

    changeOption(cartItemId, goodsId, currentOptionId) {
        const data = productData[goodsId];
        if (!data) return;

        this._currentCartItemId = cartItemId;
        this._currentGoodsId = goodsId;

        const row = document.querySelector(`[data-cart-id="${cartItemId}"]`);
        const currentQty = row ? parseInt(row.querySelector('.shop-cart__qty-input')?.value || '1', 10) : 1;

        const body = document.getElementById('optionChangeBody');
        body.innerHTML = this._renderOptionForm(data, currentOptionId);

        const qtyWrap = document.createElement('div');
        qtyWrap.className = 'shop-cart__modal-qty';
        qtyWrap.innerHTML = '<label class="shop-cart__modal-label">수량</label>'
            + '<div class="shop-cart__modal-qty-controls">'
            + '<button type="button" class="shop-cart__modal-qty-btn" onclick="ShopCart._changeQty(-1)"><i class="bi bi-dash-lg"></i></button>'
            + `<input id="optionQtyInput" type="number" min="1" value="${currentQty}" class="shop-cart__modal-qty-input">`
            + '<button type="button" class="shop-cart__modal-qty-btn" onclick="ShopCart._changeQty(1)"><i class="bi bi-plus-lg"></i></button>'
            + '</div>';
        body.appendChild(qtyWrap);

        document.getElementById('optionChangeConfirm').onclick = () => ShopCart.confirmOptionChange();
        this.openOptionModal();
    },

    _currentCartItemId: null,
    _currentGoodsId: null,

    _renderOptionForm(data, currentOptionId) {
        if (data.option_mode === 'NONE' || !data.options || data.options.length === 0) {
            return '<p class="shop-cart__modal-empty">이 상품은 옵션이 없습니다.</p>';
        }

        if (data.option_mode === 'COMBINATION' && data.combos && data.combos.length > 0) {
            let html = '<div class="shop-cart__modal-field"><label class="shop-cart__modal-label">옵션 선택</label>'
                + '<select id="optionComboSelect" class="shop-cart__modal-select">'
                + '<option value="">옵션을 선택하세요</option>';
            data.combos.forEach(combo => {
                const extra = Number(combo.extra_price) || 0;
                const price = extra > 0 ? ` (+${extra.toLocaleString()}원)` : (extra < 0 ? ` (${extra.toLocaleString()}원)` : '');
                const stockQty = combo.stock_quantity ?? combo.stock_qty;
                const stock = stockQty !== undefined && stockQty !== null && stockQty <= 0 ? ' [품절]' : '';
                const disabled = stock ? ' disabled' : '';
                const label = combo.combination_key ?? combo.combo_label ?? '';
                const selected = Number(combo.combo_id) === Number(currentOptionId) ? ' selected' : '';
                html += `<option value="${combo.combo_id}"${disabled}${selected}>${label}${price}${stock}</option>`;
            });
            html += '</select></div>';
            return html;
        }

        // SINGLE 모드: BASIC 은 셀렉트 1개, EXTRA(추가구성)는 복수 선택
        let html = '';
        data.options.forEach(opt => {
            html += '<div class="shop-cart__modal-field">'
                + `<label class="shop-cart__modal-label">${opt.option_name || '옵션'}</label>`;

            if (opt.option_type === 'EXTRA') {
                html += '<div class="shop-cart__modal-choice-list">';
                (opt.values || []).forEach(v => {
                    const extra = Number(v.extra_price) || 0;
                    const price = extra > 0 ? ` +${extra.toLocaleString()}원` : '';
                    html += `<label class="shop-cart__modal-choice">`
                        + `<input type="checkbox" name="opt_${opt.option_id}" value="${v.value_id}">`
                        + `${v.value_name}${price}</label>`;
                });
                html += '</div>';
            } else {
                html += `<select name="opt_${opt.option_id}" class="shop-cart__modal-select">`
                    + '<option value="">선택하세요</option>';
                (opt.values || []).forEach(v => {
                    const extra = Number(v.extra_price) || 0;
                    const price = extra > 0 ? ` (+${extra.toLocaleString()}원)` : (extra < 0 ? ` (${extra.toLocaleString()}원)` : '');
                    html += `<option value="${v.value_id}">${v.value_name}${price}</option>`;
                });
                html += '</select>';
            }
            html += '</div>';
        });
        return html;
    },

    _changeQty(delta) {
        const input = document.getElementById('optionQtyInput');
        if (input) input.value = Math.max(1, parseInt(input.value || 1) + delta);
    },

    confirmOptionChange() {
        const cartItemId = this._currentCartItemId;
        const goodsId = this._currentGoodsId;
        const data = productData[goodsId];
        if (!cartItemId || !data) return;

        const qty = Math.max(1, parseInt(document.getElementById('optionQtyInput')?.value || 1));
        const payload = {
            cart_item_id: cartItemId,
            optionMode: data.option_mode,
            quantity: qty,
            selectedOptions: [],
            selectedExtras: [],
        };

        if (data.option_mode === 'COMBINATION') {
            const sel = document.getElementById('optionComboSelect');
            if (sel && sel.value) {
                payload.selectedOptions = [{ comboId: parseInt(sel.value), quantity: qty }];
            }
        } else {
            const body = document.getElementById('optionChangeBody');
            (data.options || []).forEach(opt => {
                if (opt.option_type === 'EXTRA') {
                    const checked = body.querySelectorAll(`input[name="opt_${opt.option_id}"]:checked`);
                    checked.forEach(cb => {
                        payload.selectedExtras.push({ optionId: opt.option_id, valueId: parseInt(cb.value), quantity: qty });
                    });
                } else {
                    const sel = body.querySelector(`select[name="opt_${opt.option_id}"]`);
                    if (sel && sel.value) {
                        payload.selectedOptions.push({ optionId: opt.option_id, valueId: parseInt(sel.value), quantity: qty });
                    }
                }
            });
        }

        MubloRequest.requestJson('/shop/cart/update-option', payload)
            .then(() => {
                this.closeOptionModal();
                location.reload();
            });
    },

    openOptionModal() {
        document.getElementById('optionChangeOverlay').classList.add('is-open');
    },

    closeOptionModal() {
        document.getElementById('optionChangeOverlay').classList.remove('is-open');
    },

    checkout() {
        const checked = document.querySelectorAll('.cart-check:checked');
        const ids = Array.from(checked).map(el => parseInt(el.value));

        if (ids.length === 0) {
            MubloRequest.showAlert('주문할 상품을 선택해주세요.', 'warning');
            return;
        }

        MubloRequest.requestJson('/shop/cart/prepare-checkout', {
            cart_item_ids: ids
        }).then(res => {
            location.href = res.data?.redirect || '/shop/checkout';
        }).catch(err => {
            // 세션 불일치 등으로 유효한 상품이 없을 때: 알럿 닫힌 뒤 새로고침
            const msg = err?.response?.message || err?.message || '';
            if (msg.indexOf('유효한 상품') === -1) return;

            const overlay = document.getElementById('mublo-alert-overlay');
            if (!overlay) { location.reload(); return; }
            const obs = new MutationObserver(() => {
                if (!document.body.contains(overlay)) {
                    obs.disconnect();
                    location.reload();
                }
            });
            obs.observe(document.body, { childList: true });
        });
    }
};

// 그룹 전체선택 (부모 → 자식)
document.querySelectorAll('.group-check').forEach(el => {
    el.addEventListener('change', function() {
        const group = this.dataset.group;
        this.indeterminate = false;
        document.querySelectorAll(`[data-group="${group}"] .cart-check`).forEach(cb => {
            if (!cb.disabled) cb.checked = this.checked;  // 품절/가격변동 항목은 제외
        });
        scheduleRecalc();
    });
});

// 전체선택 상태 동기화 (자식 → 부모): 전부 선택=체크, 일부=indeterminate, 전무=해제
// 품절/가격변동으로 비활성(disabled)된 항목은 선택 대상에서 제외하고 카운트
function syncGroupCheck(group) {
    const boxes = Array.from(document.querySelectorAll(`[data-group="${group}"] .cart-check`)).filter(cb => !cb.disabled);
    const groupCheck = document.querySelector(`.group-check[data-group="${group}"]`);
    if (!groupCheck) return;
    if (boxes.length === 0) {
        // 그룹 전체가 비활성: 전체선택도 비활성 처리
        groupCheck.checked = false;
        groupCheck.indeterminate = false;
        groupCheck.disabled = true;
        return;
    }
    const checked = boxes.filter(cb => cb.checked).length;
    groupCheck.checked = checked === boxes.length;
    groupCheck.indeterminate = checked > 0 && checked < boxes.length;
}

// 선택(체크박스) 변경 시 배송비/합계 재계산 (디바운스 AJAX, 단일 진실 원천 = 서버 계산기)
// 디바운스는 짧게(전체선택 등 동기 다중 토글 묶음용) + 경합 가드로 최신 선택만 반영
let recalcTimer = null;
let recalcInFlight = false;
let recalcDirty = false;

function scheduleRecalc() {
    clearTimeout(recalcTimer);
    recalcTimer = setTimeout(recalcSelection, 80);
}

function recalcSelection() {
    if (recalcInFlight) { recalcDirty = true; return; }  // 진행 중이면 끝난 뒤 최신으로 재요청
    recalcInFlight = true;
    recalcDirty = false;
    const ids = Array.from(document.querySelectorAll('.cart-check:checked')).map(el => parseInt(el.value));
    MubloRequest.requestJson('/shop/cart/recalculate', { cart_item_ids: ids })
        .then(res => applyRecalc(res.data || {}))
        .catch(() => {})
        .finally(() => {
            recalcInFlight = false;
            if (recalcDirty) recalcSelection();
        });
}

function applyRecalc(data) {
    const groups = data.groups || {};
    const won = n => Number(n || 0).toLocaleString() + '원';

    document.querySelectorAll('.shop-cart__group').forEach(groupEl => {
        const info = groups[groupEl.dataset.group] || { shipping_fee: 0, unresolved: false, free_remain: 0 };

        // 배송비 표시
        const feeEl = groupEl.querySelector('[data-role="group-shipping"]');
        if (feeEl) {
            if (info.unresolved) {
                feeEl.className = 'shop-cart__group-shipping shop-cart__group-shipping--unset text-danger';
                feeEl.textContent = '배송 정책 미설정';
            } else if (Number(info.shipping_fee) > 0) {
                feeEl.className = 'shop-cart__group-shipping';
                feeEl.textContent = '배송비 ' + won(info.shipping_fee);
            } else {
                feeEl.className = 'shop-cart__group-shipping shop-cart__group-shipping--free';
                feeEl.textContent = '무료배송';
            }
        }

        // 무료배송 부족액 안내 (nudge)
        const nudgeEl = groupEl.querySelector('[data-role="group-nudge"]');
        if (nudgeEl) {
            const remain = Number(info.free_remain) || 0;
            if (remain > 0) {
                const remainEl = nudgeEl.querySelector('[data-role="nudge-remain"]');
                if (remainEl) remainEl.textContent = won(remain);
                nudgeEl.hidden = false;
            } else {
                nudgeEl.hidden = true;
            }
        }
    });

    // 합계
    const totals = data.totals || {};
    const itemEl = document.getElementById('cartItemTotal');
    const shipEl = document.getElementById('cartShippingTotal');
    const grandEl = document.getElementById('cartGrandTotal');
    if (itemEl) itemEl.textContent = won(totals.itemTotal);
    if (shipEl) shipEl.innerHTML = totals.unresolved ? '<span class="text-danger">미설정</span>' : won(totals.shippingTotal);
    if (grandEl) grandEl.textContent = won(totals.grandTotal);

    // 주문 버튼/미설정 안내
    const orderBtn = document.getElementById('cartOrderBtn');
    if (orderBtn) {
        orderBtn.disabled = !!totals.unresolved;
        orderBtn.title = totals.unresolved ? '배송 정책이 설정되지 않아 주문할 수 없습니다.' : '';
    }
    const unresolvedMsg = document.getElementById('cartUnresolvedMsg');
    if (unresolvedMsg) unresolvedMsg.hidden = !totals.unresolved;
}

document.querySelectorAll('.cart-check').forEach(cb => cb.addEventListener('change', function() {
    const group = this.closest('[data-group]')?.dataset.group;
    if (group) syncGroupCheck(group);
    scheduleRecalc();
}));

// 모달을 body 직속으로 이동 (.mublo-main의 stacking context 탈출)
const optionOverlay = document.getElementById('optionChangeOverlay');
if (optionOverlay && optionOverlay.parentElement !== document.body) {
    document.body.appendChild(optionOverlay);
}

// 모달 오버레이 클릭 시 닫기
optionOverlay?.addEventListener('click', function(e) {
    if (e.target === this) ShopCart.closeOptionModal();
});

// 초기 보정: 품절/가격변동으로 비활성된 항목이 있으면 전체선택 상태 동기화 + 선택 기준 합계 재계산
// (서버가 그린 초기 합계는 전체 항목 기준이므로, 비활성 항목을 뺀 실제 선택 합계로 맞춘다)
if (document.querySelector('.cart-check:disabled')) {
    document.querySelectorAll('.shop-cart__group').forEach(g => syncGroupCheck(g.dataset.group));
    scheduleRecalc();
}
