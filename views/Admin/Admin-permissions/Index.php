<?php
/**
 * 관리자 접근 권한 관리 - 메인 (2컬럼 레이아웃)
 *
 * 좌측: 등록된 권한 제한 목록 (테이블 형식)
 * 우측: 권한 설정 폼 (레벨 선택 → 메뉴 선택 → 서브메뉴별 액션 체크)
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle
 * @var array $permissions 권한 제한 목록 (flat 배열)
 * @var array $pagination 페이지네이션 정보
 * @var array $adminLevelOptions [level_value => level_name, ...]
 * @var array $levelNames [level_value => level_name, ...]
 * @var array $topMenus [['code' => string, 'label' => string, 'icon' => string, 'group' => string], ...]
 * @var array $allMenus 전체 메뉴
 * @var string $filterLevelValue 필터 레벨
 * @var array $actionGroupLabels ['r' => '읽기', 'w' => '쓰기', 'd' => '삭제', 'f' => '파일']
 */

// 헬퍼 함수: 메뉴 코드로 메뉴 정보 찾기 (1차 메뉴명, 서브메뉴명, 출처 분리)
if (!function_exists('getMenuInfoFromCode')) {
    function getMenuInfoFromCode(array $menus, string $code): array
    {
        $default = ['parent' => $code ?: '-', 'child' => '-', 'source' => 'core', 'sourceName' => ''];

        if (empty($menus) || empty($code)) {
            return $default;
        }

        foreach ($menus as $groupKey => $group) {
            if (!is_array($group)) {
                continue;
            }

            $items = $group['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemCode = $item['code'] ?? '';
                $itemLabel = $item['label'] ?? '';

                // 1차 메뉴 코드 일치
                if ($itemCode === $code) {
                    return [
                        'parent' => $itemLabel ?: $code,
                        'child' => '-',
                        'source' => $item['source'] ?? 'core',
                        'sourceName' => $item['sourceName'] ?? '',
                    ];
                }

                // 서브메뉴 검색
                $submenu = $item['submenu'] ?? [];
                if (is_array($submenu)) {
                    foreach ($submenu as $sub) {
                        if (!is_array($sub)) {
                            continue;
                        }
                        $subCode = $sub['code'] ?? '';
                        $subLabel = $sub['label'] ?? '';

                        if ($subCode === $code) {
                            return [
                                'parent' => $itemLabel ?: $itemCode,
                                'child' => $subLabel ?: $code,
                                'source' => $sub['source'] ?? 'core',
                                'sourceName' => $sub['sourceName'] ?? '',
                            ];
                        }
                    }
                }
            }
        }

        // 못 찾은 경우: 코드에서 부모 메뉴 다시 검색
        if (str_contains($code, '_')) {
            $lastUnderscore = strrpos($code, '_');
            $parentCode = substr($code, 0, $lastUnderscore);

            // 부모 메뉴 이름 찾기
            foreach ($menus as $group) {
                if (!is_array($group)) continue;
                foreach ($group['items'] ?? [] as $item) {
                    if (!is_array($item)) continue;
                    if (($item['code'] ?? '') === $parentCode) {
                        return [
                            'parent' => $item['label'] ?? $parentCode,
                            'child' => $code,
                            'source' => 'core',
                            'sourceName' => '',
                        ];
                    }
                }
            }

            return ['parent' => $parentCode, 'child' => $code, 'source' => 'core', 'sourceName' => ''];
        }

        return $default;
    }
}

// 헬퍼 함수: denied_actions 문자열을 액션 그룹으로 변환
if (!function_exists('getDeniedActionGroups')) {
    function getDeniedActionGroups(string $deniedActions): array
    {
        if ($deniedActions === '*') {
            return ['l', 'r', 'w', 'd', 'f'];
        }
        $actionGroups = [
            'l' => ['list'],
            'r' => ['read'],
            'w' => ['write', 'edit'],
            'd' => ['delete'],
            'f' => ['download'],
        ];
        $actions = array_map('trim', explode(',', $deniedActions));
        $result = [];
        foreach ($actionGroups as $group => $groupActions) {
            foreach ($groupActions as $action) {
                if (in_array($action, $actions, true)) {
                    $result[] = $group;
                    break;
                }
            }
        }
        return $result;
    }
}

// permissions 데이터 가공 (메뉴 정보 추가)
$flatList = [];
foreach ($permissions as $item) {
    $menuInfo = getMenuInfoFromCode($allMenus, $item['menu_code']);
    $deniedGroups = getDeniedActionGroups($item['denied_actions']);
    $flatList[] = [
        'id' => $item['id'],
        'level_value' => $item['level_value'],
        'level_name' => $item['level_name'],
        'menu_code' => $item['menu_code'],
        'parent_menu' => $menuInfo['parent'],
        'child_menu' => $menuInfo['child'],
        'source' => $menuInfo['source'],
        'sourceName' => $menuInfo['sourceName'],
        'denied_actions' => $item['denied_actions'],
        'denied_groups' => $deniedGroups,
    ];
}

// 컬럼 정의 (ListColumnBuilder 패턴)
$columns = $this->columns()
    ->checkbox('chk', '', [
        'id_key' => 'id',
        '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'],
        '_cell_attr' => ['class' => 'text-center']
    ])
    ->callback('level_value', '등급', function ($row) {
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">' . $row['level_value'] . '</span>';
    }, ['_th_attr' => ['style' => 'width:60px']])
    ->add('level_name', '등급명')
    ->add('parent_menu', '1차 메뉴')
    ->callback('child_menu', '적용 메뉴', function ($row) {
        // 2줄 표시: 코드(작게) + 메뉴명
        $html = '<div class="lh-sm">';
        $html .= '<code class="text-muted" style="font-size: 0.75rem;">' . htmlspecialchars($row['menu_code']) . '</code>';
        // 플러그인/패키지 출처 표시
        if ($row['source'] === 'plugin' && !empty($row['sourceName'])) {
            $html .= ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.6rem;">[P]</span>';
        } elseif ($row['source'] === 'package' && !empty($row['sourceName'])) {
            $html .= ' <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" style="font-size: 0.6rem;">[K]</span>';
        }
        $html .= '<br>';
        $html .= '<span class="small">' . htmlspecialchars($row['child_menu']) . '</span>';
        $html .= '</div>';
        return $html;
    })
    ->callback('denied_groups', '적용 권한', function ($row) use ($actionGroupLabels) {
        $html = '';
        foreach (['l', 'r', 'w', 'd', 'f'] as $group) {
            $isChecked = in_array($group, $row['denied_groups'], true);
            $bgClass = $isChecked ? 'bg-danger-subtle text-danger-emphasis border border-danger-subtle' : 'bg-body-secondary text-muted border';
            $html .= '<span class="badge ' . $bgClass . '" style="font-size: 0.7rem; margin-right: 2px;" title="' . ($actionGroupLabels[$group] ?? $group) . '">';
            $html .= strtoupper($group);
            $html .= '</span>';
        }
        return $html;
    }, ['_th_attr' => ['style' => 'width:160px']])
    ->actions('actions', '관리', function ($row) {
        $id = $row['id'];
        $html = '<button type="button" class="btn btn-outline-primary btn-sm btn-edit-item me-1" ';
        $html .= 'data-id="' . $id . '" data-level="' . $row['level_value'] . '" data-menu="' . htmlspecialchars($row['menu_code']) . '" title="수정">';
        $html .= '<i class="bi bi-pencil"></i></button>';
        $html .= '<button type="button" class="btn btn-outline-danger btn-sm btn-delete-item" data-id="' . $id . '" title="삭제">';
        $html .= '<i class="bi bi-trash"></i></button>';
        return $html;
    }, ['_th_attr' => ['style' => 'width:80px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '관리자 접근 권한 관리') ?></h3>
            <p>관리자 등급별로 접근 불가 메뉴를 설정합니다. 등록되지 않은 메뉴는 기본적으로 접근 허용됩니다.</p>
        </div>
    </div>

    <div class="row">
        <!-- 좌측: 등록된 권한 제한 목록 -->
        <div class="col-lg-8 mb-4">
            <div class="page-block">
                <!-- 검색 영역 -->
                <form method="get" name="fsearch" id="fsearch" class="mb-2">
                    <div class="row align-items-center gy-2 gy-xl-0">
                        <div class="col-auto">
                            <span class="ov">
                                <span class="ov-txt"><a href="/admin/admin-permissions">전체</a></span>
                                <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
                            </span>
                        </div>
                        <div class="col col-xl-auto ms-xl-auto">
                            <div class="row gx-2">
                                <!-- 등급 필터 -->
                                <div class="col col-xl-auto">
                                    <select name="level_value" class="form-select form-select-sm">
                                        <option value="">등급: 전체</option>
                                        <?php foreach ($adminLevelOptions as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $filterLevelValue == $value ? 'selected' : '' ?>>
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

                <!-- 목록 폼 -->
                <form name="flist" id="flist">
                    <!-- 목록 테이블 -->
                    <div class="table-responsive">
                        <?= $this->listRenderHelper
                            ->setColumns($columns)
                            ->setRows($flatList)
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
                                    data-target="/admin/admin-permissions/bulk-delete"
                                    data-callback="afterBulkDelete"
                                    data-confirm="선택한 권한 제한을 삭제하시겠습니까?"
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
            <div class="page-block">
                <!-- 안내 문구 -->
                <div class="alert alert-secondary small">
                    <i class="bi bi-info-circle"></i>
                    체크박스에 체크한 권한은 해당 등급에서 <strong>차단</strong>됩니다. (네거티브 방식)
                </div>
            </div>
        </div>

        <!-- 우측: 권한 설정 폼 -->
        <div class="col-lg-4 mb-4">
            <div class="page-block sticky-top" style="top: calc(var(--header-height) + 1.5rem);">
                <div class="card">
                    <div class="card-hero">
                        <i class="bi bi-shield-lock text-pastel-purple"></i>
                        <span>권한 설정</span>
                    </div>
                    <div class="card-body">
                        <form id="permissionForm">
                            <!-- 등급 선택 -->
                            <div class="mb-3">
                                <label for="levelSelect" class="form-label fw-bold">관리자 등급 선택</label>
                                <select id="levelSelect" name="formData[level_value]" class="form-select" required>
                                    <option value="">등급을 선택하세요</option>
                                    <?php foreach ($adminLevelOptions as $value => $label): ?>
                                        <option value="<?= $value ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    최고관리자 등급은 권한을 제한할 수 없습니다.
                                </div>
                            </div>

                            <!-- 1차 메뉴 선택 -->
                            <div class="mb-3">
                                <label for="menuSelect" class="form-label fw-bold">1차 메뉴 선택</label>
                                <select id="menuSelect" name="formData[menu_code]" class="form-select" required disabled>
                                    <option value="">메뉴를 선택하세요</option>
                                    <?php foreach ($topMenus as $menu): ?>
                                        <option value="<?= htmlspecialchars($menu['code']) ?>">
                                            [<?= htmlspecialchars($menu['group']) ?>] <?= htmlspecialchars($menu['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 서브메뉴 체크박스 영역 -->
                            <div id="submenuArea" class="mb-3" style="display: none;">
                                <label class="form-label fw-bold">서브메뉴별 차단 권한 설정</label>
                                <p class="text-muted small mb-2">
                                    체크한 항목은 해당 등급에서 <strong class="text-danger">접근 불가</strong>합니다.
                                </p>

                                <!-- 액션 그룹 헤더 -->
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle mb-0" id="submenuTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 40%;">메뉴</th>
                                                <?php foreach ($actionGroupLabels as $group => $label): ?>
                                                    <?php
                                                    $tooltips = [
                                                        'l' => '목록 페이지 접근',
                                                        'r' => '상세 페이지 읽기',
                                                        'w' => '작성/수정 (쓰기 차단 시 수정도 차단)',
                                                        'd' => '삭제',
                                                        'f' => '파일 다운로드',
                                                    ];
                                                    ?>
                                                    <th class="text-center" style="width: 12%;">
                                                        <span title="<?= $tooltips[$group] ?? $label ?>">
                                                            <?= htmlspecialchars($label) ?>
                                                        </span>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody id="submenuBody">
                                            <!-- AJAX로 채워짐 -->
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-3">
                                                    등급과 메뉴를 선택하면 서브메뉴가 표시됩니다.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- 저장 버튼 -->
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="btnSave" disabled>
                                    <i class="bi bi-check-lg"></i> 권한 저장
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const permissionForm = document.getElementById('permissionForm');
    const levelSelect = document.getElementById('levelSelect');
    const menuSelect = document.getElementById('menuSelect');
    const submenuArea = document.getElementById('submenuArea');
    const submenuBody = document.getElementById('submenuBody');
    const btnSave = document.getElementById('btnSave');
    const actionGroups = <?= json_encode(array_keys($actionGroupLabels)) ?>;

    // 목록 전체 선택 (헤더 chk_all → 각 행 chk[])
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            document.querySelectorAll('#flist input[name="chk[]"]').forEach(function(cb) {
                cb.checked = checkAll.checked;
            });
        });
    }

    // =========================================================================
    // 폼 제출 처리
    // =========================================================================
    permissionForm.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!levelSelect.value) {
            MubloRequest.showAlert('등급을 선택해주세요.', 'warning');
            return;
        }

        if (!menuSelect.value) {
            MubloRequest.showAlert('메뉴를 선택해주세요.', 'warning');
            return;
        }

        // 서브메뉴별 체크된 액션 그룹 수집
        // 모든 서브메뉴 코드를 빈 배열로 초기화 (체크 해제된 메뉴도 서버에 전달)
        const submenu = {};
        const allCheckboxes = submenuBody.querySelectorAll('input[type="checkbox"]');
        allCheckboxes.forEach(cb => {
            const match = cb.name.match(/formData\[submenu\]\[([^\]]+)\]/);
            if (match) {
                const menuCode = match[1];
                if (!submenu[menuCode]) {
                    submenu[menuCode] = [];
                }
                if (cb.checked) {
                    submenu[menuCode].push(cb.value);
                }
            }
        });

        // JSON 객체로 전송
        const data = {
            formData: {
                level_value: levelSelect.value,
                menu_code: menuSelect.value,
                submenu: submenu
            }
        };

        MubloRequest.requestJson('/admin/admin-permissions/store', data, {
            method: 'POST',
            loading: true
        }).then(response => {
            MubloRequest.showToast(response.message || '권한이 저장되었습니다.', 'success');
            if (response.data && response.data.redirect) {
                location.href = response.data.redirect;
            } else {
                location.reload();
            }
        });
    });

    // =========================================================================
    // 등급 선택 시 메뉴 select 활성화
    // =========================================================================
    levelSelect.addEventListener('change', function() {
        if (this.value) {
            menuSelect.disabled = false;
            menuSelect.value = '';
            submenuArea.style.display = 'none';
            btnSave.disabled = true;
        } else {
            menuSelect.disabled = true;
            menuSelect.value = '';
            submenuArea.style.display = 'none';
            btnSave.disabled = true;
        }
    });

    // =========================================================================
    // 메뉴 선택 시 서브메뉴 로드
    // =========================================================================
    menuSelect.addEventListener('change', function() {
        const menuCode = this.value;
        const levelValue = levelSelect.value;

        if (!menuCode || !levelValue) {
            submenuArea.style.display = 'none';
            btnSave.disabled = true;
            return;
        }

        // 로딩 표시
        submenuBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                    서브메뉴를 불러오는 중...
                </td>
            </tr>
        `;
        submenuArea.style.display = 'block';

        // 서브메뉴 AJAX 요청 (menuCode에 콜론이 포함될 수 있으므로 인코딩)
        MubloRequest.requestJson(`/admin/admin-permissions/submenus/${encodeURIComponent(menuCode)}?level_value=${levelValue}`, {}, {
            method: 'GET'
        }).then(response => {
            if (response.result === 'success' && response.data && response.data.submenus) {
                renderSubmenus(response.data.submenus);
                btnSave.disabled = false;
            } else {
                submenuBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">
                            서브메뉴가 없습니다.
                        </td>
                    </tr>
                `;
                btnSave.disabled = true;
            }
        }).catch(err => {
            submenuBody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-danger py-3">
                        서브메뉴를 불러오는 중 오류가 발생했습니다.
                    </td>
                </tr>
            `;
            btnSave.disabled = true;
        });
    });

    // =========================================================================
    // 서브메뉴 렌더링
    // =========================================================================
    function renderSubmenus(submenus) {
        if (!submenus || submenus.length === 0) {
            submenuBody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted py-3">
                        서브메뉴가 없습니다.
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        submenus.forEach((sub, index) => {
            html += `<tr>`;
            html += `<td>`;
            html += `<div class="lh-sm">`;
            html += `<code style="font-size: 0.75rem;">${escapeHtml(sub.code)}</code><br>`;
            html += `<span class="fw-medium">${escapeHtml(sub.label)}</span>`;
            html += `</div>`;
            html += `</td>`;

            // 각 액션 그룹별 체크박스
            actionGroups.forEach(group => {
                const isChecked = sub.checkedGroups && sub.checkedGroups.includes(group);
                html += `<td class="text-center">`;
                html += `<input type="checkbox" class="form-check-input" `;
                html += `name="formData[submenu][${sub.code}][]" `;
                html += `value="${group}" `;
                html += isChecked ? 'checked' : '';
                html += `>`;
                html += `</td>`;
            });

            html += `</tr>`;
        });

        submenuBody.innerHTML = html;
    }

    // HTML 이스케이프
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =========================================================================
    // 개별 삭제
    // =========================================================================
    document.querySelectorAll('.btn-delete-item').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;

            MubloRequest.showConfirm('이 권한 제한을 삭제하시겠습니까?', function() {
                MubloRequest.requestJson(`/admin/admin-permissions/delete/${id}`, {}, {
                    method: 'POST',
                    loading: true
                }).then(response => {
                    location.reload();
                });
            }, { type: 'warning' });
        });
    });

    // =========================================================================
    // 수정 버튼 클릭 (해당 등급/메뉴 선택)
    // =========================================================================
    document.querySelectorAll('.btn-edit-item').forEach(btn => {
        btn.addEventListener('click', function() {
            const levelValue = this.dataset.level;
            const menuCode = this.dataset.menu;

            // 메뉴 코드에서 1차 메뉴 코드 추출 (003_001 -> 003)
            const parentMenuCode = menuCode.includes('_') ? menuCode.split('_')[0] : menuCode;

            // 등급 선택
            levelSelect.value = levelValue;
            levelSelect.dispatchEvent(new Event('change'));

            // 1차 메뉴 선택 (약간의 딜레이 후)
            setTimeout(() => {
                menuSelect.value = parentMenuCode;
                menuSelect.dispatchEvent(new Event('change'));
            }, 100);

            // 우측 폼으로 스크롤
            permissionForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
});

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
    location.reload();
}
</script>
