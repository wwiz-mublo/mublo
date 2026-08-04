<?php
/**
 * 배너 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $items 배너 목록
 * @var array $pagination 페이지네이션
 * @var array $search 검색 조건
 */
$items = $items ?? [];
$pagination = $pagination ?? [];
$search = $search ?? [];
$keyword = htmlspecialchars($search['keyword'] ?? '');

// 목록 복귀 URL을 인코딩해 편집 링크의 return 파라미터로 (검색·페이징 유지)
$listUrl    = '/admin/banner/list' . (!empty($listQuery) ? '?' . $listQuery : '');
$editReturn = rawurlencode($listUrl);

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'banner_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('banner_id', '번호', ['_th_attr' => ['style' => 'width:60px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->add('preview', '미리보기', [
        '_th_attr' => ['style' => 'width:120px'],
        '_cell_attr' => ['class' => 'text-nowrap'],
        'render' => function ($row) {
            if (!empty($row['pc_image_url'])) {
                $src = htmlspecialchars($row['pc_image_url']);
                return '<img src="' . $src . '" alt="" style="max-width:100px; max-height:56px; width:auto; object-fit:cover; border-radius:0.25rem;">';
            }
            return '<span class="text-muted small">-</span>';
        },
    ])
    ->add('title', '배너명', [
        'render' => function ($row) use ($editReturn) {
            $id = (int) $row['banner_id'];
            $title = htmlspecialchars($row['title'] ?? '');
            $html = '<a href="/admin/banner/' . $id . '/edit?return=' . $editReturn . '" class="text-body text-decoration-none">' . $title . '</a>';
            if (!empty($row['link_url'])) {
                $link = htmlspecialchars($row['link_url']);
                $html .= '<br><small class="text-muted">' . $link . '</small>';
            }
            return $html;
        },
    ])
    ->add('is_active', '상태', [
        '_th_attr' => ['style' => 'width:80px; text-align:center'],
        '_cell_attr' => ['style' => 'text-align:center', 'class' => 'text-nowrap'],
        'render' => function ($row) {
            $active = (int) ($row['is_active'] ?? 1);
            if ($active) {
                return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">사용</span>';
            }
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">미사용</span>';
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
    ->actions('actions', '관리', function ($row) use ($editReturn) {
        $id = (int) $row['banner_id'];
        return '
            <a href="/admin/banner/' . $id . '/edit?return=' . $editReturn . '" class="btn btn-sm btn-default">수정</a>
            <button type="button" class="btn btn-sm btn-default" onclick="deleteBanner(' . $id . ', this)">삭제</button>
        ';
    }, ['_th_attr' => ['style' => 'width:110px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>배너를 등록하고 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/banner/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 배너 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" action="/admin/banner/list" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/banner/list">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="배너명 검색"
                                       value="<?= $keyword ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($keyword)): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/banner/list'"></i>
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

        <!-- 배너 목록 폼 -->
        <form name="flist" id="flist">
            <!-- 배너 목록 테이블 -->
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
                            data-target="/admin/banner/listDelete"
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

// 배너 삭제 (단건)
function deleteBanner(bannerId) {
    if (!confirm('이 배너를 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/banner/' + bannerId + '/delete', {}, { method: 'POST', loading: true })
        .then(function() {
            location.reload();
        });
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    alert(data.message || '삭제되었습니다.');
    location.reload();
}
</script>
