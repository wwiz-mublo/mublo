<?php
/**
 * 상품정보 템플릿 목록
 *
 * @var string $pageTitle
 * @var array $items
 * @var array $pagination
 * @var array $filters
 */
$currentStatus = $filters['status'] ?? '';
$currentKeyword = $filters['keyword'] ?? '';

$totalItems = $pagination['totalItems'] ?? 0;
$currentPage = $pagination['currentPage'] ?? 1;
$totalPages = $pagination['totalPages'] ?? 1;
$perPage = $pagination['perPage'] ?? 20;

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'template_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('template_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('tab_name', '탭명', function ($row) {
        return htmlspecialchars($row['tab_name'] ?: '-');
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:100px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('subject', '제목', function ($row) {
        $id = (int) $row['template_id'];
        $subject = htmlspecialchars($row['subject'] ?? '');
        return '<a href="/admin/shop/info-templates/' . $id . '/edit" class="text-decoration-none">' . $subject . '</a>';
    })
    ->callback('category_name', '카테고리', function ($row) {
        return '<span class="text-muted">' . htmlspecialchars($row['category_name'] ?? '전체') . '</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:120px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('status', '상태', function ($row) {
        $on = ($row['status'] ?? '') === 'Y';
        $cls = $on ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
        return '<span class="badge ' . $cls . '">' . ($on ? '사용' : '미사용') . '</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:70px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('sort_order', '정렬', function ($row) {
        $id = (int) $row['template_id'];
        $sort = (int) $row['sort_order'];
        return '<input type="number" class="form-control form-control-sm text-center sort-input mx-auto" data-id="' . $id . '" value="' . $sort . '" min="0" style="width:64px">';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:80px'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) {
        $id = (int) $row['template_id'];
        $name = htmlspecialchars($row['subject'] ?? '');
        return '
            <a href="/admin/shop/info-templates/' . $id . '/edit" class="btn btn-sm btn-default" title="수정"><i class="bi bi-pencil"></i></a>
            <button type="button" class="btn btn-sm btn-default btn-delete" data-id="' . $id . '" data-name="' . $name . '" title="삭제"><i class="bi bi-trash"></i></button>
        ';
    }, ['_th_attr' => ['style' => 'width:90px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>상품 상세페이지에 공통으로 출력할 상품정보를 등록할 수 있습니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/info-templates/create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 템플릿 등록
            </a>
        </div>
    </div>

    <form method="get" class="mb-2">
        <div class="row align-items-center gy-2 gy-xl-0">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/shop/info-templates">전체</a></span>
                    <span class="ov-num"><b><?= number_format($totalItems) ?></b> 개</span>
                </span>
            </div>
            <div class="col col-xl-auto ms-xl-auto">
                <div class="row gx-2">
                    <div class="col-auto">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">전체 상태</option>
                            <option value="Y" <?= $currentStatus === 'Y' ? 'selected' : '' ?>>사용</option>
                            <option value="N" <?= $currentStatus === 'N' ? 'selected' : '' ?>>미사용</option>
                        </select>
                    </div>
                    <div class="col col-xl-auto">
                        <div class="search-wrapper">
                            <label for="search_keyword" class="visually-hidden">검색</label>
                            <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                   placeholder="제목/탭명 검색"
                                   value="<?= htmlspecialchars($currentKeyword) ?>">
                            <i class="bi bi-search search-icon"></i>
                            <?php if ($currentKeyword): ?>
                            <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/info-templates'"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-default">
                            <i class="bi bi-search"></i> 검색
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <form name="flist" id="flist">
        <div class="table-responsive">
            <?= $this->listRenderHelper
                ->setColumns($columns)
                ->setRows($items)
                ->setSkin('table/basic')
                ->setWrapAttr(['class' => 'table table-hover align-middle mb-0'])
                ->showHeader(true)
                ->render() ?>
        </div>

        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto">
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-default" onclick="saveSortOrders()">
                        <i class="d-inline d-md-none bi bi-pencil-square"></i>
                        <span class="d-none d-md-inline">선택 수정</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/info-templates/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 템플릿을 삭제하시겠습니까?">
                        <i class="d-inline d-md-none bi bi-trash"></i>
                        <span class="d-none d-md-inline">선택 삭제</span>
                    </button>
                </div>
            </div>
            <div class="col-auto">
                <?php if ($totalPages > 1): ?>
                <?= $this->pagination($pagination) ?>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<style>
.sort-input.list-changed {
    border-color: var(--bs-warning);
    background-color: var(--bs-warning-bg-subtle);
    font-weight: 600;
}
</style>
<script>
(function () {
    // 전체 선택
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });
    // 단건 삭제 (이벤트 위임)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete');
        if (!btn) return;
        var name = btn.dataset.name;
        if (!confirm('[' + name + '] 템플릿을 삭제하시겠습니까?')) return;
        MubloRequest.requestJson('/admin/shop/info-templates/delete', { template_id: parseInt(btn.dataset.id) })
            .then(function () { location.reload(); });
    });
    // 정렬 input 변경 감지 → 변경 표기 + 해당 행 체크 (게시판 관리 컨벤션)
    document.querySelectorAll('.sort-input').forEach(function (inp) {
        inp.dataset.original = inp.value;
        inp.addEventListener('change', function () {
            var changed = inp.value !== inp.dataset.original;
            inp.classList.toggle('list-changed', changed);
            var cb = inp.closest('tr').querySelector('input[name="chk[]"]');
            if (cb) cb.checked = changed;
        });
    });
})();

// 선택 정렬 일괄 저장
function saveSortOrders() {
    var checked = document.querySelectorAll('input[name="chk[]"]:checked');
    if (checked.length === 0) { alert('저장할 항목을 선택하세요.'); return; }
    var sorts = {};
    checked.forEach(function (cb) {
        var inp = cb.closest('tr').querySelector('.sort-input');
        if (inp) sorts[inp.dataset.id] = parseInt(inp.value) || 0;
    });
    MubloRequest.requestJson('/admin/shop/info-templates/list-sort', { sorts: sorts })
        .then(function () { location.reload(); });
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        location.reload();
    } else {
        alert(data.message || '삭제에 실패했습니다.');
    }
}
</script>
