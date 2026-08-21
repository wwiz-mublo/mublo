<?php
/**
 * 상품옵션 프리셋 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle
 * @var array $presets
 * @var array $pagination 페이지네이션 (현재 미사용 — 형식 통일용 placeholder)
 * @var array $filters
 */

$presets    = $presets ?? [];
$pagination = $pagination ?? [];
$filters    = $filters ?? [];

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'preset_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('preset_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('name', '프리셋명', function ($row) {
        $id = $row['preset_id'];
        $name = htmlspecialchars($row['name'] ?? '');
        return '<a href="/admin/shop/options/' . $id . '/edit">' . $name . '</a>';
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:120px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('description', '설명', function ($row) {
        $desc = htmlspecialchars(($row['description'] ?? '') ?: '-');
        return '<div class="text-truncate min-w-0">' . $desc . '</div>';
    }, ['_td_attr' => ['class' => 'small text-muted', 'style' => 'max-width:0']])
    ->callback('created_at', '등록일시', function ($row) {
        $at = (string) ($row['created_at'] ?? '');
        $date = htmlspecialchars(substr($at, 0, 10));
        $time = htmlspecialchars(substr($at, 11));
        return $date . ' <span class="text-muted">' . $time . '</span>';
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:180px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->actions('actions', '관리', function ($row) {
        $id = $row['preset_id'];
        return '
            <a href="/admin/shop/options/' . $id . '/edit" class="btn btn-sm btn-default">수정</a>
            <button type="button" class="btn btn-sm btn-default" onclick="deletePreset(' . $id . ')">삭제</button>
        ';
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:120px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '상품옵션 프리셋') ?></h3>
            <p>상품옵션 프리셋을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/options/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 프리셋 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/options">전체</a></span>
                        <span class="ov-num"><b><?= number_format(count($presets)) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="프리셋명 검색"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/options'"></i>
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

        <!-- 목록 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($presets)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle mb-0'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단: 선택 삭제 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/options/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 프리셋을 삭제하시겠습니까?">
                        <i class="d-inline d-md-none bi bi-trash"></i>
                        <span class="d-none d-md-inline">선택 삭제</span>
                    </button>
                </div>
                <div class="col-auto">
                    <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
                        <?= $this->pagination($pagination) ?>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// 전체 선택
document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
    var checked = this.checked;
    document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
});

// 단건 삭제
function deletePreset(id) {
    if (!confirm('이 프리셋을 삭제하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/shop/options/' + id + '/delete', { preset_id: id })
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
