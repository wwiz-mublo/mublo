<?php
/**
 * 설문 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle
 * @var array  $items
 * @var array  $pagination
 * @var array  $search
 * @var array  $statuses
 * @var array  $skins       사용 가능한 프론트 스킨 목록
 * @var string $currentSkin 현재 적용된 프론트 스킨
 */
$keyword = htmlspecialchars($search['keyword'] ?? '');
$skins       = $skins ?? ['basic'];
$currentSkin = $currentSkin ?? 'basic';

// 목록 복귀 URL을 인코딩해 편집/결과 링크의 return 파라미터로 실음 (검색·페이징 유지)
$listUrl    = '/admin/survey/surveys' . (!empty($listQuery) ? '?' . $listQuery : '');
$editReturn = rawurlencode($listUrl);

$statusBadge = [
    'draft'  => 'warning',
    'active' => 'success',
    'closed' => 'dark',
];
$statusLabel = [
    'draft'  => '초안',
    'active' => '진행중',
    'closed' => '종료',
];

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'survey_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center text-nowrap']])
    ->add('survey_id', '번호', ['_th_attr' => ['style' => 'width:60px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->add('title', '제목', [
        'render' => function ($row) use ($editReturn) {
            $id    = (int) $row['survey_id'];
            $title = htmlspecialchars($row['title'] ?? '');
            $html  = '<a href="/admin/survey/surveys/' . $id . '/edit?return=' . $editReturn . '" class="text-body fw-medium text-decoration-none">' . $title . '</a>';
            // draft(비공개)는 프론트에서 메인으로 리다이렉트되므로 보기 링크를 노출하지 않음
            if (($row['status'] ?? '') !== 'draft') {
                $html .= ' <a href="/survey/' . $id . '" target="_blank" rel="noopener noreferrer" class="text-muted ms-1 text-decoration-none" title="프론트에서 설문 보기"><i class="bi bi-box-arrow-up-right small"></i></a>';
            }
            if (!empty($row['description'])) {
                $desc  = htmlspecialchars($row['description']);
                $html .= '<div class="text-muted small text-truncate" style="max-width:400px">' . $desc . '</div>';
            }
            return $html;
        },
    ])
    ->add('status', '상태', [
        '_th_attr'   => ['style' => 'width:80px', 'class' => 'text-center'],
        '_cell_attr' => ['class' => 'text-center text-nowrap'],
        'render'     => function ($row) use ($statusBadge, $statusLabel) {
            $sc    = $statusBadge[$row['status']] ?? 'secondary';
            $label = htmlspecialchars($statusLabel[$row['status']] ?? $row['status']);
            return '<span class="badge bg-' . $sc . '-subtle text-' . $sc . '-emphasis border border-' . $sc . '-subtle">' . $label . '</span>';
        },
    ])
    ->add('response', '제한', [
        '_th_attr'   => ['style' => 'width:80px', 'class' => 'text-center'],
        '_cell_attr' => ['class' => 'text-center text-nowrap'],
        'render'     => function ($row) {
            $limit = (int) ($row['response_limit'] ?? 0);
            $text  = $limit > 0 ? number_format($limit) : '무제한';
            return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . $text . '</span>';
        },
    ])
    ->add('period', '기간', [
        '_th_attr'   => ['style' => 'width:180px'],
        '_cell_attr' => ['class' => 'text-muted small text-nowrap'],
        'render'     => function ($row) {
            $start = !empty($row['start_at']) ? htmlspecialchars(substr($row['start_at'], 0, 16)) : '없음';
            $end   = !empty($row['end_at'])   ? htmlspecialchars(substr($row['end_at'], 0, 16))   : '없음';
            return "<div>시작일시: {$start}</div><div>종료일시: {$end}</div>";
        },
    ])
    ->add('result', '결과', [
        '_th_attr'   => ['style' => 'width:80px'],
        '_cell_attr' => ['class' => 'text-nowrap'],
        'render'     => function ($row) use ($editReturn) {
            $id = (int) $row['survey_id'];
            return '<a href="/admin/survey/surveys/' . $id . '/result?return=' . $editReturn . '" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart-line"></i> 결과 보기</a>';
        },
    ])
    ->actions('actions', '관리', function ($row) use ($editReturn) {
        $id = (int) $row['survey_id'];
        return '
            <a href="/admin/survey/surveys/' . $id . '/edit?return=' . $editReturn . '" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSurvey(' . $id . ')"><i class="bi bi-trash"></i></button>
        ';
    }, ['_th_attr' => ['style' => 'width:90px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>설문을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/survey/surveys/create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 새 설문
            </a>
        </div>
    </div>

    <!-- 프론트 스킨 설정 -->
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
                        <button type="button" class="btn btn-primary btn-sm" onclick="saveSkin()">저장</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" action="/admin/survey/surveys" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/survey/surveys">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="설문 제목 검색"
                                       value="<?= $keyword ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($keyword)): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/survey/surveys'"></i>
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

        <!-- 설문 목록 폼 -->
        <form name="flist" id="flist">
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
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/survey/surveys/list-delete"
                                data-callback="afterListDelete"
                                data-confirm="선택한 설문과 모든 응답 데이터를 삭제합니다. 계속하시겠습니까?">
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                    </div>
                </div>
                <?php if (($pagination['totalPages'] ?? 1) > 1): ?>
                <div class="col-auto">
                    <?= $this->pagination($pagination) ?>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
// 전체 선택
document.addEventListener('DOMContentLoaded', function () {
    var checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checkAll.checked; });
        });
    }
});

// 설문 삭제 (단건)
function deleteSurvey(id) {
    if (!confirm('이 설문과 모든 응답 데이터를 삭제합니다. 계속하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/survey/surveys/' + id + '/delete', {}, { method: 'POST' })
        .then(function () { location.reload(); });
}

// 선택 삭제 결과 콜백 (mublo-submit → /list-delete)
function afterListDelete(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '삭제에 실패했습니다.', 'error');
    }
}

// 프론트 스킨 설정 저장
function saveSkin() {
    const skin = document.getElementById('skinSelect').value;
    MubloRequest.requestJson('/admin/survey/skin', { skin: skin }, { method: 'PUT', loading: true });
}
</script>
