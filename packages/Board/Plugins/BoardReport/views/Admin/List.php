<?php
/**
 * 신고 관리 (관리자)
 *
 * @var string $pageTitle
 * @var array  $items       신고 목록 (+ reason_label, is_blinded, report_count, article_exists)
 * @var int    $total
 * @var string $status      필터 (''|pending|resolved|dismissed)
 * @var array  $reasons
 * @var int    $startNumber 목록 번호 시작값 (내림차순 표시용)
 * @var array  $pagination  [currentPage, totalPages, totalItems]
 */
$items = $items ?? [];
$number = (int) ($startNumber ?? count($items));
// 표시 라벨은 DB 값과 별개다. 신고 판정 축이므로 인용 ↔ 기각으로 대칭시킨다
$statusLabels = ['pending' => '대기', 'resolved' => '인용', 'dismissed' => '기각'];
$statusBadge = [
    'pending'   => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
    'resolved'  => 'bg-success-subtle text-success-emphasis border border-success-subtle',
    'dismissed' => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '게시글 신고 관리') ?></h3>
            <p>신고된 글을 확인하고 조치합니다.</p>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/board/report/list">전체</a></span>
                        <span class="ov-num"><b><?= number_format((int) $total) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">상태: 전체</option>
                                <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- 신고 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width:40px" class="text-center text-nowrap">
                                <input type="checkbox" name="chk_all" class="form-check-input">
                            </th>
                            <th style="width:60px" class="text-nowrap">번호</th>
                            <th class="br-article-td">게시글</th>
                            <th style="width:110px" class="text-center text-nowrap">사유</th>
                            <th style="width:60px" class="text-center text-nowrap">누적</th>
                            <th style="width:130px" class="text-nowrap">신고자</th>
                            <th style="width:100px" class="text-center text-nowrap">일시</th>
                            <th style="width:80px" class="text-center text-nowrap">상태</th>
                            <th style="width:220px" class="text-nowrap">조치</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">접수된 신고가 없습니다.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="text-center text-nowrap">
                                <input type="checkbox" name="chk[]" class="form-check-input" value="<?= (int) $item['report_id'] ?>">
                            </td>
                            <td class="text-nowrap text-muted"><?= $number-- ?></td>
                            <td class="br-article-td">
                                <?php if (!empty($item['article_exists'])): ?>
                                <a href="/admin/board/article/view?id=<?= (int) $item['article_id'] ?>&amp;activeCode=K_Board_005"
                                   target="_blank" class="d-flex align-items-center gap-1 text-body text-decoration-none"
                                   title="게시글 보기 (새 창) — 블라인드 상태여도 관리자는 열람할 수 있습니다">
                                    <span class="br-article-title text-truncate"><?= htmlspecialchars($item['article_title']) ?></span>
                                    <i class="bi bi-box-arrow-up-right small text-muted flex-shrink-0"></i>
                                </a>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="/admin/board/article/edit?id=<?= (int) $item['article_id'] ?>&amp;activeCode=K_Board_005"
                                       target="_blank" class="small text-muted text-decoration-none" title="게시글 수정 (새 창)">
                                        <i class="bi bi-pencil-square"></i> 수정
                                    </a>
                                    <?php if ($item['is_blinded']): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">블라인드</span>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-truncate"><?= htmlspecialchars($item['article_title']) ?></div>
                                <div>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">삭제된 글</span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($item['detail'])): ?>
                                <div class="text-muted small text-truncate"><?= htmlspecialchars(mb_substr($item['detail'], 0, 120)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                    <?= htmlspecialchars($item['reason_label']) ?>
                                </span>
                            </td>
                            <td class="text-center text-nowrap"><?= (int) $item['report_count'] ?>건</td>
                            <td class="small text-muted text-nowrap">
                                <?= $item['reporter_id'] !== null ? '회원 #' . (int) $item['reporter_id'] : htmlspecialchars((string) ($item['reporter_ip'] ?? '')) ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <div><small><?= htmlspecialchars(substr((string) $item['created_at'], 0, 10)) ?></small></div>
                                <div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars(substr((string) $item['created_at'], 11, 5)) ?></div>
                            </td>
                            <td class="text-center text-nowrap">
                                <span class="badge <?= $statusBadge[$item['status']] ?? $statusBadge['dismissed'] ?>">
                                    <?= $statusLabels[$item['status']] ?? $item['status'] ?>
                                </span>
                            </td>
                            <!-- 신고 축(기각) → 글 축(블라인드·삭제) 순.
                                 왼쪽에서 오른쪽으로 강도가 올라가고, 복구 불가능한 삭제가 맨 끝 -->
                            <td class="text-nowrap">
                                <?php if ($item['status'] === 'pending'): ?>
                                <button type="button" class="btn btn-sm btn-default br-status"
                                        data-id="<?= (int) $item['report_id'] ?>" data-status="dismissed"
                                        title="신고가 부당합니다 — 조치 없이 종결합니다">기각</button>
                                <?php endif; ?>
                                <?php if (!empty($item['article_exists'])): ?>
                                    <?php if ($item['is_blinded']): ?>
                                    <button type="button" class="btn btn-sm btn-default br-blind"
                                            data-article="<?= (int) $item['article_id'] ?>" data-blind="0"
                                            title="방문자에게 다시 보이게 합니다">블라인드 해제</button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-default br-blind"
                                            data-article="<?= (int) $item['article_id'] ?>" data-blind="1"
                                            title="방문자에게 숨깁니다. 대기 신고가 인용으로 전환됩니다">블라인드</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-default br-delete"
                                            data-article="<?= (int) $item['article_id'] ?>"
                                            title="게시글을 삭제합니다. 대기 신고가 인용으로 전환됩니다">글 삭제</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default" data-bulk="dismiss">선택 기각</button>
                        <button type="button" class="btn btn-sm btn-default" data-bulk="blind">선택 블라인드</button>
                        <button type="button" class="btn btn-sm btn-default" data-bulk="delete">선택 글 삭제</button>
                    </div>
                </div>
                <div class="col-auto">
                    <?= $this->pagination($pagination) ?>
                </div>
            </div>
        </form>
    </div>

    <!-- 안내 문구 -->
    <div class="page-block">
        <div class="alert alert-secondary small">
            <div class="fw-semibold">조치</div>
            <ul class="mb-2">
                <li><strong>기각</strong> — 신고를 인정하지 않고 종결합니다. 조치 없이 상태만 바뀝니다.</li>
                <li><strong>블라인드</strong> — 방문자에게 글을 숨깁니다. 관리자는 신고 판단을 위해 계속 열람할 수 있습니다.</li>
                <li><strong>글 삭제</strong> — 게시글을 삭제합니다. 신고 이력은 감사 추적을 위해 목록에 남습니다.</li>
            </ul>
            <div class="fw-semibold">상태</div>
            <ul class="mb-2">
                <li><strong>대기</strong> — 아직 판정하지 않은 신고입니다.</li>
                <li><strong>인용</strong> — 신고가 타당하다고 인정한 상태입니다. 블라인드·삭제 조치 시 그 글의 대기 신고가 자동으로 전환됩니다.</li>
                <li><strong>기각</strong> — 신고를 인정하지 않은 상태입니다.</li>
            </ul>
            <div>
                상태는 <strong>신고</strong>에 대한 판정이고, 블라인드는 <strong>게시글</strong>에 대한 조치라
                서로 독립입니다. 신고 사유는 근거가 없지만 다른 이유로 글을 숨기는 경우처럼
                <strong>기각 + 블라인드</strong> 조합도 정상입니다.
            </div>
        </div>
    </div>
</div>

<style>
/* 게시글 셀: 나머지 열이 고정폭이므로 max-width:0 으로 남는 폭만 차지하게 하고,
   제목이 길어도 열이 늘어나지 않도록 셀 안에서 말줄임한다 (Shop 상품명 셀 패턴).
   min-width 는 max-width 보다 우선하므로 이 열은 240px 아래로 줄지 않고,
   좁은 화면에서는 .table-responsive 가 가로 스크롤로 넘긴다 (찌그러뜨리지 않음) */
.br-article-td { max-width: 0; min-width: 240px; }
.br-article-title { min-width: 0; } /* flex 자식이 콘텐츠 폭 아래로 줄어들 수 있게 */
</style>
<script>
(function () {
    function post(url, data) {
        MubloRequest.requestJson(url, data).then(function (res) {
            if (res.result === 'success') {
                MubloRequest.showToast(res.message || '처리되었습니다.', 'success');
                setTimeout(function () { location.reload(); }, 600);
            } else {
                MubloRequest.showAlert(res.message || '처리에 실패했습니다.', 'error');
            }
        });
    }

    // 전체 선택
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var on = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = on; });
    });

    function checkedIds() {
        return [...document.querySelectorAll('input[name="chk[]"]:checked')].map(function (cb) { return cb.value; });
    }

    // 일괄 조치
    var bulkConfig = {
        blind:   { confirm: '선택한 신고의 게시글을 블라인드 처리할까요?\n방문자에게 보이지 않게 되고, 대기 신고는 인용으로 전환됩니다.', type: 'warning' },
        'delete': { confirm: '선택한 신고의 게시글을 삭제할까요?\n삭제된 글은 복구할 수 없습니다.', type: 'error' },
        dismiss: { confirm: '선택한 신고를 기각할까요?\n조치 없이 종결됩니다.', type: 'warning' }
    };
    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = this.dataset.bulk;
            var ids = checkedIds();
            if (!ids.length) {
                MubloRequest.showAlert('먼저 목록에서 신고를 선택하세요.', 'warning');
                return;
            }
            MubloRequest.showConfirm(bulkConfig[action].confirm, function () {
                post('/admin/board/report/bulk', { action: action, report_ids: ids });
            }, { type: bulkConfig[action].type });
        });
    });

    // 개별 조치
    document.querySelectorAll('.br-status').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.dataset.id;
            MubloRequest.showConfirm('이 신고를 기각할까요?\n조치 없이 종결됩니다.', function () {
                post('/admin/board/report/status', { report_id: id, status: 'dismissed' });
            }, { type: 'warning' });
        });
    });

    document.querySelectorAll('.br-blind').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var blind = this.dataset.blind === '1';
            var articleId = this.dataset.article;
            MubloRequest.showConfirm(
                blind
                    ? '이 게시글을 블라인드 처리할까요?\n방문자에게 보이지 않게 되고, 대기 신고는 인용으로 전환됩니다.'
                    : '블라인드를 해제할까요?\n방문자에게 다시 보이게 됩니다.',
                function () { post('/admin/board/report/blind', { article_id: articleId, blind: blind ? '1' : '0' }); },
                { type: 'warning' }
            );
        });
    });

    document.querySelectorAll('.br-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var articleId = this.dataset.article;
            MubloRequest.showConfirm('이 게시글을 삭제할까요?\n삭제된 글은 복구할 수 없습니다.', function () {
                post('/admin/board/report/delete-article', { article_id: articleId });
            }, { type: 'error' });
        });
    });
})();
</script>
