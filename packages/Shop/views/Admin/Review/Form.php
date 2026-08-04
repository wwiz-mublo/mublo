<?php
/**
 * 구매후기 등록/수정
 *
 * @var string $pageTitle
 * @var array|null $review
 */
$r = $review ?? [];
$isEdit = !empty($r['review_id']);
$btnLabel = $isEdit ? '수정' : '등록';
?>
<?php
// 기존 HTML 콘텐츠를 일반텍스트로 변환 (에디터로 저장됐던 레코드 호환)
$contentRaw = $r['content'] ?? '';
$contentText = trim(html_entity_decode(strip_tags(
    preg_replace(['#<br\s*/?>#i', '#</p>#i'], ["\n", "\n\n"], $contentRaw)
), ENT_QUOTES, 'UTF-8'));
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>구매후기를 <?= $isEdit ? '수정' : '등록' ?>합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/reviews" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveReview()">
                <i class="bi bi-check-lg"></i> <?= $btnLabel ?>
            </button>
        </div>
    </div>

    <form id="reviewForm" enctype="multipart/form-data">
        <input type="hidden" name="formData[review_id]" value="<?= (int)($r['review_id'] ?? 0) ?>">

        <div class="page-block">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">상품</label>
                            <?php
                                $gid = (int)($r['goods_id'] ?? 0);
                                $gslug = $r['goods_slug'] ?? '';
                                $frontUrl = $gid > 0 ? ('/shop/products/' . $gid . ($gslug !== '' ? '/' . rawurlencode($gslug) : '')) : '';
                            ?>
                            <input type="hidden" name="formData[goods_id]" id="fldGoodsId" value="<?= $gid ?>">
                            <?php if (!$isEdit): ?>
                            <input type="hidden" name="formData[order_no]" id="fldOrderNo" value="">
                            <input type="hidden" name="formData[order_detail_id]" id="fldOrderDetailId" value="">
                            <input type="hidden" name="formData[member_id]" id="fldMemberId" value="">
                            <?php endif; ?>
                            <div class="input-group">
                                <input type="text" class="form-control disabled-clear" id="goodsNameDisplay"
                                       value="<?= htmlspecialchars($r['goods_name'] ?? '') ?>"
                                       placeholder="<?= $isEdit ? '' : '상품 찾기로 선택하세요' ?>" disabled>
                                <?php if (!$isEdit): ?>
                                <button type="button" class="btn btn-primary" id="orderPickerBtn"
                                        data-bs-toggle="modal" data-bs-target="#orderPickerModal">
                                    <i class="bi bi-search"></i> 상품 찾기
                                </button>
                                <?php elseif ($gid > 0): ?>
                                <a href="<?= htmlspecialchars($frontUrl) ?>" target="_blank" class="btn btn-outline-secondary" title="상품 보기">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <a href="/admin/shop/products/<?= $gid ?>/edit" target="_blank" data-no-active-code class="btn btn-outline-secondary" title="상품 관리">
                                    <i class="bi bi-gear"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">내용</label>
                            <textarea name="formData[content]" class="form-control" rows="18"
                                      placeholder="후기 내용"><?= htmlspecialchars($contentText) ?></textarea>

                            <div class="mt-3">
                                <label class="form-label fw-semibold">사진 (최대 3장)</label>
                                <div class="d-flex gap-2 flex-wrap" id="reviewImageSlots">
                                    <?php for ($i = 1; $i <= 3; $i++):
                                        $img = $r['image' . $i] ?? '';
                                    ?>
                                    <div class="review-img-slot" data-slot="<?= $i ?>"
                                         data-original="<?= htmlspecialchars($img) ?>" data-removed="0">
                                        <div class="review-img-visual"></div>
                                        <input type="file" name="fileData[image<?= $i ?>]" accept="image/*" style="display:none">
                                        <input type="hidden" name="removeImages[image<?= $i ?>]" value="1" disabled>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="form-text">JPG, PNG, WEBP, GIF (각 5MB 이하) · 신규 업로드는 좌상단 "신규" 배지, 삭제 예정은 흐리게 표시됩니다.</div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="col-lg-4">
                    <?php if (!$isEdit): ?>
                    <!-- 등록: 모달에서 후기 미작성 상품을 골라 작성 -->
                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">주문번호</label>
                            <input type="text" class="form-control disabled-clear" id="orderNoDisplay"
                                   placeholder="상품 선택 시 자동 입력" disabled>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">작성자</label>
                            <div id="buyerNameLabel" class="text-muted">상품 선택 시 자동 지정</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($isEdit): ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">주문번호</label>
                            <?php $orderNo = $r['order_no'] ?? ''; ?>
                            <div class="input-group">
                                <input type="text" class="form-control disabled-clear" id="orderNoInput"
                                       value="<?= htmlspecialchars($orderNo !== '' ? $orderNo : '-') ?>" disabled>
                                <?php if ($orderNo !== ''): ?>
                                <button type="button" class="btn btn-outline-secondary" id="copyOrderNoBtn"
                                        data-order-no="<?= htmlspecialchars($orderNo) ?>" title="주문번호 복사">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                                <a href="/admin/shop/orders/<?= rawurlencode($orderNo) ?>" target="_blank"
                                   data-no-active-code class="btn btn-outline-secondary" title="주문 관리">
                                    <i class="bi bi-gear"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">작성자</label>
                            <?php
                            $nick = $r['nickname'] ?? '';
                            $uid = $r['user_id'] ?? '';
                            $mid = (int)($r['member_id'] ?? 0);
                            $author = $nick !== '' ? $nick : ($uid !== '' ? $uid : '비회원');
                            ?>
                            <div>
                                <?= htmlspecialchars($author) ?>
                                <?php if ($nick !== '' && $uid !== ''): ?>
                                <small class="text-muted ms-1">(<?= htmlspecialchars($uid) ?>)</small>
                                <?php endif; ?>
                                <?php if ($mid > 0): ?>
                                <small>
                                    <a href="/admin/member/edit/<?= $mid ?>" target="_blank" data-no-active-code class="text-muted ms-1 text-decoration-none" title="회원 관리">
                                        <i class="bi bi-gear"></i>
                                    </a>
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold d-block">평점</label>
                            <div id="starRating"></div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col">
                                    <label class="form-label fw-semibold">공개</label>
                                    <select name="formData[is_visible]" class="form-select">
                                        <option value="1" <?= ((int)($r['is_visible'] ?? 1)) === 1 ? 'selected' : '' ?>>공개</option>
                                        <option value="0" <?= ((int)($r['is_visible'] ?? 1)) === 0 ? 'selected' : '' ?>>비공개</option>
                                    </select>
                                </div>
                                <div class="col">
                                    <label class="form-label fw-semibold">베스트</label>
                                    <select name="formData[is_best]" class="form-select">
                                        <option value="0" <?= ((int)($r['is_best'] ?? 0)) === 0 ? 'selected' : '' ?>>일반</option>
                                        <option value="1" <?= ((int)($r['is_best'] ?? 0)) === 1 ? 'selected' : '' ?>>베스트</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($isEdit):
                        $reply = $r['admin_reply'] ?? '';
                        $hasReply = $reply !== '';
                    ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-semibold mb-0">관리자 답변</label>
                                <button type="button" class="btn btn-sm btn-link link-primary p-0 lh-1" data-bs-toggle="modal" data-bs-target="#replyModal"
                                        id="replyEditBtn" title="<?= $hasReply ? '답변 수정' : '답변 작성' ?>">
                                    <i class="bi bi-chat-left-text"></i>
                                </button>
                            </div>
                            <div id="replyPreview" class="small text-muted"
                                 style="white-space:pre-wrap;max-height:6em;overflow:hidden;text-overflow:ellipsis"><?= $hasReply ? htmlspecialchars($reply) : '아직 답변하지 않았습니다.' ?></div>
                            <div class="form-text mt-2" id="replyDate"<?= empty($r['admin_reply_at']) ? ' style="display:none"' : '' ?>>
                                답변일: <?= !empty($r['admin_reply_at']) ? date('Y-m-d H:i', strtotime($r['admin_reply_at'])) : '' ?>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">상태</label>
                            <div>
                                <span id="replyStatusBadge" class="badge bg-<?= $hasReply ? 'success' : 'secondary' ?>">
                                    <?= $hasReply ? '완료' : '미답변' ?>
                                </span>
                            </div>
                            <div class="form-text mt-2">등록일: <?= substr($r['created_at'] ?? '', 0, 16) ?></div>
                            <?php if (!empty($r['updated_at']) && $r['updated_at'] !== $r['created_at']): ?>
                            <div class="form-text">수정일: <?= substr($r['updated_at'], 0, 16) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="/admin/shop/reviews" class="btn btn-secondary me-2"><i class="bi bi-list"></i> 목록</a>
                <button type="button" class="btn btn-primary" onclick="saveReview()">
                    <i class="bi bi-check-lg"></i> <?= $btnLabel ?>
                </button>
            </div>
        </div>
    </form>
</div>

<?php if (!$isEdit): ?>
<!-- 상품 선택 모달: 구매확정 주문의 후기 미작성 상품 -->
<div class="modal fade" id="orderPickerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 선택 <small class="text-muted">후기 미작성</small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="pickerSearchInput" placeholder="주문번호 / 상품명 검색">
                    <button type="button" class="btn btn-outline-secondary" id="pickerSearchBtn">
                        <i class="bi bi-search"></i> 검색
                    </button>
                </div>
                <div id="pickerItemList" class="d-flex flex-column gap-2"></div>
                <div id="pickerPagination" class="d-flex justify-content-center align-items-center gap-2 mt-3"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isEdit): ?>
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">관리자 답변</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <textarea id="replyTextarea" class="form-control" rows="8"
                          placeholder="답변을 입력하세요..."><?= htmlspecialchars($r['admin_reply'] ?? '') ?></textarea>
                <div class="form-text mt-2">이 답변은 저장 즉시 반영됩니다. (본문/사진 등 다른 항목과 별도 저장)</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto" id="replyDeleteBtn"
                        onclick="deleteReply()"<?= !$hasReply ? ' style="display:none"' : '' ?>>
                    <i class="bi bi-trash"></i> 삭제
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="saveReply()">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/assets/lib/star/star.js"></script>

<script>
new StarRating('#starRating', {
    mode: 'input', maxScore: 5, maxStars: 5, step: 1,
    rating: <?= (int)($r['rating'] ?? 5) ?>,
    inputName: 'formData[rating]', showScore: true, starSize: '2rem',
    scoreTextColor: 'var(--bs-body-color)'
});

function saveReview() {
<?php if (!$isEdit): ?>
    // 등록: 주문 상품을 선택해야 저장 가능
    var chosenDetail = document.getElementById('fldOrderDetailId').value;
    if (!chosenDetail || parseInt(chosenDetail, 10) <= 0) {
        MubloRequest.showToast('주문 찾기에서 주문과 상품을 선택하세요.', 'warning');
        return;
    }
<?php endif; ?>
    var fd = new FormData(document.getElementById('reviewForm'));
    MubloRequest.sendRequest({
        method: 'POST', url: '/admin/shop/reviews/store',
        payloadType: MubloRequest.PayloadType.FORM, data: fd,
    }).then(function() { location.href = '/admin/shop/reviews'; });
}

<?php if (!$isEdit): ?>
// ── 상품 선택 모달: 구매확정 주문의 후기 미작성 상품 (평면 목록) ──
(function () {
    var esc = MubloRequest.escapeHtml;
    var modalEl = document.getElementById('orderPickerModal');
    var searchInput = document.getElementById('pickerSearchInput');
    var searchBtn = document.getElementById('pickerSearchBtn');
    var listBox = document.getElementById('pickerItemList');
    var pagerBox = document.getElementById('pickerPagination');

    var curKeyword = '';
    var loaded = false;

    function buyerLabelText(name, isMember) {
        if (isMember) return name || '회원';
        return name ? name + ' (비회원)' : '비회원';
    }

    function load(page) {
        MubloRequest.requestQuery('/admin/shop/reviews/reviewable-items', { keyword: curKeyword, page: page })
            .then(function (res) { render(res.data || {}); });
    }

    function render(data) {
        var items = data.items || [];
        listBox.innerHTML = '';
        if (!items.length) {
            listBox.innerHTML = '<div class="text-muted small text-center py-4">후기를 작성할 수 있는 상품이 없습니다.</div>';
            pagerBox.innerHTML = '';
            return;
        }
        items.forEach(function (item) {
            var card = document.createElement('div');
            card.className = 'order-item-card card p-2 flex-row align-items-center gap-2';
            card.style.cursor = 'pointer';
            var opt = item.option_name ? ' <span class="text-muted small">(' + esc(item.option_name) + ')</span>' : '';
            var thumb = item.goods_image
                ? '<img src="' + esc(item.goods_image) + '" alt="" width="44" height="44" style="object-fit:cover;border-radius:.25rem;flex:0 0 auto">'
                : '<span class="bg-body-secondary d-inline-flex align-items-center justify-content-center" style="width:44px;height:44px;border-radius:.25rem;flex:0 0 auto"><i class="bi bi-box"></i></span>';
            card.innerHTML = thumb
                + '<div class="flex-grow-1" style="min-width:0">'
                + '<div class="text-truncate">' + esc(item.goods_name) + opt + '</div>'
                + '<div class="text-muted small">' + esc(item.order_no) + ' · '
                + esc(buyerLabelText(item.buyer_name, item.is_member)) + ' · ' + esc(item.created_at) + '</div>'
                + '</div>'
                + '<i class="bi bi-chevron-right ms-auto text-muted flex-shrink-0"></i>';
            card.addEventListener('click', function () { chooseItem(item); });
            listBox.appendChild(card);
        });
        renderPager(data.pagination || {});
    }

    function renderPager(p) {
        pagerBox.innerHTML = '';
        var cur = p.currentPage || 1;
        var total = p.totalPages || 1;
        if (total <= 1) return;
        var mk = function (label, page, disabled) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-outline-secondary';
            b.innerHTML = label;
            b.disabled = disabled;
            if (!disabled) b.addEventListener('click', function () { load(page); });
            return b;
        };
        pagerBox.appendChild(mk('<i class="bi bi-chevron-left"></i>', cur - 1, cur <= 1));
        var info = document.createElement('span');
        info.className = 'small text-muted';
        info.textContent = cur + ' / ' + total;
        pagerBox.appendChild(info);
        pagerBox.appendChild(mk('<i class="bi bi-chevron-right"></i>', cur + 1, cur >= total));
    }

    // ── 선택 확정: 폼에 반영 + 모달 닫기 ──
    function chooseItem(item) {
        document.getElementById('fldGoodsId').value = item.goods_id;
        document.getElementById('fldOrderNo').value = item.order_no;
        document.getElementById('fldOrderDetailId').value = item.order_detail_id;
        document.getElementById('fldMemberId').value = item.member_id;
        document.getElementById('goodsNameDisplay').value = item.goods_name;
        document.getElementById('orderNoDisplay').value = item.order_no;

        var buyerEl = document.getElementById('buyerNameLabel');
        buyerEl.textContent = buyerLabelText(item.buyer_name, item.is_member);
        buyerEl.classList.remove('text-muted');
        buyerEl.classList.add('fw-semibold');

        bootstrap.Modal.getInstance(modalEl)?.hide();
    }

    // ── 이벤트 배선 ──
    function doSearch() { curKeyword = searchInput.value.trim(); load(1); }
    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });

    // 모달 최초 오픈 시 1회 로드 (재오픈 시 목록 유지)
    modalEl.addEventListener('show.bs.modal', function () {
        if (!loaded) { loaded = true; load(1); }
    });
})();
<?php endif; ?>

<?php if ($isEdit): ?>
function applyReplyState(content, dateStr) {
    var preview = document.getElementById('replyPreview');
    var dateEl = document.getElementById('replyDate');
    var btn = document.getElementById('replyEditBtn');
    var badge = document.getElementById('replyStatusBadge');
    var delBtn = document.getElementById('replyDeleteBtn');
    var has = content !== '';
    preview.textContent = has ? content : '아직 답변하지 않았습니다.';
    btn.title = has ? '답변 수정' : '답변 작성';
    if (has) {
        dateEl.textContent = '답변일: ' + dateStr;
        dateEl.style.display = '';
        badge.className = 'badge bg-success-subtle text-success-emphasis border border-success-subtle';
        badge.textContent = '완료';
        delBtn.style.display = '';
    } else {
        dateEl.style.display = 'none';
        badge.className = 'badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
        badge.textContent = '미답변';
        delBtn.style.display = 'none';
        document.getElementById('replyTextarea').value = '';
    }
}

function currentTimestamp() {
    var d = new Date();
    var p = function(n) { return n < 10 ? '0' + n : n; };
    return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

function saveReply() {
    var content = document.getElementById('replyTextarea').value.trim();
    if (content === '') {
        if (!confirm('내용이 비어있습니다. 답변을 삭제하시겠습니까?')) return;
    }
    MubloRequest.requestJson('/admin/shop/reviews/reply', {
        review_id: <?= (int)$r['review_id'] ?>,
        admin_reply: content
    }).then(function() {
        applyReplyState(content, currentTimestamp());
        bootstrap.Modal.getInstance(document.getElementById('replyModal'))?.hide();
    });
}

function deleteReply() {
    if (!confirm('답변을 삭제하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/shop/reviews/reply', {
        review_id: <?= (int)$r['review_id'] ?>,
        admin_reply: ''
    }).then(function() {
        applyReplyState('', '');
        bootstrap.Modal.getInstance(document.getElementById('replyModal'))?.hide();
    });
}
<?php endif; ?>

// 사진 슬롯 — 등록/수정 모두에서 배선 (슬롯 마크업은 항상 렌더됨)
function reviewSlotRender(n) {
    const slot = document.querySelector('.review-img-slot[data-slot="' + n + '"]');
    if (!slot) return;
    const visual = slot.querySelector('.review-img-visual');
    const fileInput = slot.querySelector('input[type=file]');
    const removeInput = slot.querySelector('input[name^="removeImages"]');
    const original = slot.dataset.original || '';
    const removed = slot.dataset.removed === '1';
    const newFile = fileInput.files[0] || null;

    removeInput.disabled = !removed;

    visual.innerHTML = '';
    let imgSrc = '', showBadge = false, opacity = 1, btnIcon = null;
    if (newFile) {
        imgSrc = URL.createObjectURL(newFile);
        showBadge = true;
        btnIcon = removed ? 'arrow-counterclockwise' : 'x';
    } else if (original) {
        imgSrc = original;
        opacity = removed ? 0.3 : 1;
        btnIcon = removed ? 'arrow-counterclockwise' : 'x';
    }

    if (imgSrc) {
        const img = document.createElement('img');
        img.src = imgSrc;
        if (opacity !== 1) img.style.opacity = opacity;
        visual.appendChild(img);

        const zoom = document.createElement('a');
        zoom.className = 'review-img-zoom';
        zoom.href = imgSrc;
        zoom.target = '_blank';
        zoom.rel = 'noopener';
        zoom.title = '크게 보기';
        zoom.innerHTML = '<i class="bi bi-zoom-in"></i>';
        zoom.addEventListener('click', function(e) { e.stopPropagation(); });
        visual.appendChild(zoom);

        if (showBadge) {
            const badge = document.createElement('span');
            badge.className = 'review-img-badge';
            badge.textContent = '신규';
            visual.appendChild(badge);
        }
        if (btnIcon) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'review-img-btn';
            btn.title = btnIcon === 'x' ? '삭제' : '복원';
            btn.innerHTML = '<i class="bi bi-' + btnIcon + '"></i>';
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                reviewSlotAction(n);
            });
            visual.appendChild(btn);
        }
    } else {
        const plus = document.createElement('i');
        plus.className = 'bi bi-plus-lg review-img-plus';
        visual.appendChild(plus);
    }
}

function reviewSlotAction(n) {
    const slot = document.querySelector('.review-img-slot[data-slot="' + n + '"]');
    const fileInput = slot.querySelector('input[type=file]');
    const hasFile = !!fileInput.files[0];
    const removed = slot.dataset.removed === '1';
    if (hasFile) {
        fileInput.value = '';
        if (removed) slot.dataset.removed = '0';
    } else if (slot.dataset.original) {
        slot.dataset.removed = removed ? '0' : '1';
    }
    reviewSlotRender(n);
}

document.querySelectorAll('.review-img-slot').forEach(function(slot) {
    const fileInput = slot.querySelector('input[type=file]');
    const n = parseInt(slot.dataset.slot);
    fileInput.addEventListener('change', function() { reviewSlotRender(n); });
    slot.addEventListener('click', function(e) {
        if (e.target.closest('.review-img-btn')) return;
        if (e.target.closest('.review-img-zoom')) return;
        if (fileInput.files[0]) return;
        fileInput.click();
    });
    reviewSlotRender(n);
});

<?php if ($isEdit): ?>
document.getElementById('copyOrderNoBtn')?.addEventListener('click', function() {
    var btn = this;
    var icon = btn.querySelector('i');
    navigator.clipboard.writeText(btn.dataset.orderNo).then(function() {
        icon.className = 'bi bi-clipboard-check text-success';
        setTimeout(function() { icon.className = 'bi bi-clipboard'; }, 1500);
    });
});
<?php endif; ?>
</script>
