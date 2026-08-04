/**
 * 관리자 상품 찾기 피커 (구매후기 / 상품문의 폼 공용)
 *
 * 마크업 계약: 폼 안에 아래 구조의 요소를 두면 자동 배선된다.
 *   <div data-product-picker
 *        data-view-base="/shop/products"
 *        data-manage-base="/admin/shop/products"
 *        data-search-url="/admin/shop/block-items">
 *     <input type="hidden" data-picker-id   name="formData[goods_id]" value="0">
 *     <input type="text"   data-picker-name  readonly>
 *     <button data-picker-search>상품 찾기</button>
 *     <a data-picker-view target="_blank"></a>       (선택 시에만 노출)
 *     <a data-picker-manage target="_blank"></a>     (선택 시에만 노출)
 *     <button data-picker-clear></button>            (선택 시에만 노출)
 *   </div>
 *
 * 검색은 기존 엔드포인트 /admin/shop/block-items 를 재사용한다.
 * 응답 item: { id, label, main_image_url, price }
 */
(function () {
    'use strict';

    var MODAL_ID = 'goodsPickerModal';
    var modalEl = null;
    var modalInstance = null;
    var keywordInput = null;
    var resultsBox = null;
    var activeCallback = null;

    // 검색 모달을 페이지당 1회만 주입
    function ensureModal() {
        if (modalEl) return;

        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = MODAL_ID;
        modalEl.tabIndex = -1;
        modalEl.innerHTML =
            '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<h5 class="modal-title"><i class="bi bi-search me-1"></i> 상품 찾기</h5>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                        '<div class="input-group mb-3">' +
                            '<input type="text" class="form-control" data-picker-keyword placeholder="상품명으로 검색">' +
                            '<button type="button" class="btn btn-primary" data-picker-do-search><i class="bi bi-search"></i> 검색</button>' +
                        '</div>' +
                        '<div data-picker-results></div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modalEl);

        modalInstance = new bootstrap.Modal(modalEl);
        keywordInput = modalEl.querySelector('[data-picker-keyword]');
        resultsBox = modalEl.querySelector('[data-picker-results]');

        modalEl.querySelector('[data-picker-do-search]').addEventListener('click', runSearch);
        keywordInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
        });

        // 결과 목록의 "선택" (이벤트 위임)
        resultsBox.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pick]');
            if (!btn) return;
            if (activeCallback) {
                activeCallback({
                    id: btn.getAttribute('data-id'),
                    label: btn.getAttribute('data-label') || '',
                });
            }
            // 포커스가 모달 내부에 남은 채 aria-hidden 처리되는 경고 방지
            if (document.activeElement) document.activeElement.blur();
            modalInstance.hide();
        });

        // 검색 URL은 피커마다 다를 수 있어 열 때 셋업
        modalEl.addEventListener('shown.bs.modal', function () { keywordInput.focus(); });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var currentSearchUrl = '/admin/shop/block-items';

    function runSearch() {
        var keyword = (keywordInput.value || '').trim();
        resultsBox.innerHTML = '<p class="text-muted text-center py-4 mb-0"><span class="spinner-border spinner-border-sm me-1"></span> 검색 중...</p>';

        MubloRequest.requestQuery(currentSearchUrl, { keyword: keyword })
            .then(function (res) {
                var items = (res && res.data && res.data.items) ? res.data.items : [];
                renderResults(items);
            })
            .catch(function () {
                resultsBox.innerHTML = '<p class="text-danger text-center py-4 mb-0">검색에 실패했습니다.</p>';
            });
    }

    function renderResults(items) {
        if (!items.length) {
            resultsBox.innerHTML = '<p class="text-muted text-center py-4 mb-0">검색 결과가 없습니다.</p>';
            return;
        }

        var rows = items.map(function (item) {
            var img = item.main_image_url
                ? '<img src="' + escapeHtml(item.main_image_url) + '" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;background:var(--muted)">'
                : '<div style="width:44px;height:44px;border-radius:6px;background:var(--muted);display:flex;align-items:center;justify-content:center;color:var(--muted-foreground)"><i class="bi bi-image"></i></div>';
            return '' +
                '<tr>' +
                    '<td class="text-center align-middle" style="width:56px">' + img + '</td>' +
                    '<td class="align-middle"><div class="fw-semibold">' + escapeHtml(item.label) + '</div>' +
                        '<div class="text-muted small">#' + escapeHtml(item.id) + ' · ' + escapeHtml(item.price) + '</div></td>' +
                    '<td class="text-end align-middle" style="width:80px">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary" data-pick ' +
                            'data-id="' + escapeHtml(item.id) + '" data-label="' + escapeHtml(item.label) + '">선택</button>' +
                    '</td>' +
                '</tr>';
        }).join('');

        resultsBox.innerHTML =
            '<table class="table table-hover align-middle mb-0"><tbody>' + rows + '</tbody></table>';
    }

    // 개별 피커 배선
    function initPicker(root) {
        if (root.dataset.pickerReady === '1') return;
        root.dataset.pickerReady = '1';

        var idInput = root.querySelector('[data-picker-id]');
        var nameInput = root.querySelector('[data-picker-name]');
        var searchBtn = root.querySelector('[data-picker-search]');
        var clearBtn = root.querySelector('[data-picker-clear]');
        var viewLink = root.querySelector('[data-picker-view]');
        var manageLink = root.querySelector('[data-picker-manage]');

        var viewBase = root.dataset.viewBase || '/shop/products';
        var manageBase = root.dataset.manageBase || '/admin/shop/products';
        var searchUrl = root.dataset.searchUrl || '/admin/shop/block-items';

        // 수정 진입 시점의 원래 상품 — 교체 후 "되돌리기"의 복원 기준
        var originalId = parseInt(idInput.value, 10) || 0;
        var originalName = nameInput.value || '';

        function refresh() {
            var id = parseInt(idInput.value, 10) || 0;
            var has = id > 0;
            if (viewLink) {
                viewLink.href = has ? (viewBase + '/' + id) : '#';
                viewLink.classList.toggle('d-none', !has);
            }
            if (manageLink) {
                manageLink.href = has ? (manageBase + '/' + id + '/edit') : '#';
                manageLink.classList.toggle('d-none', !has);
            }
            if (clearBtn) {
                // 상품은 필수 → "비우기"는 없다. 수정 중 원래 상품과 달라졌을 때만
                // "이전 상품으로 되돌리기"를 노출한다. (교체는 항상 '상품 찾기'로)
                var canRevert = originalId > 0 && id !== originalId;
                clearBtn.classList.toggle('d-none', !canRevert);
            }
            roundTrailing();
        }

        // input-group에서 화면상 마지막으로 보이는 버튼에만 우측 라운딩을 부여한다.
        // (뒤쪽 버튼이 d-none이면 Bootstrap의 :last-child 라운딩이 숨은 요소로 가버림)
        function roundTrailing() {
            var btns = [searchBtn, clearBtn, viewLink, manageLink].filter(Boolean);
            btns.forEach(function (b) {
                b.style.borderTopRightRadius = '';
                b.style.borderBottomRightRadius = '';
            });
            for (var i = btns.length - 1; i >= 0; i--) {
                if (!btns[i].classList.contains('d-none')) {
                    btns[i].style.borderTopRightRadius = 'var(--bs-border-radius)';
                    btns[i].style.borderBottomRightRadius = 'var(--bs-border-radius)';
                    break;
                }
            }
        }

        searchBtn.addEventListener('click', function () {
            ensureModal();
            currentSearchUrl = searchUrl;
            activeCallback = function (item) {
                idInput.value = item.id;
                nameInput.value = item.label;
                refresh();
            };
            keywordInput.value = '';
            resultsBox.innerHTML = '<p class="text-muted text-center py-4 mb-0">상품명을 입력 후 검색하세요.</p>';
            modalInstance.show();
        });

        if (clearBtn) {
            // 되돌리기 전용 — 원래 상품으로 복원
            clearBtn.addEventListener('click', function () {
                idInput.value = String(originalId);
                nameInput.value = originalName;
                refresh();
            });
        }

        refresh();
    }

    function initAll() {
        document.querySelectorAll('[data-product-picker]').forEach(initPicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // 필요 시 수동 재초기화 (동적 삽입 대응)
    window.MubloProductPicker = { init: initAll };
})();
