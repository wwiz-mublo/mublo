<?php
/**
 * Admin Boardconfig - Index
 *
 * 게시판 설정 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $boards 게시판 목록
 * @var array $pagination 페이지네이션 정보
 */

// 그룹 select 옵션 생성
$groupOptions = [];
foreach ($groups ?? [] as $g) {
    $groupOptions[$g['value']] = $g['label'];
}

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'board_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('sort', '', [
        'render' => fn($row) => '<i class="bi bi-arrows-move text-muted handle" style="cursor:grab"></i>',
        '_th_attr' => ['style' => 'width:40px']
    ])
    ->select('group_id', '그룹', $groupOptions, [
        'id_key' => 'board_id',
        '_th_attr' => ['style' => 'width:140px']
    ])
    ->add('board_id', 'ID', [
        'render' => fn($row) => '<small class="text-muted">#' . $row['board_id'] . '</small>',
        '_th_attr' => ['style' => 'width:50px'],
        '_td_attr' => ['class' => 'text-nowrap']
    ])
    ->add('board_slug', '슬러그', [
        'render' => fn($row) => '<code>' . htmlspecialchars($row['board_slug']) . '</code>',
        '_th_attr' => ['style' => 'width:100px'],
        '_td_attr' => ['class' => 'text-nowrap']
    ])
    ->add('board_name', '게시판명', [
        'render' => function($row) {
            $slug = htmlspecialchars($row['board_slug'], ENT_QUOTES);
            return '<strong>' . htmlspecialchars($row['board_name']) . '</strong>'
                . ' <a href="/board/' . $slug . '" target="_blank" rel="noopener"'
                . ' class="board-ext-link" title="프론트 게시판 새창으로 보기">'
                . '<i class="bi bi-box-arrow-up-right"></i></a>';
        }
    ])
    ->select('list_level', '목록', $levelOptions, [
        'id_key' => 'board_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('read_level', '읽기', $levelOptions, [
        'id_key' => 'board_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('write_level', '쓰기', $levelOptions, [
        'id_key' => 'board_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('comment_level', '댓글', $levelOptions, [
        'id_key' => 'board_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('download_level', '다운', $levelOptions, [
        'id_key' => 'board_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->add('article_count', '게시글', [
        'render' => fn($row) => '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . ($row['article_count'] ?? 0) . '</span>',
        '_th_attr' => ['style' => 'width:80px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center text-nowrap']
    ])
    ->select('is_active', '상태', [
        1 => '사용',
        0 => '미사용',
    ], ['id_key' => 'board_id', '_th_attr' => ['style' => 'width:90px']])
    ->actions('actions', '관리', function($row) {
        $id = $row['board_id'];
        $name = htmlspecialchars($row['board_name'], ENT_QUOTES);
        $articleCount = $row['article_count'] ?? 0;

        $html = '<a href="/admin/board/config/edit?id=' . $id . '" class="btn btn-sm btn-default">수정</a> ';

        if ($articleCount === 0) {
            $html .= '<button type="button" class="btn btn-sm btn-default js-board-delete" data-board-id="' . $id . '" data-board-name="' . $name . '">삭제</button>';
        } else {
            $html .= '<button type="button" class="btn btn-sm btn-default" disabled title="게시글이 있어 삭제 불가">삭제</button>';
        }

        return $html;
    }, ['_th_attr' => ['style' => 'width:120px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<style>
.board-ext-link {
    color: var(--bs-secondary-color, #adb5bd);
    opacity: .4;
    text-decoration: none;
    transition: opacity .12s, color .12s;
}
.board-ext-link:hover {
    opacity: 1;
    color: var(--bs-default-bg, #18181b);
}
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
            <h3><?= htmlspecialchars($pageTitle ?? '게시판 관리') ?></h3>
            <p>게시판을 생성하고 설정을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/config/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 게시판 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 요약 영역 -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/board/config">전체</a></span>
                    <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
                </span>
            </div>
        </div>

        <!-- 게시판 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($boards)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->setTrAttr(fn($row) => ['data-board-id' => $row['board_id']])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/board/config/list-update"
                                data-callback="afterListUpdate">
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/board/config/list-delete"
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

    <!-- 레벨 설명 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-question-circle text-pastel-blue"></i>
                <span>권한 레벨 안내</span>
            </div>
            <div class="card-body">
                <ul class="list-inline mb-0">
                    <?php foreach ($levelOptions as $value => $label): ?>
                    <li class="list-inline-item me-3">
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Lv.<?= $value ?></span>
                        <?= htmlspecialchars($label) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(event) {
    var button = event.target.closest('.js-board-delete');
    if (!button) return;
    deleteBoard(Number(button.dataset.boardId || 0), button.dataset.boardName || '');
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

// 행의 셀렉트(그룹/레벨/상태) 변경 시 해당 행 체크박스 자동 체크 + 변경 표시
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

// 게시판 삭제 (단건)
function deleteBoard(boardId, boardName) {
    if (!confirm('\'' + boardName + '\' 게시판을 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/board/config/delete', {
        board_id: boardId
    }).then(function(response) {
        alert(response.message || '게시판이 삭제되었습니다.');
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
                var boardIds = [];
                tbody.querySelectorAll('tr[data-board-id]').forEach(function(row) {
                    boardIds.push(parseInt(row.dataset.boardId));
                });

                MubloRequest.requestJson('/admin/board/config/order-update', {
                    board_ids: boardIds
                }).then(function() {
                    // 성공 시 조용히 저장
                });
            }
        });
    }
});
</script>
