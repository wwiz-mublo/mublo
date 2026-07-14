<?php
/**
 * Admin Member Fields - Index (2컬럼 레이아웃)
 *
 * 좌측: 필드 목록 (테이블)
 * 우측: 가이드 및 필드 추가 폼
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 *
 * @var string $pageTitle 페이지 제목
 * @var array $fields 필드 목록
 * @var array $fieldTypeOptions 필드 타입 옵션
 */

// 타입 배지 그룹/색상 (라벨 단일 정의는 MemberFieldService::getFieldTypeOptions)
$typeGroup = [
    'text'     => 'basic',
    'email'    => 'text',  'tel'    => 'text',  'number' => 'text',
    'date'     => 'text',  'textarea' => 'text',  'address' => 'text',
    'select'   => 'select', 'radio'  => 'choice', 'checkbox' => 'choice',
    'file'     => 'file',  'avatar' => 'file',
];
$typeGroupColor = [
    'basic'   => 'secondary',
    'text'    => 'primary',
    'select'  => 'info',
    'choice'  => 'success',
    'file'    => 'warning',
];

// ListColumnBuilder를 사용하여 컬럼 정의
$columns = $this->columns()
    ->callback('sort_handle', '', function ($row) {
        return '<i class="bi bi-arrows-move text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:40px'], '_td_attr' => ['class' => 'handle text-center', 'style' => 'cursor:grab']])
    ->callback('field_name', '필드명', function ($row) {
        // 2줄 표시: 필드명(code) + 라벨
        $html = '<div class="lh-sm">';
        $html .= '<code style="font-size: 0.8rem;">' . htmlspecialchars($row['field_name']) . '</code><br>';
        $html .= '<span class="fw-medium">' . htmlspecialchars($row['field_label']) . '</span>';
        $html .= '</div>';
        return $html;
    }, ['_td_attr' => ['class' => 'text-nowrap']])
    ->callback('field_type', '타입', function ($row) use ($fieldTypeOptions, $typeGroup, $typeGroupColor) {
        $type = $row['field_type'];
        $typeLabel = $fieldTypeOptions[$type] ?? $type;
        $color = $typeGroupColor[$typeGroup[$type] ?? 'basic'] ?? 'secondary';
        return '<span class="badge bg-' . $color . '-subtle text-' . $color
            . '-emphasis border border-' . $color . '-subtle">' . htmlspecialchars($typeLabel) . '</span>';
    }, ['_th_attr' => ['style' => 'width:90px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('is_required', '필수', function ($row) {
        return $row['is_required']
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<i class="bi bi-circle text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_encrypted', '암호화', function ($row) {
        return $row['is_encrypted']
            ? '<i class="bi bi-lock-fill text-primary" title="암호화 저장"></i>'
            : '<i class="bi bi-circle text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_searched', '검색', function ($row) {
        return $row['is_searched']
            ? '<i class="bi bi-search text-info" title="검색 가능"></i>'
            : '<i class="bi bi-circle text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_unique', '중복불가', function ($row) {
        return ($row['is_unique'] ?? false)
            ? '<i class="bi bi-fingerprint text-warning" title="중복 불가"></i>'
            : '<i class="bi bi-circle text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:70px', 'class' => 'text-center'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_visible_signup', '가입폼', function ($row) {
        return $row['is_visible_signup']
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<i class="bi bi-circle text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_visible_profile', '프로필', function ($row) {
        return $row['is_visible_profile']
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<i class="bi bi-circle text-muted"></i>';
    }, ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'], '_td_attr' => ['class' => 'text-center']])
    ->callback('actions', '관리', function ($row) {
        $html = '<a href="/admin/member-field/edit?id=' . $row['field_id'] . '" class="btn btn-sm btn-outline-secondary me-1">';
        $html .= '<i class="bi bi-pencil"></i></a>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-danger btn-delete-field" ';
        $html .= 'data-field-id="' . $row['field_id'] . '" ';
        $html .= 'data-field-name="' . htmlspecialchars($row['field_label']) . '">';
        $html .= '<i class="bi bi-trash"></i></button>';
        return $html;
    }, ['_th_attr' => ['style' => 'width:100px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '회원 추가 필드 관리') ?></h3>
            <p>회원가입 시 입력받을 추가 정보를 관리합니다.</p>
        </div>
    </div>

    <div class="row">
        <!-- 좌측: 필드 목록 -->
        <div class="col-lg-8 mb-4">

            <div class="page-block">
                <!-- 목록 정보 -->
                <div class="row align-items-center gy-2 gy-xl-0 mb-2">
                    <div class="col-auto">
                        <span class="ov">
                            <span class="ov-txt">전체</span>
                            <span class="ov-num"><b><?= number_format(count($fields)) ?></b> 개</span>
                        </span>
                    </div>
                </div>

                <!-- 목록 테이블 -->
                <div class="table-responsive">
                    <?php if (empty($fields)): ?>
                    <div class="text-center py-5 text-muted border rounded">
                        등록된 필드가 없습니다.<br>
                        <a href="/admin/member-field/create" class="btn btn-sm btn-outline-primary mt-2">첫 번째 필드 추가하기</a>
                    </div>
                    <?php else: ?>
                    <?= $this->listRenderHelper
                        ->setColumns($columns)
                        ->setRows($fields)
                        ->setSkin('table/basic')
                        ->setWrapAttr(['class' => 'table table-hover align-middle', 'id' => 'field-table'])
                        ->setTrAttr(function($row) {
                            return ['data-field-id' => $row['field_id']];
                        })
                        ->showHeader(true)
                        ->render() ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 우측: 가이드 -->
        <div class="col-lg-4 mb-4">
        <div class="page-block sticky-top" style="top: calc(var(--header-height) + 1.5rem);">
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-sky"></i>
                    <span>필드 가이드</span>
                </div>
                <div class="card-body">
                    <!-- 아이콘 설명 -->
                    <h6 class="fw-bold mb-3">아이콘 설명</h6>
                    <ul class="list-unstyled small mb-4">
                        <li class="mb-2">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <strong>활성화</strong> - 해당 기능이 켜져있음
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-circle text-muted"></i>
                            <strong>비활성화</strong> - 해당 기능이 꺼져있음
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-lock-fill text-primary"></i>
                            <strong>암호화</strong> - DB에 암호화되어 저장됨
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-search text-info"></i>
                            <strong>검색</strong> - 관리자 회원 검색에서 사용 가능
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-fingerprint text-warning"></i>
                            <strong>중복불가</strong> - 가입/수정 시 중복 체크 필요
                        </li>
                    </ul>

                    <!-- 드래그 정렬 안내 -->
                    <h6 class="fw-bold mb-3">정렬 변경</h6>
                    <p class="small text-muted mb-4">
                        <i class="bi bi-arrows-move"></i> 아이콘을 드래그하여 필드 순서를 변경할 수 있습니다.
                        변경된 순서는 자동 저장됩니다.
                    </p>

                    <!-- 주의사항 -->
                    <h6 class="fw-bold mb-3">
                        주의사항 <i class="bi bi-exclamation-triangle"></i>
                    </h6>
                    <ul class="small text-muted mb-0">
                        <li class="mb-1">암호화 + 검색 필드는 <strong class="text-danger">완전 일치</strong>만 검색됩니다.</li>
                        <li class="mb-1">암호화된 필드는 한번 설정하면 해제할 수 없습니다.</li>
                        <li class="mb-1">필드 삭제 시 저장된 데이터도 함께 삭제됩니다.</li>
                    </ul>
                </div>
                <div class="card-footer">
                    <a href="/admin/member-field/create" class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg"></i> 새 필드 추가
                    </a>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 드래그 앤 드롭 정렬
    var fieldTable = document.querySelector('#field-table tbody');
    if (fieldTable && fieldTable.children.length > 1) {
        new Sortable(fieldTable, {
            handle: '.handle',
            animation: 150,
            onEnd: function() {
                saveFieldOrder();
            }
        });
    }

    // 삭제 버튼
    document.querySelectorAll('.btn-delete-field').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldId = this.dataset.fieldId;
            var fieldName = this.dataset.fieldName;

            MubloRequest.showConfirm(
                '"' + fieldName + '" 필드를 삭제하시겠습니까?\n\n이 필드에 저장된 모든 회원 데이터도 함께 삭제됩니다.',
                function() { deleteField(fieldId); },
                { type: 'warning' }
            );
        });
    });
});

// 정렬 순서 저장
function saveFieldOrder() {
    var fieldIds = [];
    document.querySelectorAll('#field-table tbody tr[data-field-id]').forEach(function(row) {
        fieldIds.push(parseInt(row.dataset.fieldId));
    });

    MubloRequest.requestJson('/admin/member-field/order-update', { field_ids: fieldIds })
        .then(function(data) {
            if (data.result !== 'success') {
                MubloRequest.showAlert(data.message || '정렬 저장에 실패했습니다.', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
        });
}

// 필드 삭제
function deleteField(fieldId) {
    MubloRequest.requestJson('/admin/member-field/delete', { field_id: fieldId }, { loading: true })
        .then(function(data) {
            if (data.result === 'success') {
                location.reload();
            } else {
                MubloRequest.showAlert(data.message || '삭제에 실패했습니다.', 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
        });
}
</script>
