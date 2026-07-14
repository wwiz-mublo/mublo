<?php
/**
 * 팝업 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $items 팝업 목록
 * @var array $pagination 페이지네이션
 * @var array $search 검색 조건
 * @var array $config 팝업 설정
 * @var array $skinOptions 스킨 목록
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$search = $search ?? [];
$keyword = htmlspecialchars($search['keyword'] ?? '');
$config = $config ?? [];
$skinOptions = $skinOptions ?? ['basic' => 'basic'];
$currentSkin = $config['popup_skin'] ?? 'basic';

// 목록 복귀 URL을 인코딩해 편집 링크의 return 파라미터로 (검색·페이징 유지)
$listUrl    = '/admin/popup/list' . (!empty($listQuery) ? '?' . $listQuery : '');
$editReturn = rawurlencode($listUrl);

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'popup_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('popup_id', '번호', ['_th_attr' => ['style' => 'width:60px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->add('title', '팝업명', [
        'render' => function ($row) use ($editReturn) {
            $id = (int) $row['popup_id'];
            $title = htmlspecialchars($row['title'] ?? '');
            return '<a href="/admin/popup/' . $id . '/edit?return=' . $editReturn . '" class="text-body text-decoration-none">' . $title . '</a>';
        },
    ])
    ->add('position', '위치', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center', 'class' => 'text-nowrap'],
        'render' => function ($row) {
            $position = htmlspecialchars($row['position'] ?? '');
            return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . $position . '</span>';
        },
    ])
    ->add('display_device', '디바이스', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center', 'class' => 'text-nowrap'],
        'render' => function ($row) {
            $device = $row['display_device'] ?? 'all';
            if ($device === 'pc') {
                return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">PC전용</span>';
            }
            if ($device === 'mo') {
                return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">모바일전용</span>';
            }
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">전체</span>';
        },
    ])
    ->add('is_active', '상태', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center', 'class' => 'text-nowrap'],
        'render' => function ($row) {
            $active = (int) ($row['is_active'] ?? 1);
            if ($active) {
                return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">활성</span>';
            }
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">비활성</span>';
        },
    ])
    ->add('sort_order', '순서', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center', 'class' => 'text-nowrap'],
        'render' => function ($row) {
            $order = (int) ($row['sort_order'] ?? 0);
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">' . $order . '</span>';
        },
    ])
    ->add('period', '노출 기간', [
        '_th_attr' => ['style' => 'width:150px'],
        '_cell_attr' => ['class' => 'text-muted small text-nowrap'],
        'render' => function ($row) {
            $start = !empty($row['start_date']) ? htmlspecialchars($row['start_date']) : '없음';
            $end   = !empty($row['end_date'])   ? htmlspecialchars($row['end_date'])   : '없음';
            return "<div>시작일: {$start}</div><div>종료일: {$end}</div>";
        },
    ])
    ->add('hide_duration', '숨김 시간', [
        '_th_attr' => ['style' => 'width:90px'],
        '_cell_attr' => ['class' => 'text-nowrap'],
        'render' => function ($row) {
            $hours = (int) ($row['hide_duration'] ?? 0);
            if ($hours <= 0) {
                return '<span class="text-muted small">-</span>';
            }
            return "<small>{$hours}시간</small>";
        },
    ])
    ->actions('actions', '관리', function ($row) use ($editReturn) {
        $id = (int) $row['popup_id'];
        return '
            <a href="/admin/popup/' . $id . '/edit?return=' . $editReturn . '" class="btn btn-sm btn-default">수정</a>
            <button type="button" class="btn btn-sm btn-default" onclick="deletePopup(' . $id . ', this)">삭제</button>
        ';
    }, ['_th_attr' => ['style' => 'width:110px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>팝업을 등록하고 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/popup/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 팝업 추가
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
                        <select id="skinSelect" name="formData[popup_skin]" class="form-select form-select-sm" style="width:auto">
                            <?php foreach ($skinOptions as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>"<?= $key === $currentSkin ? ' selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary btn-sm mublo-submit"
                                data-target="/admin/popup/config/save"
                                data-callback="onSkinSaved">
                            저장
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" action="/admin/popup/list" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/popup/list">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="팝업명 검색"
                                       value="<?= $keyword ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($keyword)): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/popup/list'"></i>
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

        <!-- 팝업 목록 폼 -->
        <form name="flist" id="flist">
            <!-- 팝업 목록 테이블 -->
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($items)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button
                            type="button"
                            class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/popup/listDelete"
                            data-callback="afterBulkDelete"
                        >
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
</div>

<script>
// 전체 선택
document.addEventListener('DOMContentLoaded', function() {
    var checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }
});

// 팝업 삭제 (단건)
function deletePopup(popupId) {
    if (!confirm('이 팝업을 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/popup/' + popupId + '/delete', {}, { method: 'POST', loading: true })
        .then(function() {
            location.reload();
        });
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    alert(data.message || '삭제되었습니다.');
    location.reload();
}

// 스킨 설정 저장 콜백
MubloRequest.registerCallback('onSkinSaved', function(response) {
    alert(response.message || '저장되었습니다.');
});
</script>
