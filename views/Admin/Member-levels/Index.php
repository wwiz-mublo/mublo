<?php
/**
 * Admin Member-levels - Index
 *
 * 회원 등급 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var \Mublo\Entity\Member\MemberLevel[] $levels
 * @var array $pagination 페이지네이션 정보
 * @var array $levelTypeOptions 레벨 타입 옵션
 * @var array $currentFilters 현재 필터 조건
 */

// 레벨 타입별 배지 색상 (소프트라벨: bg-*-subtle / text-*-emphasis / border-*-subtle)
$levelTypeColors = [
    'SUPER' => 'danger',
    'STAFF' => 'warning',
    'PARTNER' => 'primary',
    'SITE_MASTER' => 'success',
    'SUPPLIER' => 'info',
    'BASIC' => 'secondary',
];

// 레벨 데이터를 배열로 변환 (ListRenderHelper 용)
$levelsData = [];
foreach ($levels as $level) {
    $levelsData[] = [
        'level_id' => $level->getLevelId(),
        'level_value' => $level->getLevelValue(),
        'level_name' => $level->getLevelName(),
        'level_type' => $level->getLevelType(),
        'is_super' => $level->isSuper(),
        'is_admin' => $level->canAccessAdmin(),
        'can_operate_domain' => $level->canOperateDomain(),
    ];
}

// 수정 링크에 붙일 목록 복귀 쿼리 (필터·activeCode 유지)
$listQuery = $listQuery ?? '';
$editReturn = rawurlencode('/admin/member-levels' . ($listQuery !== '' ? '?' . $listQuery : ''));

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', [
        'id_key' => 'level_id',
        'skip_key' => 'is_super',
        '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'],
        '_cell_attr' => ['class' => 'text-center']
    ])
    ->callback('level_value', '레벨', function ($row) {
        return '<span class="badge bg-dark-subtle text-dark-emphasis border border-dark-subtle">' . $row['level_value'] . '</span>';
    }, ['_th_attr' => ['style' => 'width:70px']])
    ->callback('level_name', '등급명', function ($row) {
        $name = htmlspecialchars($row['level_name']);
        $icon = $row['is_super'] ? ' <i class="bi bi-shield-fill text-danger" title="최고관리자"></i>' : '';
        return "<strong>{$name}</strong>{$icon}";
    })
    ->callback('level_type', '타입', function ($row) use ($levelTypeColors, $levelTypeOptions) {
        $type = $row['level_type'];
        $color = $levelTypeColors[$type] ?? 'secondary';
        $label = htmlspecialchars($levelTypeOptions[$type] ?? $type);
        return '<span class="badge bg-' . $color . '-subtle text-' . $color . '-emphasis border border-' . $color . '-subtle">' . $label . '</span>';
    }, ['_th_attr' => ['style' => 'width:100px']])
    ->callback('is_admin', '관리자', function ($row) {
        if ($row['is_super']) {
            return '<i class="bi bi-check-circle-fill text-success"></i>';
        }
        $id = $row['level_id'];
        $checked = $row['is_admin'] ? 'checked' : '';
        return '<input type="checkbox" class="form-check-input" name="is_admin[' . $id . ']" value="1" ' . $checked . '>';
    }, ['_th_attr' => ['style' => 'width:80px', 'class' => 'text-center', 'title' => '관리자 모드 접근 권한'], '_td_attr' => ['class' => 'text-center']])
    ->callback('can_operate_domain', '도메인', function ($row) {
        if ($row['is_super']) {
            return '<i class="bi bi-check-circle-fill text-success"></i>';
        }
        $id = $row['level_id'];
        $checked = $row['can_operate_domain'] ? 'checked' : '';
        return '<input type="checkbox" class="form-check-input" name="can_operate_domain[' . $id . ']" value="1" ' . $checked . '>';
    }, ['_th_attr' => ['style' => 'width:80px', 'class' => 'text-center', 'title' => '도메인 소유/운영 가능'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) use ($editReturn) {
        $id = $row['level_id'];
        $name = htmlspecialchars($row['level_name']);
        $html = '<a href="/admin/member-levels/edit/' . $id . '?return=' . $editReturn . '" class="btn btn-sm btn-default">수정</a>';
        if (!$row['is_super']) {
            $html .= ' <button type="button" class="btn btn-sm btn-default btn-delete" data-id="' . $id . '" data-name="' . $name . '">삭제</button>';
        }
        return $html;
    }, ['_th_attr' => ['style' => 'width:120px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '회원 등급 관리') ?></h3>
            <p>회원 등급을 관리합니다. 등급은 모든 사이트에서 공통으로 사용됩니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/member-levels/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 등급 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/member-levels">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? $pagination['total'] ?? 0) ?></b> 개</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <!-- 레벨 타입 필터 -->
                        <div class="col col-xl-auto">
                            <select name="level_type" class="form-select form-select-sm">
                                <option value="">레벨 타입: 전체</option>
                                <?php foreach ($levelTypeOptions as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($currentFilters['level_type'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
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

        <!-- 등급 목록 폼 -->
        <form name="flist" id="flist">
            <!-- 등급 목록 테이블 -->
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($levelsData)
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
                            data-target="/admin/member-levels/list-modify"
                            data-callback="afterBulkUpdate"
                        >
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/member-levels/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 등급을 삭제하시겠습니까?\n\n이 등급을 사용 중인 회원이 있으면 삭제할 수 없습니다."
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

    <!-- 안내 문구 -->
    <div class="page-block">
        <div class="alert alert-secondary small">
            <i class="bi bi-info-circle"></i>
            권한을 변경하려면 등급을 선택 후 "선택 수정" 버튼을 클릭하세요. 최고관리자 등급은 수정/삭제할 수 없습니다.
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 전체 선택 체크박스 이벤트
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }

    // =========================================================================
    // 개별 삭제
    // =========================================================================
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function() {
            const levelId = this.dataset.id;
            const levelName = this.dataset.name;

            MubloRequest.showConfirm(
                `"${levelName}" 등급을 삭제하시겠습니까?\n\n이 등급을 사용 중인 회원이 있으면 삭제할 수 없습니다.`,
                function() {
                    MubloRequest.requestJson(`/admin/member-levels/delete/${levelId}`, {}, {
                        method: 'DELETE',
                        loading: true
                    }).then(response => {
                        location.reload();
                    }).catch(err => {});
                },
                { type: 'warning' }
            );
        });
    });
});

// 일괄 수정 후 콜백
function afterBulkUpdate(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '수정되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '수정에 실패했습니다.', 'error');
    }
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '삭제에 실패했습니다.', 'error');
    }
}
</script>
