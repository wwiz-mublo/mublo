<?php
/**
 * Admin Member - Index
 *
 * 회원 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 * - $this->component($name, $data) : 컴포넌트 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $members 회원 목록 데이터
 * @var array $listFields 추가 필드 정보
 * @var array $pagination 페이지네이션 정보
 * @var array $searchFields 검색 필드 옵션
 * @var array $levelOptions 등급 선택 옵션 [level_value => level_name]
 * @var array $currentSearch 현재 검색 조건
 */

// 일시 2행 렌더 (1행: 일자, 2행: 시간)
$renderDateTime = function ($value): string {
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '<span class="text-muted">-</span>';
    }
    $parts = explode(' ', $value, 2);
    $date = htmlspecialchars($parts[0]);
    $time = htmlspecialchars(substr($parts[1] ?? '', 0, 8)); // HH:MM:SS
    return '<div>' . $date . '</div>'
        . ($time !== '' ? '<div class="text-muted small">' . $time . '</div>' : '');
};

// 수정 후 목록 복귀 URL (현재 검색/정렬/페이지 유지) — 수정 링크의 return 파라미터
$editReturn = rawurlencode('/admin/member' . (!empty($listQuery) ? '?' . $listQuery : ''));

// 컬럼 정의 (View에서 직접 정의)
$columnBuilder = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'member_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center text-nowrap']])
    ->add('member_id', '번호', ['sortable' => true, '_th_attr' => ['style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->add('user_id', '아이디', [
        'sortable' => true,
        'render' => function ($row) use ($selfMemberId) {
            $code = '<code>' . htmlspecialchars($row['user_id'] ?? '') . '</code>';
            $mid = (int) ($row['member_id'] ?? 0);
            $origin = $row['origin_domain_id'] ?? null;

            // 내 사이트(관리자 자신)
            if ($mid === (int) ($selfMemberId ?? 0)) {
                $badge = '<span title="내 사이트"><i class="bi bi-person-workspace"></i></span>';
                return '<div class="d-flex align-items-center gap-2">' . $badge . $code . '</div>';
            }
            // 독립(사이트개설)해나간 회원(현재 도메인 ≠ 태생 도메인)
            if ($origin !== null && (int) $origin !== (int) ($row['domain_id'] ?? 0)) {
                $dom = $row['operating_domain'] ?? '';
                $title = $dom !== '' ? '운영 사이트: ' . $dom : '사이트를 개설해 독립한 회원';
                $badge = '<span title="' . htmlspecialchars($title) . '"><i class="bi bi-window"></i></span>';
                return '<div class="d-flex align-items-center gap-2">' . $badge . $code . '</div>';
            }
            return $code;
        },
        '_th_attr' => ['style' => 'width:140px'],
        '_td_attr' => ['class' => 'text-nowrap'],
    ])
    ->add('nickname', '닉네임', ['sortable' => true])
    ->select('level_value', '등급', $levelOptions, ['id_key' => 'member_id', 'sortable' => true, 'disabled_values' => $disabledLevels ?? [], 'disabled_rows' => [$selfMemberId ?? 0], '_th_attr' => ['style' => 'width:130px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->select('status', '상태', [
        'active' => '활성',
        'inactive' => '비활성',
        'pending' => '대기',
        'blocked' => '차단',
    ], ['id_key' => 'member_id', 'sortable' => true, '_th_attr' => ['style' => 'width:100px'], '_td_attr' => ['class' => 'text-nowrap']]);

// 추가 필드 컬럼 동적 추가
foreach ($listFields as $field) {
    $fieldKey = 'field_' . $field['field_name'];
    $fieldLabel = $field['field_label'];
    // 암호화 필드는 🔒 표시
    if (!empty($field['is_encrypted'])) {
        $fieldLabel = "🔒 {$fieldLabel}";
    }

    if (in_array($field['field_type'], ['file', 'avatar'], true)) {
        $isAvatar = $field['field_type'] === 'avatar';
        $columnBuilder->add($fieldKey, $fieldLabel, [
            'render' => function ($row) use ($fieldKey, $isAvatar) {
                $val = $row[$fieldKey] ?? '';
                if (empty($val) || !is_array($val)) {
                    return '-';
                }
                $url = htmlspecialchars($val['url'] ?? '', ENT_QUOTES);
                $filename = htmlspecialchars($val['filename'] ?? '', ENT_QUOTES);

                // 아바타: 원형 썸네일
                if ($isAvatar) {
                    if (!$url) {
                        return '-';
                    }
                    return '<img src="' . $url . '" alt="" class="rounded-circle border"'
                        . ' style="width:32px;height:32px;object-fit:cover;">';
                }

                // 첨부파일: 다운로드 링크
                if (!$url) {
                    return $filename ?: '-';
                }
                return '<a href="' . $url . '" title="' . $filename . '" class="text-decoration-none"><i class="bi bi-file-earmark-arrow-down"></i> ' . $filename . '</a>';
            },
            '_th_attr' => $isAvatar ? ['class' => 'text-center'] : [],
            '_td_attr' => ['class' => 'text-nowrap' . ($isAvatar ? ' text-center' : '')],
        ]);
    } else {
        $columnBuilder->add($fieldKey, $fieldLabel, ['_td_attr' => ['class' => 'text-nowrap']]);
    }
}

// 기본 컬럼 계속
$columns = $columnBuilder
    ->add('point', '포인트', [
        'sortable' => true,
        'sort_key' => 'point_balance', // 실제 DB 컬럼 (엔티티는 point로 별칭)
        'render' => function ($row) {
            $memberId = (int) ($row['member_id'] ?? 0);
            $point = number_format($row['point'] ?? 0);
            $url = '/admin/point?activeCode=003_002&member_id=' . $memberId;
            // 좌: 포인트 내역 아이콘 / 우: 포인트 값 (space-between)
            return '<div class="d-flex justify-content-between align-items-center">'
                . '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener"'
                . ' class="text-reset point-history-link" title="포인트 내역"><i class="bi bi-coin"></i></a>'
                . '<span>' . $point . '</span>'
                . '</div>';
        },
        '_th_attr' => ['style' => 'width:100px'],
        '_td_attr' => ['class' => 'text-nowrap'],
    ])
    ->add('created_at', '가입일시', [
        'sortable' => true,
        'render' => fn($row) => $renderDateTime($row['created_at'] ?? ''),
        '_th_attr' => ['style' => 'width:120px'],
        '_td_attr' => ['class' => 'text-nowrap'],
    ])
    ->add('last_login_at', '최종 로그인', [
        'sortable' => true,
        'render' => fn($row) => $renderDateTime($row['last_login_at'] ?? ''),
        '_th_attr' => ['style' => 'width:120px'],
        '_td_attr' => ['class' => 'text-nowrap'],
    ])
    ->actions('actions', '관리', function ($row) use ($editReturn) {
        $id = $row['member_id'];
        return '<a href="/admin/member/edit/' . $id . '?return=' . $editReturn . '" class="btn btn-sm btn-default">수정</a>'
             . ' <button type="button" class="btn btn-sm btn-default" onclick="deleteMember(' . $id . ')">삭제</button>';
    }, ['_th_attr' => ['style' => 'width:120px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<style>
/* 인라인 셀렉트 변경됨 표시 */
#flist select.list-changed {
    border-color: var(--bs-warning, #ffc107);
    background-color: var(--bs-warning-bg-subtle, #fff3cd);
    font-weight: 600;
}
/* 포인트 내역 아이콘: 평소 연하게, 호버 시 강하게 */
.point-history-link {
    opacity: .4;
    transition: opacity .15s ease-in-out;
}
.point-history-link:hover,
.point-history-link:focus {
    opacity: 1;
}
</style>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '회원 관리') ?></h3>
            <p>사이트 회원을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/member/create" class="btn btn-sm btn-primary">
                <i class="bi bi-person-plus"></i> 회원 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/member">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 명</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <select name="level" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">등급: 전체</option>
                                <?php foreach ($levelOptions as $lv => $lname): ?>
                                <option value="<?= $lv ?>" <?= (string) ($currentFilters['level'] ?? '') === (string) $lv ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lname) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">상태: 전체</option>
                                <?php foreach (($statusOptions ?? []) as $sv => $sname): ?>
                                <option value="<?= htmlspecialchars($sv) ?>" <?= ($currentFilters['status'] ?? '') === $sv ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sname) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <select name="search_field" class="form-select form-select-sm">
                                <option value="">검색 필드</option>
                                <?php foreach ($searchFields as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($currentSearch['field'] ?? '') === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="search_keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="검색어 입력"
                                       value="<?= htmlspecialchars($currentSearch['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($currentSearch['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/member'"></i>
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

        <!-- 회원 목록 폼 -->
        <form name="flist" id="flist">
            <!-- 회원 목록 테이블 -->
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($members)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->setSort(
                        $currentSort['field'] ?? null,
                        $currentSort['order'] ?? 'DESC',
                        array_filter([
                            'search_field' => $currentSearch['field'] ?? '',
                            'search_keyword' => $currentSearch['keyword'] ?? '',
                        ], fn($v) => $v !== '')
                    )
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
                            data-target="/admin/member/listModify"
                            data-callback="afterBulkUpdate"
                        >
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/member/listDelete"
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

// 행의 셀렉트(등급/상태) 변경 시 해당 행 체크박스 자동 체크 + 변경 표시
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

// 회원 삭제 (단건)
function deleteMember(memberId) {
    MubloRequest.showConfirm('정말 이 회원을 삭제하시겠습니까?\n\n이 작업은 되돌릴 수 없습니다.', function() {
        MubloRequest.requestJson('/admin/member/delete/' + memberId, {}, { method: 'DELETE', loading: true })
            .then(function(data) {
                location.reload();
            })
            .catch(function(error) {
                console.error('Error:', error);
                MubloRequest.showAlert('삭제 중 오류가 발생했습니다.', 'error');
            });
    }, { type: 'warning' });
}

// 일괄 수정 후 콜백
function afterBulkUpdate(data) {
    MubloRequest.showToast(data.message || '수정되었습니다.', 'success');
    location.reload();
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
    location.reload();
}
</script>
