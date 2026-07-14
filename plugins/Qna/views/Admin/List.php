<?php
/**
 * Q&A 관리 - 목록
 *
 * 회원 관리(/admin/member) 컨벤션: page-container / page-title / ov 검색 /
 * $this->columns() + $this->listRenderHelper + $this->pagination().
 *
 * @var string $pageTitle
 * @var array  $items       질문(헤드) 행 목록
 * @var array  $pagination  {totalItems, perPage, currentPage, totalPages}
 * @var array  $filters     {status, keyword}
 * @var array  $statuses    InquiryStatus::options()
 * @var array  $skins       사용 가능한 프론트 스킨 목록
 * @var string $currentSkin 현재 적용된 스킨
 */
use Mublo\Plugin\Qna\Enum\AuthorType;
use Mublo\Plugin\Qna\Enum\InquiryStatus;

$currentStatus  = $filters['status'] ?? '';
$currentKeyword = $filters['keyword'] ?? '';
$totalItems     = $pagination['totalItems'] ?? 0;
$skins          = $skins ?? ['basic'];
$currentSkin    = $currentSkin ?? 'basic';

// 상태 라벨 맵 (enum 단일 소스)
$statusLabels = [];
foreach (InquiryStatus::cases() as $st) {
    $statusLabels[$st->value] = $st->label();
}

// 일시 2행 렌더 (1행 일자, 2행 시간)
$renderDateTime = function ($value): string {
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '<span class="text-muted">-</span>';
    }
    $parts = explode(' ', $value, 2);
    return '<div><small>' . htmlspecialchars($parts[0]) . '</small></div>'
        . '<div class="text-muted" style="font-size:.75rem">' . htmlspecialchars(substr($parts[1] ?? '', 0, 5)) . '</div>';
};

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'post_id', '_th_attr' => ['class' => 'text-center', 'style' => 'width:40px'], '_td_attr' => ['class' => 'text-center']])
    ->add('post_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('title', '제목', function ($row) {
        $id    = (int) $row['post_id'];
        $title = htmlspecialchars($row['title'] ?? '(제목 없음)');
        $lock  = !empty($row['is_secret'])
            ? '<i class="bi bi-lock-fill text-warning" title="비밀글"></i>'
            : '';
        $html  = $lock . '<a href="/admin/qna/' . $id . '/edit" class="text-truncate">' . $title . '</a>';

        // 질문 첨부 존재 표시 (meta.attachments)
        $meta = json_decode((string) ($row['meta'] ?? ''), true);
        $attCount = is_array($meta['attachments'] ?? null) ? count($meta['attachments']) : 0;
        if ($attCount > 0) {
            $html .= '<i class="bi bi-paperclip text-muted" title="첨부 ' . $attCount . '개"></i>';
        }

        if ((int) ($row['reply_count'] ?? 0) > 0) {
            $html .= '<span class="text-primary small">[' . (int) $row['reply_count'] . ']</span>';
        }

        // 2행: 본문 일부 (태그 제거 후 말줄임)
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($row['content'] ?? ''))));
        if ($excerpt !== '' && mb_strlen($excerpt) > 60) {
            $excerpt = mb_substr($excerpt, 0, 60) . '…';
        }
        $sub = $excerpt === '' ? '' : '<div class="text-muted small text-truncate">' . htmlspecialchars($excerpt) . '</div>';

        return '<div class="d-flex gap-2 align-items-center">' . $html . '</div>' . $sub;
    }, ['_td_attr' => ['class' => 'qna-title-cell']])
    ->callback('author', '작성', function ($row) {
        // 1행: 작성자. author_name 은 작성 시점 닉네임 스냅샷(회원가입 이름 폴백)이며
        // members 테이블과 join 하지 않는다 — 회원이 이후 닉네임을 바꿔도 옛 값이 남는다.
        // 스냅샷이 비어 있을 때만 member_id 로 '회원 #N', 그것도 없으면 '비회원'.
        // 문의자는 역할 구분이 없으므로 최종 작성 열과 달리 뱃지를 쓰지 않는다.
        $name = trim((string) ($row['author_name'] ?? ''));
        if ($name === '') {
            $mid  = (int) ($row['member_id'] ?? 0);
            $name = $mid > 0 ? '회원 #' . $mid : '비회원';
        }
        // 2행: 작성일 (단일 라인)
        $created = trim((string) ($row['created_at'] ?? ''));
        $date = ($created === '' || str_starts_with($created, '0000-00-00'))
            ? ''
            : '<div class="text-muted small">' . htmlspecialchars(substr($created, 0, 16)) . '</div>';
        return '<div><small>' . htmlspecialchars($name) . '</small></div>' . $date;
    }, ['_th_attr' => ['style' => 'width:150px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('last_reply', '최종 작성', function ($row) {
        $role = (string) ($row['last_reply_role'] ?? '');
        $at   = trim((string) ($row['last_reply_at'] ?? ''));
        if ($role === '' && $at === '') {
            return '<span class="text-muted">-</span>';
        }
        // 1행: 작성자 배지
        $badge = '';
        if ($role !== '') {
            $label = htmlspecialchars(AuthorType::tryFrom($role)?->label() ?? $role);
            $cls = $role === 'admin'
                ? 'bg-primary-subtle text-primary-emphasis border border-primary-subtle'
                : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
            $badge = '<span class="badge ' . $cls . '">' . $label . '</span>';
        }
        // 2행: 작성일 (단일 라인)
        $date = ($at === '' || str_starts_with($at, '0000-00-00'))
            ? ''
            : '<div class="text-muted small">' . htmlspecialchars(substr($at, 0, 16)) . '</div>';
        return '<div>' . $badge . '</div>' . $date;
    }, ['_th_attr' => ['style' => 'width:150px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->select('status', '상태', $statusLabels, ['id_key' => 'post_id', '_el_attr' => ['class' => 'form-select form-select-sm'], '_th_attr' => ['style' => 'width:110px']])
    ->actions('actions', '관리', function ($row) {
        $id    = (int) $row['post_id'];
        $title = htmlspecialchars($row['title'] ?? '', ENT_QUOTES);
        return '<a href="/admin/qna/' . $id . '/edit" class="btn btn-sm btn-default" title="상세/답변"><i class="bi bi-chat-left-text"></i></a>'
            . ' <button type="button" class="btn btn-sm btn-default btn-delete-qna" data-id="' . $id . '" data-title="' . $title . '" title="삭제"><i class="bi bi-trash"></i></button>';
    }, ['_th_attr' => ['style' => 'width:90px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? 'Q&A 관리') ?></h3>
            <p>등록된 질문을 조회하고 답변합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/qna" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-box-arrow-up-right"></i> Q&A 보기
            </a>
        </div>
    </div>

    <!-- 스킨 설정 -->
    <div class="page-block">
        <div class="card">
            <div class="card-body py-2">
                <form id="skinConfigForm" class="row align-items-center g-2">
                    <div class="col-auto">
                        <label class="form-label mb-0 fw-bold" for="skinSelect"><i class="bi bi-palette"></i> 프론트 스킨</label>
                    </div>
                    <div class="col-auto">
                        <select id="skinSelect" class="form-select form-select-sm" style="width:auto">
                            <?php foreach ($skins as $skin): ?>
                            <option value="<?= htmlspecialchars($skin) ?>"<?= $skin === $currentSkin ? ' selected' : '' ?>>
                                <?= htmlspecialchars($skin) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary btn-sm" onclick="saveQnaSkin()">저장</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 검색 영역 -->
    <form method="get" name="fsearch" id="fsearch" class="mb-2">
        <div class="row align-items-center gy-2 gy-xl-0">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/qna">전체</a></span>
                    <span class="ov-num"><b><?= number_format($totalItems) ?></b> 건</span>
                </span>
            </div>
            <div class="col col-xl-auto ms-xl-auto">
                <div class="row gx-2">
                    <div class="col-auto">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">전체 상태</option>
                            <?php foreach ($statusLabels as $val => $label): ?>
                                <option value="<?= htmlspecialchars($val) ?>" <?= $currentStatus === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col col-xl-auto">
                        <div class="search-wrapper">
                            <label for="search_keyword" class="visually-hidden">검색</label>
                            <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                   placeholder="제목 검색" value="<?= htmlspecialchars($currentKeyword) ?>">
                            <i class="bi bi-search search-icon"></i>
                            <?php if ($currentKeyword !== ''): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/qna'"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-default"><i class="bi bi-search"></i> 검색</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- 목록 -->
    <form name="flist" id="flist">
        <div class="table-responsive">
            <?= $this->listRenderHelper
                ->setColumns($columns)
                ->setRows($items)
                ->setSkin('table/basic')
                ->setWrapAttr(['class' => 'table table-hover align-middle mb-0', 'id' => 'qnaTable'])
                ->showHeader(true)
                ->render() ?>
        </div>

        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto">
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-default" onclick="qnaBatchModify()">
                        <i class="d-inline d-md-none bi bi-pencil-square"></i>
                        <span class="d-none d-md-inline">선택 수정</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/qna/list-delete"
                            data-callback="qnaAfterBulkDelete"
                            data-confirm="선택한 문의를 삭제하시겠습니까?">
                        <i class="d-inline d-md-none bi bi-trash"></i>
                        <span class="d-none d-md-inline">선택 삭제</span>
                    </button>
                </div>
            </div>
            <div class="col-auto">
                <?= $this->pagination($pagination) ?>
            </div>
        </div>
    </form>
</div>

<style>
/* 인라인 셀렉트 변경됨 표시 (회원 관리 패턴) */
#flist select.list-changed {
    border-color: var(--bs-warning, #ffc107);
    background-color: var(--bs-warning-bg-subtle, #fff3cd);
    font-weight: 600;
}

/* 제목 셀: 다른 열이 고정폭이므로 남은 너비만 차지하게 하고 제목/본문 발췌를 말줄임.
   max-width:0 = 자동 레이아웃 표에서 가변 열이 내용 때문에 무한정 늘어나는 걸 막는 관용 트릭.
   flex 자식(제목 링크)은 min-width:0 이어야 콘텐츠보다 작게 줄어 .text-truncate 가 걸린다. */
#qnaTable td.qna-title-cell {
    max-width: 0;
}
#qnaTable td.qna-title-cell .text-truncate {
    min-width: 0;
}
</style>
<script>
(function () {
    // 전체 선택
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });

    // 행 셀렉트(상태) 변경 시 변경 표시 + 해당 행 자동 체크 (회원 관리 패턴)
    var flist = document.getElementById('flist');
    if (flist) {
        flist.querySelectorAll('select').forEach(function (sel) { sel.dataset.original = sel.value; });
        flist.addEventListener('change', function (e) {
            var el = e.target;
            if (!el || el.tagName !== 'SELECT') return;
            var row = el.closest('tr');
            if (!row) return;
            el.classList.toggle('list-changed', el.value !== el.dataset.original);
            var cb = row.querySelector('input[name="chk[]"]');
            if (cb) cb.checked = row.querySelector('select.list-changed') !== null;
        });
    }

    // 단건 삭제 (이벤트 위임) — DELETE /admin/qna/{id}
    document.getElementById('qnaTable')?.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete-qna');
        if (!btn) return;
        var title = btn.dataset.title || '문의';
        MubloRequest.showConfirm('[' + title + '] 문의를 삭제하시겠습니까?\n답변도 함께 삭제됩니다.', function () {
            MubloRequest.requestJson('/admin/qna/' + btn.dataset.id, {}, { method: 'DELETE', loading: true })
                .then(function () { location.reload(); })
                .catch(function (err) { MubloRequest.showAlert((err && err.message) || '삭제에 실패했습니다.', 'error'); });
        }, { type: 'warning' });
    });
})();

// 상태 일괄 수정
function qnaBatchModify() {
    var checked = document.querySelectorAll('input[name="chk[]"]:checked');
    if (checked.length === 0) { MubloRequest.showAlert('수정할 항목을 선택하세요.'); return; }

    var items = {};
    checked.forEach(function (cb) {
        var row = cb.closest('tr');
        var sel = row && row.querySelector('select[name^="status["]');
        if (sel) { items[cb.value] = { status: sel.value }; }
    });

    MubloRequest.showConfirm(checked.length + '건의 상태를 수정하시겠습니까?', function () {
        MubloRequest.requestJson('/admin/qna/list-modify', { items: items }, { loading: true })
            .then(function () { location.reload(); })
            .catch(function (err) { MubloRequest.showAlert((err && err.message) || '수정에 실패했습니다.', 'error'); });
    });
}

// 선택 삭제 후 콜백
function qnaAfterBulkDelete(data) {
    MubloRequest.showToast((data && data.message) || '삭제되었습니다.', 'success');
    location.reload();
}

// 프론트 스킨 저장 — PUT /admin/qna/config
function saveQnaSkin() {
    var skin = document.getElementById('skinSelect').value;
    MubloRequest.requestJson('/admin/qna/config', { skin_name: skin }, { method: 'PUT', loading: true })
        .then(function () {})
        .catch(function (err) { MubloRequest.showAlert((err && err.message) || '저장에 실패했습니다.', 'error'); });
}
</script>
