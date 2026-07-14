<?php
/**
 * Admin Blockrow - Index
 *
 * 블록 행 목록
 *
 * @var string $pageTitle 페이지 제목
 * @var array $rows 행 목록 (전량 — 페이지네이션 없음)
 * @var array $positions 위치 목록
 * @var array $pages 페이지 목록
 * @var string|null $currentPosition 현재 선택된 위치
 * @var int $currentPageId 현재 선택된 페이지 ID
 */

// 편집 화면으로 넘길 위치 필터 (복귀 시 유지용). activeCode는 전역 스크립트가 처리.
$filterSuffix = !empty($currentPosition) ? '&filter_position=' . urlencode($currentPosition) : '';

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'row_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('sort', '', [
        'render' => fn($row) => '<i class="bi bi-arrows-move text-muted handle" style="cursor:move"></i>',
        '_th_attr' => ['style' => 'width:40px']
    ])
    ->add('row_id', 'ID', [
        'render' => fn($row) => '<small class="text-muted">#' . $row['row_id'] . '</small>',
        '_th_attr' => ['style' => 'width:60px']
    ])
    ->add('sort_order', '순서', [
        'render' => function($row) {
            $id = $row['row_id'];
            $order = $row['sort_order'] ?? 0;
            return '<input type="number" class="form-control form-control-sm sort-order-input"
                    name="sort_order[' . $id . ']" value="' . $order . '"
                    style="width:70px; text-align:center" min="0">';
        },
        '_th_attr' => ['style' => 'width:80px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->add('admin_title', '관리 제목', [
        'render' => function($row) {
            if (empty($row['admin_title'])) {
                return '<span class="text-muted">(제목없음)</span>';
            }
            return '<strong>' . htmlspecialchars($row['admin_title']) . '</strong>';
        },
        '_th_attr' => ['class' => 'col-admin-title'],
        '_td_attr' => ['class' => 'col-admin-title'],
    ])
    ->add('target', '출력 대상', [
        'render' => function($row) use ($positions, $pages, $menuLabels) {
            if ($row['page_id']) {
                // 페이지 기반
                $pageBadge = '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">페이지</span> ';
                foreach ($pages as $page) {
                    if ($page['value'] == $row['page_id']) {
                        return $pageBadge . htmlspecialchars($page['label']);
                    }
                }
                return $pageBadge . '#' . $row['page_id'];
            } elseif ($row['position']) {
                // 위치 기반. index(메인화면)는 메뉴 개념이 없으므로 스코프 표시를 생략한다.
                $label = $positions[$row['position']] ?? $row['position'];
                $scope = '';
                if ($row['position'] !== 'index') {
                    $menuCode = $row['position_menu'] ?? '';
                    if ($menuCode === '') {
                        $scope = ' <small class="text-muted">· 전역</small>';
                    } elseif (isset($menuLabels[$menuCode])) {
                        $scope = ' <small class="text-muted">· ' . htmlspecialchars($menuLabels[$menuCode]) . '</small>';
                    } else {
                        // 묶여 있던 메뉴가 삭제됐다. 원시 코드를 날것으로 보여주지 않는다.
                        $scope = ' <small class="text-danger">· 삭제된 메뉴</small>';
                    }
                }
                return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">위치</span> ' . htmlspecialchars($label) . $scope;
            }
            return '-';
        },
        '_th_attr' => []
    ])
    ->add('column_count', '칸', [
        'render' => function($row) {
            $configured = $row['column_count'] ?? 1;
            $actual = $row['column_count_actual'] ?? 0;
            $hasContent = $row['column_has_content'] ?? [];

            // 칸이 아예 없으면 경고 표시
            if ($actual === 0) {
                return '<span class="column-box-warning" title="칸 설정 필요"><i class="bi bi-exclamation-triangle text-warning"></i></span>';
            }

            $boxes = '';
            for ($i = 0; $i < $configured; $i++) {
                if ($i < $actual) {
                    // 실제 생성된 칸
                    $hasCont = $hasContent[$i] ?? false;
                    $boxClass = $hasCont ? 'column-box column-box--filled' : 'column-box column-box--empty';
                    $title = $hasCont ? '콘텐츠 있음' : '콘텐츠 없음';
                } else {
                    // 설정됐지만 미생성된 칸
                    $boxClass = 'column-box column-box--missing';
                    $title = '칸 미생성';
                }
                $boxes .= '<span class="' . $boxClass . '" title="' . $title . '"></span>';
            }
            return '<div class="column-boxes">' . $boxes . '</div>';
        },
        '_th_attr' => ['style' => 'width:100px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->add('block_usage', '사용 블록', [
        'render' => function($row) {
            $usage = $row['block_usage'] ?? [];
            if (empty($usage)) {
                return '<span class="text-muted small">—</span>';
            }

            // kind 별 색상. 미설치 확장은 회색 + 경고 아이콘으로 "설치 필요"를 알린다.
            $kindClass = [
                'CORE'    => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
                'PLUGIN'  => 'bg-success-subtle text-success-emphasis border border-success-subtle',
                'PACKAGE' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            ];

            $badges = '';
            foreach ($usage as $block) {
                if (!empty($block['installed'])) {
                    $cls = $kindClass[$block['kind']] ?? $kindClass['CORE'];
                    $text = htmlspecialchars($block['label']);
                    $icon = '';
                } else {
                    // 미설치 확장 — 라벨을 알 수 없어 코드를 보여주되 "설치 필요"임을 표시한다.
                    $cls = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                    $text = htmlspecialchars($block['type']);
                    $icon = '<i class="bi bi-exclamation-triangle"></i> ';
                }
                $count = ((int) $block['count']) > 1 ? ' ×' . (int) $block['count'] : '';
                $badges .= '<span class="badge ' . $cls . ' me-1 mb-1">' . $icon . $text . $count . '</span>';
            }
            return '<div class="d-flex flex-wrap">' . $badges . '</div>';
        },
        '_th_attr' => []
    ])
    ->add('width_type', '넓이', [
        'render' => function($row) {
            return $row['width_type'] == 0 ? '와이드' : '최대넓이';
        },
        '_th_attr' => ['style' => 'width:80px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->add('is_active', '상태', [
        'render' => function($row) {
            $id = $row['row_id'];
            $checked = !empty($row['is_active']) ? 'checked' : '';
            return '<div class="form-check form-switch mb-0 d-flex justify-content-center">'
                . '<input class="form-check-input" type="checkbox" role="switch" ' . $checked . ' onchange="toggleRowActive(' . $id . ', this)">'
                . '</div>';
        },
        '_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->actions('actions', '관리', function($row) use ($filterSuffix, $positions, $pages) {
        $id = $row['row_id'];
        $name = htmlspecialchars($row['admin_title'] ?: '행 #' . $id, ENT_QUOTES, 'UTF-8');

        // 현재 위치(자신의 위치) — 복사/이동 모달 폼에 기본 선택 (그대로 실행 시 같은 위치)
        if (!empty($row['page_id'])) {
            $curType = 'page';
            $curValue = (string) (int) $row['page_id'];
        } elseif (!empty($row['position'])) {
            $curType = 'position';
            $curValue = htmlspecialchars($row['position'], ENT_QUOTES);
        } else {
            $curType = 'position';
            $curValue = '';
        }

        $html  = '<div class="d-flex gap-1">';
        // 그룹 1: 보기 · 수정 · 갱신
        $html .= '<div class="btn-group btn-group-sm">';
        $html .= '<button type="button" class="btn btn-outline-info js-row-preview" data-row-id="' . $id . '" data-row-name="' . $name . '" title="미리보기"><i class="bi bi-eye"></i></button>';
        $html .= '<a href="/admin/block-row/edit?id=' . $id . $filterSuffix . '" class="btn btn-default" title="수정"><i class="bi bi-pencil"></i></a>';
        $html .= '<button type="button" class="btn btn-outline-success js-row-cache-refresh" data-row-id="' . $id . '" data-row-name="' . $name . '" title="캐시 갱신"><i class="bi bi-arrow-clockwise"></i></button>';
        $html .= '</div>';
        // 그룹 2: 복사 · 이동 · 삭제
        $html .= '<div class="btn-group btn-group-sm">';
        $html .= '<button type="button" class="btn btn-outline-primary js-row-copy" data-row-id="' . $id . '" data-row-name="' . $name . '" data-current-type="' . $curType . '" data-current-value="' . $curValue . '" title="복사"><i class="bi bi-copy"></i></button>';
        $html .= '<button type="button" class="btn btn-outline-secondary js-row-move" data-row-id="' . $id . '" data-row-name="' . $name . '" data-current-type="' . $curType . '" data-current-value="' . $curValue . '" title="이동"><i class="bi bi-arrows-move"></i></button>';
        $html .= '<button type="button" class="btn btn-outline-danger js-row-delete" data-row-id="' . $id . '" data-row-name="' . $name . '" title="삭제"><i class="bi bi-trash"></i></button>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }, ['_th_attr' => []])
    ->build();
?>
<style>
/* 관리 제목 열만 줄바꿈 허용, 나머지 열은 nowrap */
#row-list th, #row-list td { white-space: nowrap; }
#row-list th.col-admin-title, #row-list td.col-admin-title { white-space: normal; }
</style>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '블록 행 관리') ?></h3>
            <p>
                <?php if ($currentPageId > 0):
                    $currentPageLabel = '';
                    foreach ($pages as $page) {
                        if ($page['value'] == $currentPageId) {
                            $currentPageLabel = $page['label'];
                            break;
                        }
                    }
                ?>
                <span class="badge bg-primary me-1">페이지</span>
                <?= htmlspecialchars($currentPageLabel) ?> 페이지의 행을 관리합니다.
                <?php else: ?>
                위치 기반 블록 행을 관리합니다.
                <?php endif; ?>
            </p>
        </div>
        <div class="page-title-actions">
            <?php if ($canExportKit): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openDeletedRowHistory()">
                <i class="bi bi-clock-history"></i> 삭제 이력
            </button>
            <?php endif; ?>
            <?php if ($currentPageId > 0): ?>
            <a href="/admin/block-page/edit?id=<?= $currentPageId ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> 페이지 설정
            </a>
            <?php endif; ?>
            <?php
            // 메인화면은 여러 position을 조합한 screen 킷으로, 그 외는 position 킷으로 내보낸다.
            // 페이지 블록 킷은 블록 페이지 관리에서 내보낸다(설계 5.6).
            $kitExportable = $currentPageId <= 0
                && (!empty($currentPosition) || $currentScreen === 'main');
            ?>
            <?php if ($currentPageId <= 0): ?>
                <?php if (!$canExportKit): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                        title="블록 킷 기능은 도메인 운영자 권한이 필요합니다.">
                    <i class="bi bi-box-arrow-in-down"></i> 블록 킷 가져오기
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                        title="블록 킷 기능은 도메인 운영자 권한이 필요합니다.">
                    <i class="bi bi-box-arrow-up"></i> 블록 킷 내보내기
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openKitImportModal()">
                    <i class="bi bi-box-arrow-in-down"></i> 블록 킷 가져오기
                </button>
                    <?php if (!$kitExportable): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                            title="내보낼 위치 또는 메인화면을 먼저 선택하세요.">
                        <i class="bi bi-box-arrow-up"></i> 블록 킷 내보내기
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openKitExportModal()">
                        <i class="bi bi-box-arrow-up"></i> 블록 킷 내보내기
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            <a href="/admin/block-row/create<?= $currentPageId > 0 ? '?page_id=' . $currentPageId : (!empty($currentPosition) ? '?filter_position=' . urlencode($currentPosition) : '') ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 행 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 요약 영역 -->
        <form method="get" class="mb-2">
            <div class="row align-items-center gx-2 gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/block-row">전체</a></span>
                        <span class="ov-num"><b><?= count($rows) ?></b> 개</span>
                    </span>
                </div>
                <?php if ($currentPageId <= 0): ?>
                <div class="col-auto">
                    <select name="position" class="form-select form-select-sm" onchange="onPositionFilterChange(this)">
                        <option value="">위치: 전체</option>
                        <optgroup label="화면 구성">
                            <option value="screen:main" <?= ($currentScreen ?? null) === 'main' ? 'selected' : '' ?>>
                                🖥 메인화면 구성
                            </option>
                        </optgroup>
                        <optgroup label="개별 위치">
                            <?php foreach ($positions as $key => $label): ?>
                                <?php
                                // 메인화면(index)은 "메인화면 구성"이 대체한다 — 라벨 중복을 없앤다.
                                // 맵 자체는 "출력 대상" 열의 라벨 변환에 쓰이므로 유지한다.
                                if ($key === 'index') {
                                    continue;
                                }
                                ?>
                                <option value="<?= $key ?>" <?= ($currentScreen ?? null) === null && $currentPosition === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <?php
                // 메뉴 필터는 개별 위치 필터에 종속된다 — 화면 구성 모드나 index/전체에선 숨긴다.
                $menuFilterVisible = ($currentScreen ?? null) === null
                    && !empty($currentPosition) && $currentPosition !== 'index';
                ?>
                <div class="col-auto" id="menu_filter_wrapper"<?= $menuFilterVisible ? '' : ' style="display:none"' ?>>
                    <select name="menu" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">페이지: 전체(전역)</option>
                        <?php foreach ($menuOptions as $group): ?>
                            <optgroup label="<?= htmlspecialchars($group['group']) ?>">
                                <?php foreach ($group['items'] as $item): ?>
                                    <option value="<?= htmlspecialchars($item['value'], ENT_QUOTES) ?>"
                                        <?= $currentMenu === $item['value'] ? 'selected' : '' ?>>
                                        <?= str_repeat('　', (int) ($item['depth'] ?? 0)) ?><?= htmlspecialchars($item['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </form>

        <?php if (($currentScreen ?? null) === 'main'): ?>
        <div class="alert alert-info py-2 px-3 small mb-2 d-flex align-items-center">
            <i class="bi bi-info-circle me-2"></i>
            <div>
                <strong>메인화면 구성</strong> — 현재 레이아웃 설정에서 메인화면에 조립되는 위치의 행을 위→아래 순서로 모았습니다.
                사이드바(<span class="text-muted">· 전역</span>)는 <strong>모든 페이지 공통</strong>이라, 여기서 편집하면 다른 페이지에도 함께 반영됩니다.
            </div>
        </div>
        <?php endif; ?>

        <!-- 행 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($rows)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle', 'id' => 'row-list'])
                    ->setTrAttr(fn($row) => ['data-row-id' => $row['row_id']])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 (순서 관리 중심 화면 — 페이지네이션 없음, 전량 출력) -->
            <div class="row gx-2 my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/block-row/list-delete"
                                data-callback="afterListDelete">
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success mublo-submit"
                                data-target="/admin/block-row/list-cache-refresh"
                                data-callback="afterListCacheRefresh">
                            <i class="bi bi-arrow-clockwise"></i>
                            <span class="d-none d-md-inline">선택 캐시갱신</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="updateSelectedOrders()">
                            <i class="bi bi-arrow-down-up"></i>
                            <span class="d-none d-md-inline">선택 순서변경</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="saveAllOrders()">
                            <i class="bi bi-save"></i> 순서 일괄저장
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($canExportKit): ?>
<div class="modal fade" id="deletedRowHistoryModal" tabindex="-1" aria-labelledby="deletedRowHistoryTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deletedRowHistoryTitle">삭제된 블록 행</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">직접 삭제하거나 블록 킷 교체로 사라진 행입니다. 복구하면 기존 화면에 행이 다시 추가됩니다.</p>
                <div id="deletedRowHistoryBody" class="text-muted small">불러오는 중…</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 복사/이동 모달 -->
<div class="modal fade" id="rowActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rowActionModalTitle">행 복사</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="actionRowId" value="">
                <input type="hidden" id="actionType" value="copy">

                <div class="mb-3">
                    <label class="form-label">대상 유형</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="targetType" id="targetPosition" value="position" checked>
                        <label class="btn btn-outline-primary" for="targetPosition">위치 기반</label>
                        <input type="radio" class="btn-check" name="targetType" id="targetPage" value="page">
                        <label class="btn btn-outline-primary" for="targetPage">페이지 기반</label>
                    </div>
                </div>

                <div id="positionSelectArea" class="mb-3">
                    <label class="form-label">대상 위치</label>
                    <select class="form-select" id="targetPositionValue">
                        <?php foreach ($positions as $key => $label): ?>
                            <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="pageSelectArea" class="mb-3" style="display:none;">
                    <label class="form-label">대상 페이지</label>
                    <select class="form-select" id="targetPageValue">
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= $page['value'] ?>"><?= htmlspecialchars($page['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($pages)): ?>
                    <div class="form-text text-warning">등록된 블록 페이지가 없습니다.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" id="confirmActionBtn" onclick="executeRowAction()">복사</button>
            </div>
        </div>
    </div>
</div>

<?php if ($canExportKit && $currentPageId <= 0): ?>
<!-- 블록 킷 가져오기 모달 -->
<div class="modal fade" id="kitImportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">블록 킷 가져오기</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle"></i>
                    블록 킷은 사이트에서 스크립트를 실행할 수 있습니다. 신뢰하는 배포자의 것만 적용하세요.
                </div>

                <div class="mb-3">
                    <label class="form-label">블록 킷 파일 (.json)</label>
                    <input type="file" id="kitFile" class="form-control form-control-sm" accept=".json,application/json">
                </div>

                <div class="mb-3">
                    <label class="form-label">적용 모드</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="kit_mode" id="kitModeAppend" value="append" checked>
                        <label class="form-check-label" for="kitModeAppend">
                            <strong>이어붙이기</strong>
                            <span class="text-muted small d-block">기존 행 뒤에 추가합니다.</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="kit_mode" id="kitModeReplace" value="replace">
                        <label class="form-check-label" for="kitModeReplace">
                            <strong>교체</strong>
                            <span class="text-danger small d-block">블록 킷이 지정한 위치의 기존 행을 삭제합니다. 교체 전 행은 삭제 이력에 보관됩니다.</span>
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="kitApplySiteSettings">
                        <label class="form-check-label" for="kitApplySiteSettings">
                            <span id="kitApplySiteSettingsLabel">블록 킷이 요구하는 레이아웃 설정도 적용</span>
                            <span class="text-danger small d-block">
                                <code>layout_type</code>은 사이트 전역입니다. 게시판 등 다른 화면의 레이아웃도 함께 변경됩니다.
                            </span>
                        </label>
                    </div>
                </div>

                <div id="kitPreviewResult" style="display:none;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="previewKit()">
                    <i class="bi bi-search"></i> 미리보기
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="kitApplyBtn" onclick="applyKit()" disabled>
                    적용
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canExportKit && $currentPageId <= 0 && (!empty($currentPosition) || $currentScreen === 'main')): ?>
<!-- 블록 킷 내보내기 모달 -->
<div class="modal fade" id="kitExportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/admin/block-row/export" enctype="multipart/form-data" target="_blank">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES) ?>">
                <?php if ($currentScreen === 'main'): ?>
                <input type="hidden" name="screen" value="main">
                <?php else: ?>
                <input type="hidden" name="position" value="<?= htmlspecialchars($currentPosition, ENT_QUOTES) ?>">
                <?php endif; ?>

                <div class="modal-header">
                    <h5 class="modal-title">블록 킷 내보내기 — <?= $currentScreen === 'main'
                        ? '메인화면 구성'
                        : htmlspecialchars($positions[$currentPosition] ?? $currentPosition) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">블록 킷 이름</label>
                            <input type="text" name="kit_name" class="form-control form-control-sm" placeholder="커뮤니티 메인화면 블록 킷">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">버전</label>
                            <input type="text" name="kit_version" class="form-control form-control-sm" placeholder="1.0.0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">설명</label>
                            <input type="text" name="kit_description" class="form-control form-control-sm" placeholder="게시판 최신글 중심의 커뮤니티 메인화면">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">저작자</label>
                            <input type="text" name="kit_author" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">저작자 URL</label>
                            <input type="url" name="kit_author_url" class="form-control form-control-sm" placeholder="https://example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">필요 코어 버전 <span class="text-muted small">(선택)</span></label>
                            <input type="text" name="kit_requires_core" class="form-control form-control-sm" placeholder="^1.0">
                            <div class="form-text">비워 두면 요구하지 않습니다. 낮은 코어에서는 경고만 뜨고 적용은 됩니다.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">스크린샷 <span class="text-muted small">(선택)</span></label>
                            <input type="file" name="kit_screenshot" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                            <div class="form-text">1200×900 webp로 변환해 블록 킷에 포함됩니다. SVG는 지원하지 않습니다.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">내보내기 모드</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_mode" id="kitModeDist" value="distribution" checked>
                                <label class="form-check-label" for="kitModeDist">
                                    <strong>배포용 블록 킷</strong>
                                    <span class="text-muted small d-block">콘텐츠 참조를 비웁니다. 남에게 배포할 때 씁니다.</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_mode" id="kitModeClone" value="clone">
                                <label class="form-check-label" for="kitModeClone">
                                    <strong>복제 / 백업</strong>
                                    <span class="text-muted small d-block">참조를 그대로 유지합니다. 같은 설치 안에서만 쓰세요.</span>
                                </label>
                            </div>
                        </div>

                        <?php if (!empty($kitItemCandidates)): ?>
                        <div class="col-12" id="kitItemCandidates">
                            <label class="form-label">이 블록 킷에 포함할 콘텐츠 참조 <span class="text-muted small">(기본: 포함 안 함)</span></label>
                            <?php foreach ($kitItemCandidates as $candidate): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="include_content_items_by_column[]"
                                       value="<?= (int) $candidate['column_id'] ?>"
                                       id="kitItem<?= (int) $candidate['column_id'] ?>">
                                <label class="form-check-label" for="kitItem<?= (int) $candidate['column_id'] ?>">
                                    <?= htmlspecialchars($candidate['row_title']) ?>
                                    · <?= (int) $candidate['column_index'] + 1 ?>번째 칸
                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($candidate['content_type']) ?></span>
                                    <span class="text-muted small"><?= (int) $candidate['item_count'] ?>건</span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <div class="form-text">
                                게시판 슬러그처럼 관례적인 이름은 대상 사이트에서 맞아떨어질 확률이 높습니다.
                                배너 블록은 깨진 이미지를 남기므로 목록에서 제외됩니다.
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-light border small mb-0">
                                이 위치에는 블록 킷에 담을 만한 콘텐츠 참조가 없습니다. 구조만 내보냅니다.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-download"></i> JSON 다운로드
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 미리보기 모달 -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalTitle"><i class="bi bi-eye"></i> 미리보기</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="previewLoading" class="text-center text-muted py-5">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">미리보기 생성 중...</p>
                </div>
                <div id="previewError" style="display:none;" class="p-3"></div>
                <iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" style="display:none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
                <a href="#" class="btn btn-primary" id="previewEditBtn">
                    <i class="bi bi-pencil"></i> 수정
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(event) {
    const button = event.target.closest('.js-row-preview, .js-row-cache-refresh, .js-row-copy, .js-row-move, .js-row-delete');
    if (!button) return;

    const rowId = Number(button.dataset.rowId || 0);
    const rowName = button.dataset.rowName || ('행 #' + rowId);
    if (!rowId) return;

    if (button.classList.contains('js-row-preview')) previewRow(rowId, rowName);
    else if (button.classList.contains('js-row-cache-refresh')) refreshRowCache(rowId, rowName);
    else if (button.classList.contains('js-row-copy')) openCopyModal(rowId, rowName, button.dataset.currentType || '', button.dataset.currentValue || '');
    else if (button.classList.contains('js-row-move')) openMoveModal(rowId, rowName, button.dataset.currentType || '', button.dataset.currentValue || '');
    else if (button.classList.contains('js-row-delete')) deleteRow(rowId, rowName);
});

// 프론트 미리보기 토큰 — 행 미리보기 iframe 이 프론트와 같은 캐스케이드
// (tokens.css → 프레임 스킨 변수 rebind → 도메인 브랜드색)를 재현한다.
// 소비자: 바로 아래 front-preview-tokens.js (MubloFrontPreviewCss)
window.MubloFrontPreviewTokens = <?= json_encode($frontPreviewTokens ?? [], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= asset('/assets/js/admin/front-preview-tokens.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-preview-iframe.js') ?>"></script>
<script>
// 위치 필터 변경 시: 메인화면(index)이나 전체로 바꾸면 메뉴 필터는 무의미하므로 비운다.
// 그래야 이전 메뉴 값이 남아 엉뚱한 스코프로 조회되지 않는다.
function onPositionFilterChange(select) {
    // 전체·메인화면(index)·화면 구성 모드에선 메뉴 필터가 무의미하므로 값을 비운다.
    // 남겨 두면 이전 스코프가 엉뚱하게 따라붙는다.
    const v = select.value;
    if (v === '' || v === 'index' || v.indexOf('screen:') === 0) {
        const menu = select.form.elements['menu'];
        if (menu) {
            menu.value = '';
        }
    }
    select.form.submit();
}

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

// 사용 여부 토글
function toggleRowActive(rowId, el) {
    MubloRequest.requestJson('/admin/block-row/toggle-active', {
        row_id: rowId
    }).then(response => {
        MubloRequest.showToast(response.message, 'success');
    }).catch(err => {
        el.checked = !el.checked;
        console.error(err);
    });
}

// 행 삭제 (단건)
function deleteRow(rowId, rowName) {
    MubloRequest.showConfirm(`'${rowName}'을(를) 삭제하시겠습니까? 삭제 이력에서 복구할 수 있습니다.`, function() {
        MubloRequest.requestJson('/admin/block-row/delete', {
            row_id: rowId
        }).then(response => {
            MubloRequest.showToast(response.message || '삭제되었습니다.', 'success');
            location.reload();
        }).catch(err => {
            console.error(err);
        });
    }, { type: 'warning' });
}

async function openDeletedRowHistory() {
    const body = document.getElementById('deletedRowHistoryBody');
    body.innerHTML = '불러오는 중…';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deletedRowHistoryModal')).show();

    try {
        const response = await fetch('/admin/block-row/deleted-revisions');
        const json = await response.json();
        const items = json.data?.items || [];
        if (!json.success || items.length === 0) {
            body.innerHTML = '<div class="text-muted">복구할 삭제 이력이 없습니다.</div>';
            return;
        }

        body.innerHTML = '<div class="list-group">' + items.map(item => `
            <div class="list-group-item d-flex align-items-center gap-3">
                <div class="flex-grow-1">
                    <strong>${escapeHtml(item.admin_title || '이름 없는 행')}</strong>
                    <div class="text-muted small">${escapeHtml(item.source_label || '')} · ${escapeHtml(item.created_at || '')}</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" data-restore-revision="${Number(item.revision_id)}">복구</button>
            </div>`).join('') + '</div>';

        body.querySelectorAll('[data-restore-revision]').forEach(button => {
            button.addEventListener('click', async () => {
                if (!confirm('이 행을 기존 화면에 다시 추가할까요?')) return;
                button.disabled = true;
                try {
                    const result = await MubloRequest.requestJson('/admin/block-row/restore-revision', {
                        revision_id: Number(button.dataset.restoreRevision)
                    });
                    MubloRequest.showToast(result.message || '복구했습니다.', 'success');
                    location.reload();
                } catch (error) {
                    button.disabled = false;
                }
            });
        });
    } catch (error) {
        body.innerHTML = '<div class="text-danger">삭제 이력을 불러오지 못했습니다.</div>';
    }
}

// 행 캐시 갱신 (단건)
function refreshRowCache(rowId, rowName) {
    MubloRequest.requestJson('/admin/block-row/cache-refresh', {
        row_id: rowId
    }).then(response => {
        MubloRequest.showToast(response.message || '캐시를 갱신했습니다.', 'success');
    }).catch(err => {
        console.error(err);
    });
}

// 일괄 캐시 갱신 후 콜백 (목록 변화 없음 → reload 불필요)
function afterListCacheRefresh(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '캐시를 갱신했습니다.', 'success');
    } else {
        MubloRequest.showAlert(data.message || '캐시 갱신에 실패했습니다.', 'error');
    }
}

// 일괄 삭제 후 콜백
function afterListDelete(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '삭제에 실패했습니다.', 'error');
    }
}

// 드래그 앤 드롭 정렬
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('row-list');
    const tbody = list ? list.querySelector('tbody') : null;

    if (tbody && typeof Sortable !== 'undefined') {
        new Sortable(tbody, {
            handle: '.handle',
            animation: 150,
            onEnd: function(evt) {
                const rowIds = [];
                tbody.querySelectorAll('tr[data-row-id]').forEach(row => {
                    rowIds.push(parseInt(row.dataset.rowId));
                });

                MubloRequest.requestJson('/admin/block-row/order-update', {
                    row_ids: rowIds
                }).then(response => {
                    if (response.result === 'success') {
                        // 드래그 성공 시 순서 입력 필드 값 업데이트
                        tbody.querySelectorAll('tr[data-row-id]').forEach((row, index) => {
                            const rowId = row.dataset.rowId;
                            const input = row.querySelector(`input[name="sort_order[${rowId}]"]`);
                            if (input) {
                                input.value = index;
                            }
                        });
                    } else {
                        MubloRequest.showAlert(response.message || '정렬 저장에 실패했습니다.', 'error');
                        location.reload();
                    }
                }).catch(err => {
                    console.error(err);
                    location.reload();
                });
            }
        });
    }

    // 대상 유형 라디오 변경 시
    document.querySelectorAll('input[name="targetType"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const positionArea = document.getElementById('positionSelectArea');
            const pageArea = document.getElementById('pageSelectArea');
            if (this.value === 'position') {
                positionArea.style.display = 'block';
                pageArea.style.display = 'none';
            } else {
                positionArea.style.display = 'none';
                pageArea.style.display = 'block';
            }
        });
    });
});

// 현재 위치를 모달 폼에 기본 선택 (그대로 실행 시 같은 위치로 복사/이동)
function presetCurrentLocation(curType, curValue) {
    var positionArea = document.getElementById('positionSelectArea');
    var pageArea = document.getElementById('pageSelectArea');

    if (curType === 'page') {
        document.getElementById('targetPage').checked = true;
        positionArea.style.display = 'none';
        pageArea.style.display = 'block';
        if (curValue) document.getElementById('targetPageValue').value = curValue;
    } else {
        document.getElementById('targetPosition').checked = true;
        positionArea.style.display = 'block';
        pageArea.style.display = 'none';
        if (curValue) document.getElementById('targetPositionValue').value = curValue;
    }
}

/**
 * 블록 킷 내보내기 모달 열기
 *
 * 폼은 target="_blank" 로 제출되어 JSON 다운로드만 발생하고 목록 화면은 그대로 남는다.
 */
function openKitExportModal() {
    const el = document.getElementById('kitExportModal');
    if (!el) return;
    new bootstrap.Modal(el).show();
}

// ========================================
// 블록 킷 가져오기
// ========================================

let previewedKitTargetKind = null;

function openKitImportModal() {
    const el = document.getElementById('kitImportModal');
    if (!el) return;

    document.getElementById('kitFile').value = '';
    document.getElementById('kitPreviewResult').style.display = 'none';
    document.getElementById('kitApplyBtn').disabled = true;
    const settings = document.getElementById('kitApplySiteSettings');
    settings.checked = false;
    settings.disabled = false;
    document.getElementById('kitApplySiteSettingsLabel').textContent = '블록 킷이 요구하는 레이아웃 설정도 적용';
    previewedKitTargetKind = null;

    new bootstrap.Modal(el).show();
}

function buildKitFormData() {
    const input = document.getElementById('kitFile');
    if (!input.files.length) {
        alert('블록 킷 파일을 선택하세요.');
        return null;
    }

    const formData = new FormData();
    formData.append('kit_file', input.files[0]);

    // CsrfMiddleware 는 모든 POST 를 검증한다(excludePaths 비어 있음). 없으면 419.
    formData.append('_token', <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>);

    return formData;
}

/**
 * dry-run 미리보기. 적용은 이 단계를 반드시 거친다(설계 6.1).
 */
async function previewKit() {
    const formData = buildKitFormData();
    if (!formData) return;

    const box = document.getElementById('kitPreviewResult');
    box.style.display = 'block';
    box.innerHTML = '<div class="text-muted small">검증 중...</div>';
    document.getElementById('kitApplyBtn').disabled = true;

    try {
        const res = await fetch('/admin/block-row/kit-preview', { method: 'POST', body: formData });
        const json = await res.json();

        if (!json.success) {
            box.innerHTML = '<div class="alert alert-danger small mb-0">' + escapeHtml(json.message || '검증에 실패했습니다.') + '</div>';
            return;
        }

        const data = json.data;
        previewedKitTargetKind = data.summary?.target_kind || null;
        const settings = document.getElementById('kitApplySiteSettings');
        if (data.summary?.site_settings_required) {
            settings.checked = true;
            settings.disabled = true;
            document.getElementById('kitApplySiteSettingsLabel').textContent = '메인화면 레이아웃 설정도 함께 적용됩니다';
        } else {
            settings.disabled = false;
            document.getElementById('kitApplySiteSettingsLabel').textContent = '블록 킷이 요구하는 레이아웃 설정도 적용';
        }
        box.innerHTML = renderKitPreview(data);
        document.getElementById('kitApplyBtn').disabled = !data.ok;
    } catch (e) {
        box.innerHTML = '<div class="alert alert-danger small mb-0">요청에 실패했습니다.</div>';
    }
}

function renderKitPreview(data) {
    const s = data.summary || {};
    let html = '';

    if (!data.ok) {
        html += '<div class="alert alert-danger small"><strong>적용할 수 없습니다.</strong><ul class="mb-0 mt-1">';
        (data.errors || []).forEach(e => { html += '<li>' + escapeHtml(e) + '</li>'; });
        html += '</ul></div>';
    }

    html += '<div class="border rounded p-2 small">';
    html += '<div><strong>' + (s.row_count || 0) + '</strong>개 행 · <strong>' + (s.column_count || 0) + '</strong>개 칸이 생성됩니다.</div>';

    if (s.target_kind === 'screen' && s.target_screen === 'main') {
        html += '<div class="text-primary mt-1"><i class="bi bi-layout-text-window-reverse"></i> 메인화면 복합 킷 — 슬롯 구성과 레이아웃 설정을 함께 적용합니다.</div>';
    }

    if (s.contains_script) {
        html += '<div class="text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> 이 블록 킷은 스크립트를 포함합니다.</div>';
    } else {
        html += '<div class="text-success mt-1"><i class="bi bi-shield-check"></i> 스크립트 없음</div>';
    }

    if ((s.missing_block_types || []).length) {
        html += '<div class="text-warning mt-1">미설치 블록 타입: ' + s.missing_block_types.map(escapeHtml).join(', ') + '</div>';
    }

    if ((s.needs_setup || []).length) {
        html += '<div class="mt-2"><strong>적용 후 설정이 필요한 칸 ' + s.needs_setup.length + '개</strong><ul class="mb-0 mt-1">';
        s.needs_setup.forEach(item => {
            const label = item.content_type ? escapeHtml(item.content_type) : '블록';
            // provider 는 블록 킷이 실어 온 문자열이다. 서버가 형식을 걸렀지만 여기서도 이스케이프한다.
            const REASONS = {
                image_missing: '이미지를 지정하세요',
                items_empty: '표시할 콘텐츠를 선택하세요',
            };
            const reason = item.reason === 'extension_missing'
                ? (item.provider ? escapeHtml(item.provider) + '를 설치하세요' : '확장 설치가 필요합니다')
                : (REASONS[item.reason] || '표시할 콘텐츠를 선택하세요');
            // 블록 킷은 제3자 파일이다. 서버가 정수로 강제하지만 여기서도 숫자로 취급한다.
            const rowNo = (parseInt(item.row_index, 10) || 0) + 1;
            const colNo = (parseInt(item.column_index, 10) || 0) + 1;
            html += '<li>' + rowNo + '행 ' + colNo + '칸 · '
                + '<span class="badge bg-light text-dark">' + label + '</span> ' + reason;
            if (item.kit_hint) {
                html += '<div class="text-muted small">' + escapeHtml(item.kit_hint) + '</div>';
            }
            html += '</li>';
        });
        html += '</ul></div>';
    }
    html += '</div>';

    if ((data.warnings || []).length) {
        html += '<div class="alert alert-warning small mt-2 mb-0"><ul class="mb-0">';
        data.warnings.forEach(w => { html += '<li>' + escapeHtml(w) + '</li>'; });
        html += '</ul><div class="mt-1 text-muted">해당 칸은 구조만 유지되고, 확장을 설치하면 나타납니다.</div></div>';
    }

    return html;
}

async function applyKit() {
    const formData = buildKitFormData();
    if (!formData) return;

    const mode = document.querySelector('input[name="kit_mode"]:checked').value;
    const replaceMessage = previewedKitTargetKind === 'screen'
        ? '교체 모드는 메인화면 대상 슬롯의 기존 전역 행을 삭제합니다. 공용 영역은 다른 화면에도 영향을 줍니다. 계속할까요?'
        : '교체 모드는 해당 위치의 기존 행을 삭제합니다. 교체 전 행은 삭제 이력에 보관됩니다. 계속할까요?';
    if (mode === 'replace' && !confirm(replaceMessage)) {
        return;
    }
    formData.append('mode', mode);

    if (document.getElementById('kitApplySiteSettings').checked) {
        formData.append('apply_site_settings', '1');
    }

    const btn = document.getElementById('kitApplyBtn');
    btn.disabled = true;

    try {
        const res = await fetch('/admin/block-row/kit-apply', { method: 'POST', body: formData });
        const json = await res.json();

        if (!json.success) {
            alert(json.message || '적용에 실패했습니다.');
            btn.disabled = false;
            return;
        }

        // 적용 완료 후 "설정이 필요한 칸" 안내 (설계 8.3)
        const needsSetup = (json.data && json.data.summary && json.data.summary.needs_setup) || [];
        let message = '블록 킷이 적용되었습니다.';
        if (needsSetup.length) {
            message += '\n\n다음 ' + needsSetup.length + '개 칸에 콘텐츠를 설정하세요:\n'
                + needsSetup.map(i =>
                    '· ' + (i.row_index + 1) + '행 ' + (i.column_index + 1) + '칸 (' + (i.content_type || '블록') + ')'
                    + (i.kit_hint ? ' — ' + i.kit_hint : '')
                ).join('\n');
        }

        alert(message);
        location.reload();
    } catch (e) {
        alert('요청에 실패했습니다.');
        btn.disabled = false;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value);
    return div.innerHTML;
}

// 복사 모달 열기
function openCopyModal(rowId, rowName, curType, curValue) {
    document.getElementById('rowActionModalTitle').textContent = `'${rowName}' 복사`;
    document.getElementById('actionRowId').value = rowId;
    document.getElementById('actionType').value = 'copy';
    document.getElementById('confirmActionBtn').textContent = '복사';
    document.getElementById('confirmActionBtn').className = 'btn btn-primary';

    // 현재 위치를 기본 선택
    presetCurrentLocation(curType, curValue);

    const modal = new bootstrap.Modal(document.getElementById('rowActionModal'));
    modal.show();
}

// 이동 모달 열기
function openMoveModal(rowId, rowName, curType, curValue) {
    document.getElementById('rowActionModalTitle').textContent = `'${rowName}' 이동`;
    document.getElementById('actionRowId').value = rowId;
    document.getElementById('actionType').value = 'move';
    document.getElementById('confirmActionBtn').textContent = '이동';
    document.getElementById('confirmActionBtn').className = 'btn btn-warning';

    // 현재 위치를 기본 선택
    presetCurrentLocation(curType, curValue);

    const modal = new bootstrap.Modal(document.getElementById('rowActionModal'));
    modal.show();
}

// 복사/이동 실행
function executeRowAction() {
    const rowId = parseInt(document.getElementById('actionRowId').value);
    const actionType = document.getElementById('actionType').value;
    const targetType = document.querySelector('input[name="targetType"]:checked').value;

    let data = { row_id: rowId };

    if (targetType === 'position') {
        data.position = document.getElementById('targetPositionValue').value;
    } else {
        const pageValue = document.getElementById('targetPageValue').value;
        if (!pageValue) {
            MubloRequest.showAlert('대상 페이지를 선택해주세요.', 'warning');
            return;
        }
        data.page_id = parseInt(pageValue);
    }

    const url = actionType === 'copy' ? '/admin/block-row/copy' : '/admin/block-row/move';
    const actionLabel = actionType === 'copy' ? '복사' : '이동';

    MubloRequest.requestJson(url, data).then(response => {
        bootstrap.Modal.getInstance(document.getElementById('rowActionModal')).hide();
        MubloRequest.showToast(response.message || `${actionLabel}되었습니다.`, 'success');
        location.reload();
    }).catch(err => {
        console.error(err);
    });
}

// 선택된 행의 순서 변경
function updateSelectedOrders() {
    const checkedBoxes = document.querySelectorAll('input[name="chk[]"]:checked');

    if (checkedBoxes.length === 0) {
        MubloRequest.showAlert('순서를 변경할 항목을 선택해주세요.', 'warning');
        return;
    }

    const orders = {};
    let hasChange = false;

    checkedBoxes.forEach(checkbox => {
        const rowId = checkbox.value;
        const input = document.querySelector(`input[name="sort_order[${rowId}]"]`);
        if (input) {
            orders[rowId] = parseInt(input.value) || 0;
            hasChange = true;
        }
    });

    if (!hasChange) {
        MubloRequest.showAlert('변경할 순서 정보가 없습니다.', 'warning');
        return;
    }

    MubloRequest.requestJson('/admin/block-row/order-set', {
        orders: orders
    }).then(response => {
        MubloRequest.showToast(response.message || '순서가 변경되었습니다.', 'success');
        location.reload();
    }).catch(err => {
        console.error(err);
    });
}

// 전체 순서 일괄 저장
function saveAllOrders() {
    const inputs = document.querySelectorAll('.sort-order-input');

    if (inputs.length === 0) {
        MubloRequest.showAlert('저장할 항목이 없습니다.', 'warning');
        return;
    }

    const orders = {};
    inputs.forEach(input => {
        const match = input.name.match(/sort_order\[(\d+)\]/);
        if (match) {
            orders[match[1]] = parseInt(input.value) || 0;
        }
    });

    MubloRequest.requestJson('/admin/block-row/order-set', {
        orders: orders
    }).then(response => {
        MubloRequest.showToast(response.message || '순서가 저장되었습니다.', 'success');
        location.reload();
    }).catch(err => {
        console.error(err);
    });
}

// 행 미리보기
function previewRow(rowId, rowName) {
    var modal = new bootstrap.Modal(document.getElementById('previewModal'));
    var title = document.getElementById('previewModalTitle');
    var loading = document.getElementById('previewLoading');
    var errorEl = document.getElementById('previewError');
    var frame = document.getElementById('previewFrame');
    var editBtn = document.getElementById('previewEditBtn');

    title.innerHTML = '<i class="bi bi-eye"></i> ' + rowName + ' 미리보기';
    editBtn.href = '/admin/block-row/edit?id=' + rowId + <?= json_encode($filterSuffix) ?>;
    loading.style.display = '';
    errorEl.style.display = 'none';
    frame.style.display = 'none';

    modal.show();

    MubloRequest.requestQuery('/admin/block-row/preview-row', {
        row_id: rowId
    }).then(function(response) {
        if (response.data && response.data.html) {
            renderBlockPreviewIframe(response.data.html, response.data.skinCss || [], response.data.skinJs || [], { autoHeight: true });
        } else {
            loading.style.display = 'none';
            errorEl.style.display = '';
            errorEl.innerHTML = '<div class="alert alert-warning mb-0">미리보기 데이터가 없습니다.</div>';
        }
    }).catch(function() {
        loading.style.display = 'none';
        errorEl.style.display = '';
        errorEl.innerHTML = '<div class="alert alert-danger mb-0">미리보기를 불러올 수 없습니다.</div>';
    });
}

</script>
