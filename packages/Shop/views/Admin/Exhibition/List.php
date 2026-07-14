<?php
/**
 * 기획전 관리 목록
 *
 * @var string $pageTitle
 * @var array  $items
 * @var array  $pagination
 * @var array  $filters
 */
$currentActive  = $filters['is_active'] ?? '';
$currentKeyword = $filters['keyword'] ?? '';

$totalItems  = $pagination['totalItems'] ?? 0;
$currentPage = $pagination['currentPage'] ?? 1;
$totalPages  = $pagination['totalPages'] ?? 1;
$perPage     = $pagination['perPage'] ?? 20;

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'exhibition_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('exhibition_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('banner', '배너', function ($row) {
        $thumb = $row['banner_image'] ?: ($row['banner_mobile_image'] ?? '');
        if ($thumb !== '') {
            return '<img src="' . htmlspecialchars($thumb) . '" class="border rounded" style="width:80px;height:44px;object-fit:cover;">';
        }
        return '<span class="d-inline-flex align-items-center justify-content-center border rounded bg-body-tertiary text-muted opacity-50" style="width:80px;height:44px;"><i class="bi bi-image"></i></span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:96px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('title', '기획전명', function ($row) {
        $eid = (int) $row['exhibition_id'];
        $title = htmlspecialchars($row['title'] ?? '');
        $frontSlug = !empty($row['slug']) ? $row['slug'] : (string) $eid;
        $slugEnc = rawurlencode($frontSlug);
        $slugText = htmlspecialchars($frontSlug);
        return '<div><a href="/admin/shop/exhibitions/' . $eid . '/edit" class="fw-semibold text-decoration-none">' . $title . '</a></div>'
            . '<div class="small mt-1">'
            . '<a href="/shop/exhibitions/' . $slugEnc . '" target="_blank" rel="noopener" class="icon-link-muted text-decoration-none" title="프론트에서 보기">'
            . '<span class="text-muted">/shop/exhibitions/</span>' . $slugText . ' <i class="bi bi-box-arrow-up-right"></i></a></div>';
    })
    ->callback('linked', '연결 상품', function ($row) {
        $gc = (int) ($row['goods_count'] ?? 0);
        $cc = (int) ($row['category_count'] ?? 0);
        if ($gc === 0 && $cc === 0) {
            return '<span class="text-muted opacity-50"></span>';
        }
        $html = '';
        if ($gc > 0) $html .= '<div class="text-nowrap"><i class="bi bi-box-seam text-muted"></i> 상품 ' . $gc . '</div>';
        if ($cc > 0) $html .= '<div class="text-nowrap"><i class="bi bi-folder text-muted"></i> 카테고리 ' . $cc . '</div>';
        return $html;
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:110px'], '_td_attr' => ['class' => 'text-nowrap small']])
    ->callback('period', '행사 기간', function ($row) {
        $start = $row['start_date'] ? substr($row['start_date'], 0, 10) : '없음';
        $end = $row['end_date'] ? substr($row['end_date'], 0, 10) : '없음';
        return '<div>시작일: ' . htmlspecialchars($start) . '</div><div>종료일: ' . htmlspecialchars($end) . '</div>';
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:130px'], '_td_attr' => ['class' => 'text-nowrap small']])
    ->callback('is_active', '상태', function ($row) {
        $now = time();
        $isOngoing = $row['is_active']
            && (empty($row['start_date']) || strtotime($row['start_date']) <= $now)
            && (empty($row['end_date']) || strtotime($row['end_date']) >= $now);
        if ($isOngoing) {
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">진행 중</span>';
        }
        if (!$row['is_active']) {
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">비활성</span>';
        }
        return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">대기/종료</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:80px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('sort_order', '정렬', function ($row) {
        return (int) $row['sort_order'];
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:70px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('created_at', '등록일', function ($row) {
        return '<small>' . htmlspecialchars(substr($row['created_at'] ?? '', 0, 10)) . '</small>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:100px'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) {
        $eid = (int) $row['exhibition_id'];
        return '
            <a href="/admin/shop/exhibitions/' . $eid . '/edit" class="btn btn-sm btn-default" title="수정"><i class="bi bi-pencil"></i></a>
            <button type="button" class="btn btn-sm btn-default btn-delete" data-id="' . $eid . '" title="삭제"><i class="bi bi-trash"></i></button>
        ';
    }, ['_th_attr' => ['style' => 'width:90px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '기획전 관리') ?></h3>
            <p>기획전을 등록하고 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/exhibitions/create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 기획전 등록
            </a>
        </div>
    </div>

    <form method="get" name="fsearch" id="fsearch" class="mb-2">
        <div class="row align-items-center gy-2 gy-xl-0">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/shop/exhibitions">전체</a></span>
                    <span class="ov-num"><b><?= number_format($totalItems) ?></b> 건</span>
                </span>
            </div>
            <div class="col col-xl-auto ms-xl-auto">
                <div class="row gx-2">
                    <div class="col-auto">
                        <select name="is_active" class="form-select form-select-sm">
                            <option value="">전체 상태</option>
                            <option value="1" <?= $currentActive === '1' ? 'selected' : '' ?>>활성</option>
                            <option value="0" <?= $currentActive === '0' ? 'selected' : '' ?>>비활성</option>
                        </select>
                    </div>
                    <div class="col col-xl-auto">
                        <div class="search-wrapper">
                            <label for="search_keyword" class="visually-hidden">검색</label>
                            <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                   placeholder="기획전명"
                                   value="<?= htmlspecialchars($currentKeyword) ?>">
                            <i class="bi bi-search search-icon"></i>
                            <?php if ($currentKeyword): ?>
                            <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/exhibitions'"></i>
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
                <button type="button" class="btn btn-sm btn-default mublo-submit"
                        data-target="/admin/shop/exhibitions/menu-register"
                        data-callback="afterMenuRegister"
                        data-confirm="선택한 기획전을 메뉴에 등록하시겠습니까?">
                    <i class="d-inline d-md-none bi bi-list-ul"></i>
                    <span class="d-none d-md-inline">선택 메뉴 등록</span>
                </button>
                <button type="button" class="btn btn-sm btn-default mublo-submit ms-1"
                        data-target="/admin/shop/exhibitions/list-delete"
                        data-callback="afterBulkDelete"
                        data-confirm="선택한 기획전을 삭제하시겠습니까?">
                    <i class="d-inline d-md-none bi bi-trash"></i>
                    <span class="d-none d-md-inline">선택 삭제</span>
                </button>
            </div>
            <div class="col-auto">
                <?php if ($totalPages > 1): ?>
                <?= $this->pagination($pagination) ?>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    // 단건 삭제 (이벤트 위임)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete');
        if (!btn) return;
        if (!confirm('이 기획전을 삭제하시겠습니까?\n연결된 아이템도 함께 삭제됩니다.')) return;
        MubloRequest.requestJson('/admin/shop/exhibitions/delete', { exhibition_id: parseInt(btn.dataset.id) })
            .then(function () { location.reload(); });
    });

    // 전체 선택
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });
})();

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        location.reload();
    } else {
        alert(data.message || '삭제에 실패했습니다.');
    }
}

// 메뉴 등록 후 콜백 (목록 변화 없음 → 결과 메시지만 안내)
function afterMenuRegister(data) {
    alert(data.message || (data.result === 'success' ? '메뉴 등록 완료' : '등록에 실패했습니다.'));
}
</script>
