<?php
/**
 * Admin Boardcategory - Index
 *
 * 게시판 카테고리 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $categories 카테고리 목록
 * @var array $pagination 페이지네이션 정보
 */

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'category_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('sort', '', [
        'render' => fn($row) => '<i class="bi bi-arrows-move text-muted handle" style="cursor:grab"></i>',
        '_th_attr' => ['style' => 'width:40px']
    ])
    ->add('category_id', 'ID', [
        'render' => fn($row) => '<small class="text-muted">#' . $row['category_id'] . '</small>',
        '_th_attr' => ['style' => 'width:60px']
    ])
    ->add('category_slug', '슬러그', [
        'render' => fn($row) => '<code>' . htmlspecialchars($row['category_slug']) . '</code>'
    ])
    ->add('category_name', '카테고리명', [
        'render' => function($row) {
            $html = '<strong>' . htmlspecialchars($row['category_name']) . '</strong>';
            if (!empty($row['category_description'])) {
                $html .= '<br><small class="text-muted">' . htmlspecialchars($row['category_description']) . '</small>';
            }
            return $html;
        }
    ])
    ->add('board_count', '사용 게시판', [
        'render' => fn($row) => '<span class="badge bg-secondary">' . ($row['board_count'] ?? 0) . '</span>',
        '_th_attr' => ['style' => 'width:90px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->select('is_active', '상태', [
        1 => '사용',
        0 => '미사용',
    ], ['id_key' => 'category_id', '_th_attr' => ['style' => 'width:100px']])
    ->actions('actions', '관리', function($row) {
        $id = $row['category_id'];
        $name = htmlspecialchars($row['category_name'], ENT_QUOTES);
        $boardCount = $row['board_count'] ?? 0;

        $html = '<a href="/admin/board/category/edit?id=' . $id . '" class="btn btn-sm btn-default">수정</a> ';

        if ($boardCount === 0) {
            $html .= '<button type="button" class="btn btn-sm btn-default js-board-category-delete" data-category-id="' . $id . '" data-category-name="' . $name . '">삭제</button>';
        } else {
            $html .= '<button type="button" class="btn btn-sm btn-default" disabled title="사용 중인 게시판이 있어 삭제 불가">삭제</button>';
        }

        return $html;
    }, ['_th_attr' => ['style' => 'width:120px']])
    ->build();
?>
<style>
/* 인라인 셀렉트 변경됨 표시 */
#flist select.list-changed {
    border-color: var(--bs-warning, #ffc107);
    background-color: var(--bs-warning-bg-subtle, #fff3cd);
    font-weight: 600;
}
</style>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '게시판 카테고리 관리') ?></h3>
            <p>게시판에서 사용할 카테고리를 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/category/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 카테고리 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 요약 영역 -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/board/category">전체</a></span>
                    <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
                </span>
            </div>
        </div>

        <!-- 카테고리 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($categories)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->setTrAttr(fn($row) => ['data-category-id' => $row['category_id']])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/board/category/list-update"
                                data-callback="afterListUpdate">
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/board/category/list-delete"
                                data-callback="afterListDelete">
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

    <!-- 안내 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-question-circle text-pastel-blue"></i>
                <span>카테고리 안내</span>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>카테고리는 여러 게시판에서 공유하여 사용할 수 있습니다.</li>
                    <li>게시판 설정에서 카테고리 사용을 활성화하고, 사용할 카테고리를 선택합니다.</li>
                    <li>게시판에서 사용 중인 카테고리는 삭제할 수 없습니다.</li>
                    <li>드래그하여 카테고리 순서를 변경할 수 있습니다.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(event) {
    var button = event.target.closest('.js-board-category-delete');
    if (!button) return;
    deleteCategory(Number(button.dataset.categoryId || 0), button.dataset.categoryName || '');
});

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

// 행의 셀렉트(상태) 변경 시 해당 행 체크박스 자동 체크 + 변경 표시
document.addEventListener('DOMContentLoaded', function() {
    var flist = document.getElementById('flist');
    if (!flist) return;

    // 각 셀렉트의 원래 값 저장 (되돌림 판정용)
    flist.querySelectorAll('select').forEach(function(sel) {
        sel.dataset.original = sel.value;
    });

    flist.addEventListener('change', function(e) {
        var el = e.target;
        if (!el || el.tagName !== 'SELECT') return;
        var row = el.closest('tr');
        if (!row) return;

        // 원래 값과 비교해 변경됨 표시 토글 (되돌리면 해제)
        var changed = el.value !== el.dataset.original;
        el.classList.toggle('list-changed', changed);

        // 행 내 변경된 셀렉트가 하나라도 있으면 체크, 모두 원복이면 해제
        var anyChanged = row.querySelector('select.list-changed') !== null;
        var cb = row.querySelector('input[name="chk[]"]');
        if (cb) cb.checked = anyChanged;
    });
});

// 카테고리 삭제 (단건)
function deleteCategory(categoryId, categoryName) {
    if (!confirm('\'' + categoryName + '\' 카테고리를 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/board/category/delete', {
        category_id: categoryId
    }).then(function(response) {
        alert(response.message || '카테고리가 삭제되었습니다.');
        location.reload();
    });
}

// 일괄 수정 후 콜백
function afterListUpdate(data) {
    if (data.result === 'success') {
        alert(data.message || '수정되었습니다.');
        location.reload();
    }
}

// 일괄 삭제 후 콜백
function afterListDelete(data) {
    if (data.result === 'success') {
        alert(data.message || '삭제되었습니다.');
        location.reload();
    }
}

// 드래그 앤 드롭 정렬 (Sortable.js)
document.addEventListener('DOMContentLoaded', function() {
    var tbody = document.querySelector('#flist tbody');
    if (tbody && typeof Sortable !== 'undefined') {
        new Sortable(tbody, {
            handle: '.handle',
            animation: 150,
            onEnd: function() {
                var categoryIds = [];
                tbody.querySelectorAll('tr[data-category-id]').forEach(function(row) {
                    categoryIds.push(parseInt(row.dataset.categoryId));
                });

                MubloRequest.requestJson('/admin/board/category/order-update', {
                    category_ids: categoryIds
                }).then(function() {
                    // 성공 시 조용히 저장
                });
            }
        });
    }
});
</script>
