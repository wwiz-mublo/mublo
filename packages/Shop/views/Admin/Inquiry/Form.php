<?php
/**
 * 상품문의 등록/수정
 *
 * @var string $pageTitle
 * @var array|null $inquiry
 */
$q = $inquiry ?? [];
$isEdit = !empty($q['inquiry_id']);
$btnLabel = $isEdit ? '수정' : '등록';
$statusLabels = ['WAITING' => '대기', 'REPLIED' => '답변완료', 'CLOSED' => '종료'];
$statusColors = ['WAITING' => 'warning', 'REPLIED' => 'success', 'CLOSED' => 'secondary'];
$typeLabels = ['PRODUCT' => '상품', 'STOCK' => '재고', 'DELIVERY' => '배송', 'OTHER' => '기타'];
?>
<?php
// 기존 HTML 콘텐츠를 일반텍스트로 변환 (에디터로 저장됐던 레코드 호환)
$contentRaw = $q['content'] ?? '';
$contentText = trim(html_entity_decode(strip_tags(
    preg_replace(['#<br\s*/?>#i', '#</p>#i'], ["\n", "\n\n"], $contentRaw)
), ENT_QUOTES, 'UTF-8'));
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>상품 문의를 <?= $isEdit ? '수정' : '등록' ?>합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/inquiries" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveInquiry()">
                <i class="bi bi-check-lg"></i> <?= $btnLabel ?>
            </button>
        </div>
    </div>

    <form id="inquiryForm" enctype="multipart/form-data">
        <input type="hidden" name="formData[inquiry_id]" value="<?= (int)($q['inquiry_id'] ?? 0) ?>">

        <div class="page-block">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">상품</label>
                            <?php
                                $gid = (int)($q['goods_id'] ?? 0);
                            ?>
                            <div data-product-picker>
                                <input type="hidden" name="formData[goods_id]" data-picker-id value="<?= $gid ?>">
                                <div class="input-group">
                                    <input type="text" class="form-control" data-picker-name readonly
                                           value="<?= htmlspecialchars($q['goods_name'] ?? '') ?>"
                                           placeholder="상품을 검색하세요">
                                    <button type="button" class="btn btn-outline-secondary" data-picker-search>
                                        <i class="bi bi-search"></i> 상품 찾기
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary d-none" data-picker-clear title="이전 상품으로 되돌리기">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                    <a href="#" target="_blank" data-picker-view class="btn btn-outline-secondary d-none" title="상품 보기">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <a href="#" target="_blank" data-no-active-code data-picker-manage class="btn btn-outline-secondary d-none" title="상품 관리">
                                        <i class="bi bi-gear"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="form-text">등록 시 상품을 선택하세요. 수정 시 다른 상품으로 교체할 수 있습니다.</div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">제목</label>
                                <input type="text" name="formData[title]" class="form-control"
                                       value="<?= htmlspecialchars($q['title'] ?? '') ?>">
                            </div>
                            <div>
                                <label class="form-label fw-semibold">내용</label>
                                <textarea name="formData[content]" class="form-control" rows="18"
                                          placeholder="문의 내용"><?= htmlspecialchars($contentText) ?></textarea>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="col-lg-4">
                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">작성자</label>
                            <?php if ($isEdit):
                                $nick = $q['nickname'] ?? '';
                                $uid = $q['user_id'] ?? '';
                                $mid = (int)($q['member_id'] ?? 0);
                                $author = $nick !== '' ? $nick : ($uid !== '' ? $uid : ($q['author_name'] ?? '-'));
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
                            <?php else: ?>
                            <input type="text" name="formData[author_name]" class="form-control" placeholder="작성자명">
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">문의 유형</label>
                            <?php if ($isEdit): ?>
                            <div><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><?= $typeLabels[$q['inquiry_type'] ?? ''] ?? ($q['inquiry_type'] ?? '-') ?></span></div>
                            <?php else: ?>
                            <select name="formData[inquiry_type]" class="form-select">
                                <?php foreach ($typeLabels as $val => $label): ?>
                                <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">비밀글</label>
                            <select name="formData[is_secret]" class="form-select">
                                <option value="0" <?= ((int)($q['is_secret'] ?? 0)) === 0 ? 'selected' : '' ?>>공개</option>
                                <option value="1" <?= ((int)($q['is_secret'] ?? 0)) === 1 ? 'selected' : '' ?>>비밀글</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($isEdit):
                        $reply = $q['reply'] ?? '';
                        $hasReply = $reply !== '';
                        $st = $q['inquiry_status'] ?? 'WAITING';
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
                            <div class="form-text mt-2" id="replyDate"<?= empty($q['replied_at']) ? ' style="display:none"' : '' ?>>
                                답변일: <?= !empty($q['replied_at']) ? date('Y-m-d H:i', strtotime($q['replied_at'])) : '' ?>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <label class="form-label fw-semibold">상태</label>
                            <div>
                                <span id="inquiryStatusBadge" class="badge bg-<?= $statusColors[$st] ?? 'secondary' ?>"><?= $statusLabels[$st] ?? $st ?></span>
                            </div>
                            <div class="form-text mt-2">등록일: <?= substr($q['created_at'] ?? '', 0, 16) ?></div>
                            <?php if (!empty($q['updated_at']) && $q['updated_at'] !== $q['created_at']): ?>
                            <div class="form-text">수정일: <?= substr($q['updated_at'], 0, 16) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="/admin/shop/inquiries" class="btn btn-secondary me-2"><i class="bi bi-list"></i> 목록</a>
                <button type="button" class="btn btn-primary" onclick="saveInquiry()">
                    <i class="bi bi-check-lg"></i> <?= $btnLabel ?>
                </button>
            </div>
        </div>
    </form>
</div>

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
                          placeholder="답변을 입력하세요..."><?= htmlspecialchars($q['reply'] ?? '') ?></textarea>
                <div class="form-text mt-2">이 답변은 저장 즉시 반영됩니다. (본문/제목 등 다른 항목과 별도 저장)</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto" id="replyDeleteBtn"
                        onclick="deleteAnswer()"<?= !$hasReply ? ' style="display:none"' : '' ?>>
                    <i class="bi bi-trash"></i> 삭제
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="saveAnswer()">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/serve/package/Shop/assets/js/admin-product-picker.js"></script>
<script>
function saveInquiry() {
    var fd = new FormData(document.getElementById('inquiryForm'));
    MubloRequest.sendRequest({
        method: 'POST', url: '/admin/shop/inquiries/store',
        payloadType: MubloRequest.PayloadType.FORM, data: fd,
    }).then(function() { location.href = '/admin/shop/inquiries'; });
}

<?php if ($isEdit):
    $statusLabelsJson = json_encode($statusLabels, JSON_UNESCAPED_UNICODE);
    $statusColorsJson = json_encode($statusColors, JSON_UNESCAPED_UNICODE);
?>
const inquiryStatusLabels = <?= $statusLabelsJson ?>;
const inquiryStatusColors = <?= $statusColorsJson ?>;

function applyAnswerState(content, dateStr, nextStatus) {
    var preview = document.getElementById('replyPreview');
    var dateEl = document.getElementById('replyDate');
    var btn = document.getElementById('replyEditBtn');
    var badge = document.getElementById('inquiryStatusBadge');
    var delBtn = document.getElementById('replyDeleteBtn');
    var has = content !== '';
    preview.textContent = has ? content : '아직 답변하지 않았습니다.';
    btn.title = has ? '답변 수정' : '답변 작성';
    if (has) {
        dateEl.textContent = '답변일: ' + dateStr;
        dateEl.style.display = '';
        delBtn.style.display = '';
    } else {
        dateEl.style.display = 'none';
        delBtn.style.display = 'none';
        document.getElementById('replyTextarea').value = '';
    }
    if (badge) {
        var color = inquiryStatusColors[nextStatus] || 'secondary';
        badge.className = 'badge bg-' + color;
        badge.textContent = inquiryStatusLabels[nextStatus] || nextStatus;
    }
}

function currentTimestamp() {
    var d = new Date();
    var p = function(n) { return n < 10 ? '0' + n : n; };
    return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

function saveAnswer() {
    var content = document.getElementById('replyTextarea').value.trim();
    if (content === '') {
        if (!confirm('내용이 비어있습니다. 답변을 삭제하시겠습니까?')) return;
    }
    MubloRequest.requestJson('/admin/shop/inquiries/answer', {
        inquiry_id: <?= (int)$q['inquiry_id'] ?>,
        reply: content
    }).then(function() {
        applyAnswerState(content, currentTimestamp(), content !== '' ? 'REPLIED' : 'WAITING');
        bootstrap.Modal.getInstance(document.getElementById('replyModal'))?.hide();
    });
}

function deleteAnswer() {
    if (!confirm('답변을 삭제하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/shop/inquiries/answer', {
        inquiry_id: <?= (int)$q['inquiry_id'] ?>,
        reply: ''
    }).then(function() {
        applyAnswerState('', '', 'WAITING');
        bootstrap.Modal.getInstance(document.getElementById('replyModal'))?.hide();
    });
}
<?php endif; ?>
</script>
