<?php
/**
 * Admin Boardgroup - Index
 *
 * 게시판 그룹 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 *
 * @var string $pageTitle 페이지 제목
 * @var array $groups 그룹 목록
 * @var array $levelOptions 권한 레벨 옵션 [value => label]
 */

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'group_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('group_id', 'ID', [
        'render' => fn($row) => '<small class="text-muted">#' . $row['group_id'] . '</small>',
        '_th_attr' => ['style' => 'width:60px']
    ])
    ->add('group_slug', '슬러그', [
        'render' => fn($row) => '<code>' . htmlspecialchars($row['group_slug']) . '</code>'
    ])
    ->add('group_name', '그룹명', [
        'render' => function($row) {
            $html = '<strong>' . htmlspecialchars($row['group_name']) . '</strong>';
            if (!empty($row['group_description'])) {
                $html .= '<br><small class="text-muted">' . htmlspecialchars($row['group_description']) . '</small>';
            }
            return $html;
        }
    ])
    ->add('board_count', '게시판', [
        'render' => fn($row) => '<span class="badge bg-secondary">' . ($row['board_count'] ?? 0) . '</span>',
        '_th_attr' => ['style' => 'width:70px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->select('list_level', '목록', $levelOptions, [
        'id_key' => 'group_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('read_level', '읽기', $levelOptions, [
        'id_key' => 'group_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('write_level', '쓰기', $levelOptions, [
        'id_key' => 'group_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('comment_level', '댓글', $levelOptions, [
        'id_key' => 'group_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('download_level', '다운', $levelOptions, [
        'id_key' => 'group_id',
        '_th_attr' => ['style' => 'width:120px']
    ])
    ->select('is_active', '상태', [
        1 => '사용',
        0 => '미사용',
    ], ['id_key' => 'group_id', '_th_attr' => ['style' => 'width:100px']])
    ->actions('actions', '관리', function($row) {
        $id = $row['group_id'];
        $name = htmlspecialchars($row['group_name'], ENT_QUOTES);
        $boardCount = $row['board_count'] ?? 0;

        $html = '<a href="/admin/board/group/edit?id=' . $id . '" class="btn btn-sm btn-default">수정</a> ';

        if ($boardCount === 0) {
            $html .= '<button type="button" class="btn btn-sm btn-default js-board-group-delete" data-group-id="' . $id . '" data-group-name="' . $name . '">삭제</button>';
        } else {
            $html .= '<button type="button" class="btn btn-sm btn-default" disabled title="게시판이 있어 삭제 불가">삭제</button>';
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
            <h3><?= htmlspecialchars($pageTitle ?? '게시판 그룹 관리') ?></h3>
            <p>게시판을 분류하는 그룹을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/group/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 그룹 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 요약 영역 -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/board/group">전체</a></span>
                    <span class="ov-num"><b><?= count($groups) ?></b> 개</span>
                </span>
            </div>
        </div>

        <!-- 그룹 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($groups)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/board/group/list-update"
                                data-callback="afterListUpdate">
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/board/group/list-delete"
                                data-callback="afterListDelete">
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                    </div>
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
                <ul class="list-inline mb-2">
                    <?php foreach ($levelOptions as $value => $label): ?>
                    <li class="list-inline-item me-3">
                        <span class="badge bg-secondary">Lv.<?= $value ?></span>
                        <?= htmlspecialchars($label) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <small class="text-muted">* 게시판별로 그룹 권한을 오버라이드할 수 있습니다.</small>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(event) {
    const button = event.target.closest('.js-board-group-delete');
    if (!button) return;
    deleteGroup(Number(button.dataset.groupId || 0), button.dataset.groupName || '');
});

// 전체 선택
document.addEventListener('DOMContentLoaded', function() {
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }
});

// 행의 셀렉트(레벨/상태) 변경 시 해당 행 체크박스 자동 체크 + 변경 표시
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

// 그룹 삭제 (단건)
function deleteGroup(groupId, groupName) {
    if (!confirm(`'${groupName}' 그룹을 삭제하시겠습니까?`)) {
        return;
    }

    MubloRequest.requestJson('/admin/board/group/delete', {
        group_id: groupId
    }).then(response => {
        if (response.result === 'success') {
            alert(response.message || '그룹이 삭제되었습니다.');
            location.reload();
        } else {
            alert(response.message || '삭제에 실패했습니다.');
        }
    }).catch(err => {
        alert('오류가 발생했습니다.');
        console.error(err);
    });
}

// 일괄 수정 후 콜백
function afterListUpdate(data) {
    if (data.result === 'success') {
        alert(data.message || '수정되었습니다.');
        location.reload();
    } else {
        alert(data.message || '수정에 실패했습니다.');
    }
}

// 일괄 삭제 후 콜백
function afterListDelete(data) {
    if (data.result === 'success') {
        alert(data.message || '삭제되었습니다.');
        location.reload();
    } else {
        alert(data.message || '삭제에 실패했습니다.');
    }
}
</script>
