<?php
/**
 * Admin Blockrow - Form
 *
 * 블록 행 생성/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array $rowData 정규화된 행 데이터
 * @var int $rowId 행 ID (0이면 새 행)
 * @var string $position 출력 위치
 * @var int $pageId 페이지 ID (0이면 위치 기반)
 * @var int $columnCount 칸 수
 * @var bool $isPageBased 페이지 기반 여부
 * @var string $currentPageLabel 현재 페이지 라벨
 * @var array $bgConfig 배경 설정
 * @var array $columns 칸 데이터
 * @var array $positions 위치 목록
 * @var array $pages 페이지 목록
 * @var array $contentTypes 콘텐츠 타입 목록
 * @var bool $canEditInclude Include(서버 실행) 편집 가능 여부 — raw JS 는 편집 신뢰 전원 허용
 * @var array $contentTypeGroups 종류별 콘텐츠 타입
 * @var array $menuOptions 메뉴 옵션 (position_menu 셀렉트용)
 * @var string $filterPosition 목록 복귀 시 유지할 위치 필터
 */
// activeCode는 전역 스크립트(Foot.php)가 링크/폼에 자동 주입하므로 여기서 다루지 않음.
// 위치 필터만 목록 복귀용으로 별도 유지한다.
$filterPosition = $filterPosition ?? '';
$listQuery = $filterPosition !== '' ? '?position=' . urlencode($filterPosition) : '';
?>
<?php /* HTML 칸의 비주얼 에디터(BlockHtmlEditor)는 도메인 에디터 설정과
   무관하게 항상 뜨므로, editor_css()(설정이 textarea 면 빈 값) 대신
   스타일을 직접 로드한다. BlockHtmlEditorBase.js 무조건 로드와 짝. */ ?>
<link rel="stylesheet" href="<?= asset('/assets/lib/editor/mublo-editor/MubloEditor.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/admin/blockrow-form.css') ?>">
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '블록 행 설정') ?></h3>
            <p>
                블록 행의 레이아웃과 칸 설정을 관리합니다.
                <?php if ($isEdit): ?>
                    <span class="badge bg-secondary">ID: <?= $rowId ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="page-title-actions">
            <?php if ($isPageBased): ?>
            <a href="/admin/block-row?page_id=<?= $pageId ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 행 목록
            </a>
            <a href="/admin/block-page/edit?id=<?= $pageId ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark"></i> 페이지 설정
            </a>
            <?php else: ?>
            <a href="/admin/block-row<?= $listQuery ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 메인 폼 -->
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="formData[row_id]" value="<?= $rowId ?>">
        <input type="hidden" name="formData[revision_no]" value="<?= (int) ($rowData['revision_no'] ?? 1) ?>">
        <input type="hidden" name="filter_position" value="<?= htmlspecialchars($filterPosition, ENT_QUOTES, 'UTF-8') ?>">

        <div class="page-block">
            <!-- 기본 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-blue"></i>
                    <span>기본 정보</span>
                    <?php if ($isPageBased): ?>
                    <span class="badge bg-primary ms-auto">페이지: <?= htmlspecialchars($currentPageLabel) ?></span>
                    <?php else: ?>
                    <span class="badge bg-secondary ms-auto">위치 기반</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">관리용 제목</label>
                            <input type="text" name="formData[admin_title]" class="form-control"
                                   value="<?= htmlspecialchars($rowData['admin_title'] ?? '') ?>"
                                   placeholder="관리자에서 식별할 제목">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">섹션 ID</label>
                            <?php $defaultSectionId = $rowData['section_id'] ?? ('section-' . bin2hex(random_bytes(4))); ?>
                            <input type="text" name="formData[section_id]" class="form-control"
                                   value="<?= htmlspecialchars($defaultSectionId) ?>"
                                   placeholder="자동 생성됨 (수정 가능)">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">사용 여부</label>
                            <select name="formData[is_active]" class="form-select">
                                <option value="1" <?= ($rowData['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>사용</option>
                                <option value="0" <?= ($rowData['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>미사용</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($isPageBased): ?>
            <!-- 페이지 기반: page_id 고정 -->
            <input type="hidden" name="formData[page_id]" value="<?= $pageId ?>">
            <input type="hidden" name="formData[position]" value="">
            <?php else: ?>
            <!-- 위치 기반: position 선택 -->
            <input type="hidden" name="formData[page_id]" value="0">
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-geo-alt text-pastel-blue"></i>
                    <span>출력 위치</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">출력 위치 <span class="text-danger">*</span></label>
                            <select name="formData[position]" id="position_select" class="form-select">
                                <?php foreach ($positions as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $position === $key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="position_menu_wrapper"<?php if ($position === 'index'): ?> style="display:none"<?php endif; ?>>
                            <label class="form-label">특정 메뉴에서만 출력</label>
                            <select name="formData[position_menu]" id="position_menu_select" class="form-select">
                                <option value="">전체 (메뉴 제한 없음)</option>
                                <option value="__index__" <?= ($rowData['position_menu'] ?? '') === '__index__' ? 'selected' : '' ?>>메인화면 전용 (메인에서만 출력)</option>
                                <?php foreach ($menuOptions ?? [] as $group): ?>
                                <optgroup label="<?= htmlspecialchars($group['group']) ?>">
                                    <?php foreach ($group['items'] as $menuItem): ?>
                                    <option value="<?= htmlspecialchars($menuItem['value']) ?>"
                                            <?= ($rowData['position_menu'] ?? '') === $menuItem['value'] ? 'selected' : '' ?>>
                                        <?= str_repeat('&nbsp;&nbsp;', $menuItem['depth']) ?><?= htmlspecialchars($menuItem['label']) ?>
                                        (<?= htmlspecialchars($menuItem['value']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- topbar "보지 않기" (헤더 위 최상단 바 전용) -->
            <?php $dismissible = (int) ($rowData['dismissible'] ?? 0); $dismissHours = (int) ($rowData['dismiss_hours'] ?? 24); ?>
            <div class="card mb-4" id="topbar_dismiss_card"<?php if ($position !== 'topbar'): ?> style="display:none"<?php endif; ?>>
                <div class="card-hero">
                    <i class="bi bi-eye-slash text-pastel-orange"></i>
                    <span>보지 않기</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <!-- 미체크 시 키 미전송 → FormHelper.normalizeFormData 가 bool 스키마 필드를 0 으로 보정 -->
                                <input type="checkbox" class="form-check-input" role="switch"
                                       id="dismissible_check" name="formData[dismissible]" value="1"
                                       <?= $dismissible ? 'checked' : '' ?>>
                                <label class="form-check-label" for="dismissible_check">
                                    "보지 않기" 버튼 표시 <span class="text-muted">(방문자가 일정 기간 숨김)</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">숨김 기간</label>
                            <select name="formData[dismiss_hours]" id="dismiss_hours_select" class="form-select">
                                <?php foreach ([24 => '1일', 72 => '3일', 168 => '7일', 336 => '14일', 720 => '30일'] as $h => $lb): ?>
                                <option value="<?= $h ?>" <?= $dismissHours === $h ? 'selected' : '' ?>><?= $lb ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 레이아웃 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-layout-text-window text-pastel-green"></i>
                    <span>레이아웃 설정</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">넓이 타입</label>
                            <select name="formData[width_type]" id="width_type_select" class="form-select">
                                <option value="0" <?= ($rowData['width_type'] ?? 1) == 0 ? 'selected' : '' ?>>와이드 (전체)</option>
                                <option value="1" <?= ($rowData['width_type'] ?? 1) == 1 ? 'selected' : '' ?>>최대넓이</option>
                            </select>
                            <div class="form-text" id="width_type_hint" style="display:none">이 출력 위치는 컨테이너 내부이므로 최대넓이만 가능합니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">칸 수</label>
                            <select name="formData[column_count]" id="column_count" class="form-select">
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <option value="<?= $i ?>" <?= $columnCount == $i ? 'selected' : '' ?>><?= $i ?>칸</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">칸 간격</label>
                            <div class="input-group">
                                <input type="number" name="formData[column_margin]" class="form-control"
                                       value="<?= $rowData['column_margin'] ?? 0 ?>" min="0">
                                <span class="input-group-text">px</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 상세 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-sliders text-pastel-purple"></i>
                    <span>상세 설정</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- 여백 -->
                        <div class="col-12 col-lg-6">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">PC 외부 여백</label>
                                    <input type="text" name="formData[pc_margin]" class="form-control"
                                           value="<?= htmlspecialchars($rowData['pc_margin'] ?? '') ?>"
                                           placeholder="40px 0 0 0">
                                    <div class="form-text">마진 · 위, 오른쪽, 아래, 왼쪽 순서</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Mobile 외부 여백</label>
                                    <input type="text" name="formData[mobile_margin]" class="form-control"
                                           value="<?= htmlspecialchars($rowData['mobile_margin'] ?? '') ?>"
                                           placeholder="20px 0 0 0">
                                    <div class="form-text">마진 · 위, 오른쪽, 아래, 왼쪽 순서</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">PC 내부 여백</label>
                                    <input type="text" name="formData[pc_padding]" class="form-control"
                                           value="<?= htmlspecialchars($rowData['pc_padding'] ?? '') ?>"
                                           placeholder="25px 10px 20px 25px">
                                    <div class="form-text">패딩 · 위, 오른쪽, 아래, 왼쪽 순서</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Mobile 내부 여백</label>
                                    <input type="text" name="formData[mobile_padding]" class="form-control"
                                           value="<?= htmlspecialchars($rowData['mobile_padding'] ?? '') ?>"
                                           placeholder="15px 10px 15px 10px">
                                    <div class="form-text">패딩 · 위, 오른쪽, 아래, 왼쪽 순서</div>
                                </div>
                            </div>
                        </div>

                        <!-- 배경 설정 -->
                        <div class="col-12 col-lg-6">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">배경 색상</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="color" class="form-control form-control-color p-0"
                                               id="row_bg_color_picker"
                                               value="<?= htmlspecialchars(($bgConfig['color'] ?? '') ?: '#ffffff') ?>"
                                               title="색상 선택">
                                        <input type="text" name="formData[bg_color]" id="row_bg_color" class="form-control"
                                               value="<?= htmlspecialchars($bgConfig['color'] ?? '') ?>"
                                               placeholder="#ffffff">
                                    </div>
                                    <div class="form-text">비워두면 기본 배경색 적용</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">배경 이미지</label>
                                    <input type="hidden" name="formData[bg_image_old]" value="<?= htmlspecialchars($bgConfig['image'] ?? '') ?>">
                                    <input type="file" name="bg_image" id="row_bg_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                    <?php if (!empty($bgConfig['image'])): ?>
                                    <div class="mt-2">
                                        <img src="<?= htmlspecialchars($bgConfig['image']) ?>" alt="배경 이미지"
                                             class="img-fluid rounded border blockrow-bg-preview-img">
                                    </div>
                                    <div class="form-check mt-1">
                                        <input type="checkbox" class="form-check-input" name="formData[bg_image_del]" value="1" id="bg_image_del">
                                        <label class="form-check-label" for="bg_image_del">기존 이미지 삭제</label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <!-- 배경 이미지 옵션 -->
                                <div class="col-12<?= empty($bgConfig['image']) ? ' d-none' : '' ?>" id="bg_image_options">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">크기 (size)</label>
                                            <select name="formData[bg_size]" class="form-select">
                                                <option value="cover"<?= ($bgConfig['size'] ?? 'cover') === 'cover' ? ' selected' : '' ?>>cover (채우기)</option>
                                                <option value="contain"<?= ($bgConfig['size'] ?? '') === 'contain' ? ' selected' : '' ?>>contain (맞추기)</option>
                                                <option value="auto"<?= ($bgConfig['size'] ?? '') === 'auto' ? ' selected' : '' ?>>auto (원본)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">위치 (position)</label>
                                            <select name="formData[bg_position]" class="form-select">
                                                <option value="center center"<?= ($bgConfig['position'] ?? 'center center') === 'center center' ? ' selected' : '' ?>>가운데</option>
                                                <option value="top center"<?= ($bgConfig['position'] ?? '') === 'top center' ? ' selected' : '' ?>>상단</option>
                                                <option value="bottom center"<?= ($bgConfig['position'] ?? '') === 'bottom center' ? ' selected' : '' ?>>하단</option>
                                                <option value="left center"<?= ($bgConfig['position'] ?? '') === 'left center' ? ' selected' : '' ?>>좌측</option>
                                                <option value="right center"<?= ($bgConfig['position'] ?? '') === 'right center' ? ' selected' : '' ?>>우측</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">반복 (repeat)</label>
                                            <select name="formData[bg_repeat]" class="form-select">
                                                <option value="no-repeat"<?= ($bgConfig['repeat'] ?? 'no-repeat') === 'no-repeat' ? ' selected' : '' ?>>반복 없음</option>
                                                <option value="repeat"<?= ($bgConfig['repeat'] ?? '') === 'repeat' ? ' selected' : '' ?>>전체 반복</option>
                                                <option value="repeat-x"<?= ($bgConfig['repeat'] ?? '') === 'repeat-x' ? ' selected' : '' ?>>가로 반복</option>
                                                <option value="repeat-y"<?= ($bgConfig['repeat'] ?? '') === 'repeat-y' ? ' selected' : '' ?>>세로 반복</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">스크롤</label>
                                            <select name="formData[bg_attachment]" class="form-select">
                                                <option value="scroll"<?= ($bgConfig['attachment'] ?? 'scroll') === 'scroll' ? ' selected' : '' ?>>스크롤</option>
                                                <option value="fixed"<?= ($bgConfig['attachment'] ?? '') === 'fixed' ? ' selected' : '' ?>>고정 (패럴랙스)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 칸 구성 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-grid-3x2 text-pastel-sky"></i>
                    <span>칸 구성</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        각 칸의 [설정] 버튼을 클릭하여 콘텐츠와 스타일을 설정하세요.
                    </p>

                    <!-- 칸 프리뷰 -->
                    <?php $columnMargin = (int)($rowData['column_margin'] ?? 0); ?>
                    <div id="columns-preview" class="d-flex flex-wrap mb-3" style="gap: <?= $columnMargin ?>px;">
                        <?php for ($i = 0; $i < $columnCount; $i++):
                            $col = $columns[$i] ?? [];
                            $contentType = $col['content_type'] ?? '';
                            $colWidth = $col['width'] ?? '';
                            $contentLabel = '';
                            foreach ($contentTypes as $ct) {
                                if ($ct['value'] === $contentType) {
                                    $contentLabel = $ct['label'];
                                    break;
                                }
                            }
                            if ($colWidth) {
                                $gapTotal = $columnMargin * ($columnCount - 1);
                                $cardStyle = "flex: 0 0 calc({$colWidth} - {$gapTotal}px / {$columnCount}); min-width: 150px;";
                            } else {
                                $cardStyle = 'flex: 1; min-width: 200px;';
                            }
                        ?>
                        <div class="column-preview-item card" style="<?= $cardStyle ?>" data-index="<?= $i ?>">
                            <div class="card-body text-center">
                                <h6 class="card-title"><?= $i + 1 ?>번째 칸<?php if ($colWidth): ?><span class="column-width-badge badge bg-info ms-1"><?= htmlspecialchars($colWidth) ?></span><?php endif; ?></h6>
                                <p class="card-text">
                                    <?php if ($contentType): ?>
                                        <span class="column-type-badge badge bg-primary"><?= htmlspecialchars($contentLabel) ?></span>
                                    <?php else: ?>
                                        <span class="column-type-badge badge bg-secondary">미설정</span>
                                    <?php endif; ?>
                                </p>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="openColumnModal(<?= $i ?>)">
                                    <?= $contentType ? '수정' : '설정' ?>
                                </button>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <!-- 칸 데이터 (hidden) -->
                    <!-- 삭제된 필드: content_count, content_style, style_config, aos_effect (content_config로 통합) -->
                    <div id="columns-data">
                        <?php
                        // 추가 화면($columns=[])에서도 칸 수만큼 히든 인풋을 그린다.
                        // 안 그리면 JS 수집기가 is_active 를 '' 로 보내고, 서버 정규화가
                        // 이를 0(비활성)으로 굳혀 렌더러가 칸을 통째로 건너뛴다(미리보기 빈 결과).
                        $columnsList = array_values($columns);
                        $hiddenColumnCount = max((int) $columnCount, count($columnsList));
                        ?>
                        <?php for ($i = 0; $i < $hiddenColumnCount; $i++): $col = $columnsList[$i] ?? []; ?>
                        <?php if (!empty($col['column_id'])): ?>
                        <input type="hidden" name="columns[<?= $i ?>][column_id]" value="<?= (int) $col['column_id'] ?>">
                        <?php endif; ?>
                        <input type="hidden" name="columns[<?= $i ?>][width]" value="<?= htmlspecialchars($col['width'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][pc_padding]" value="<?= htmlspecialchars($col['pc_padding'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][mobile_padding]" value="<?= htmlspecialchars($col['mobile_padding'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][content_type]" value="<?= htmlspecialchars($col['content_type'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][content_kind]" value="<?= htmlspecialchars($col['content_kind'] ?? 'CORE') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][content_skin]" value="<?= htmlspecialchars($col['content_skin'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][background_config]" value="<?= htmlspecialchars(json_encode($col['background_config'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][border_config]" value="<?= htmlspecialchars(json_encode($col['border_config'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][title_config]" value="<?= htmlspecialchars(json_encode($col['title_config'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][content_config]" value="<?= htmlspecialchars(json_encode($col['content_config'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][content_items]" value="<?= htmlspecialchars(json_encode($col['content_items'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][is_active]" value="<?= (int) ($col['is_active'] ?? 1) ?>">
                        <?php if (($col['content_mode'] ?? 'single') === 'stack'): ?>
                        <input type="hidden" name="columns[<?= $i ?>][content_mode]" value="stack">
                        <input type="hidden" name="columns[<?= $i ?>][pc_content_gap]" value="<?= (int) ($col['pc_content_gap'] ?? 0) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][mobile_content_gap]" value="<?= (int) ($col['mobile_content_gap'] ?? 0) ?>">
                        <?php foreach (array_values($col['contents'] ?? []) as $j => $content): ?>
                        <?php if (!empty($content['content_id'])): ?>
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][content_id]" value="<?= (int) $content['content_id'] ?>">
                        <?php endif; ?>
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][content_type]" value="<?= htmlspecialchars($content['content_type'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][content_kind]" value="<?= htmlspecialchars($content['content_kind'] ?? 'CORE') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][content_skin]" value="<?= htmlspecialchars($content['content_skin'] ?? '') ?>">
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][title_config]" value="<?= htmlspecialchars(json_encode($content['title_config'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][content_config]" value="<?= htmlspecialchars(json_encode($content['content_config'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][content_items]" value="<?= htmlspecialchars(json_encode($content['content_items'] ?? [])) ?>">
                        <input type="hidden" name="columns[<?= $i ?>][contents][<?= $j ?>][is_active]" value="<?= (int) ($content['is_active'] ?? 1) ?>">
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 저장 버튼 -->
        <div class="sticky-act sticky-status">
            <a href="/admin/block-row<?= $isPageBased ? '?page_id=' . $pageId : $listQuery ?>" class="btn btn-default">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-outline-info" onclick="showPreview()">
                <i class="bi bi-eye"></i> 미리보기
            </button>
            <?php if ($isEdit): ?>
            <button type="button" class="btn btn-outline-secondary" id="rowRevisionButton">
                <i class="bi bi-clock-history"></i> 변경 이력
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary mublo-submit"
                    data-target="/admin/block-row/store"
                    data-callback="blockrowSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="modal fade" id="rowRevisionModal" tabindex="-1" aria-labelledby="rowRevisionTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rowRevisionTitle"><i class="bi bi-clock-history"></i> 블록 행 변경 이력</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
            </div>
            <div class="modal-body" id="rowRevisionList"><p class="text-muted mb-0">이력을 불러오는 중입니다.</p></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 미리보기 모달 -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye"></i> 블록 미리보기</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="previewLoading" class="text-center text-muted py-5">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">미리보기 생성 중...</p>
                </div>
                <div id="previewError" style="display: none;" class="p-3"></div>
                <iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" style="display: none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
            </div>
        </div>
    </div>
</div>

<!-- 칸 설정 모달 -->
<div class="modal fade" id="columnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable" style="--bs-modal-width:min(1600px,96vw);">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="modalColumnNumber">1</span>번째 칸 설정</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalColumnIndex" value="0">

                <!-- 탭 -->
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-style">스타일</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-title">제목</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-content">콘텐츠</button>
                    </li>
                </ul>

                <div class="tab-content pt-3">
                    <!-- 스타일 탭 -->
                    <div class="tab-pane fade show active" id="tab-style">
                        <!-- 레이아웃 -->
                        <div class="card mb-3">
                            <div class="card-hero text-pastel-blue">
                                <i class="bi bi-rulers"></i>
                                <span>레이아웃</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">칸 너비</label>
                                        <div class="input-group">
                                            <input type="text" id="modal_column_width" class="form-control" placeholder="자동">
                                            <select id="modal_column_width_unit" class="form-select" style="max-width: 80px;">
                                                <option value="%">%</option>
                                                <option value="px">px</option>
                                            </select>
                                        </div>
                                        <div class="form-text">비워두면 균등 분배</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">PC 여백</label>
                                        <input type="text" id="modal_pc_padding" class="form-control" placeholder="15px">
                                        <div class="form-text">위, 오른쪽, 아래, 왼쪽 순서</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Mobile 여백</label>
                                        <input type="text" id="modal_mobile_padding" class="form-control" placeholder="10px">
                                        <div class="form-text">위, 오른쪽, 아래, 왼쪽 순서</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 배경 -->
                        <div class="card mb-3">
                            <div class="card-hero text-pastel-green">
                                <i class="bi bi-palette"></i>
                                <span>배경</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">배경 색상</label>
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="color" class="form-control form-control-color p-0"
                                                   id="modal_bg_color_picker"
                                                   value="#ffffff"
                                                   title="색상 선택">
                                            <input type="text" id="modal_bg_color" class="form-control" placeholder="#ffffff">
                                        </div>
                                        <div class="form-text">비워두면 기본 배경색 적용</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">배경 이미지</label>
                                        <input type="hidden" id="modal_bg_image" value="">
                                        <input type="file" id="modal_bg_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                        <div id="modal_bg_image_preview" class="mt-2 d-none">
                                            <img src="" alt="배경 이미지" class="img-fluid rounded border blockrow-bg-preview-img">
                                            <div class="form-check mt-1">
                                                <input type="checkbox" class="form-check-input" id="modal_bg_image_del">
                                                <label class="form-check-label small" for="modal_bg_image_del">기존 이미지 삭제</label>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- 배경 이미지 옵션 -->
                                    <div class="col-12 d-none" id="modal_bg_image_options">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label small">크기 (size)</label>
                                                <select id="modal_bg_size" class="form-select">
                                                    <option value="cover">cover (채우기)</option>
                                                    <option value="contain">contain (맞추기)</option>
                                                    <option value="auto">auto (원본)</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small">위치 (position)</label>
                                                <select id="modal_bg_position" class="form-select">
                                                    <option value="center center">가운데</option>
                                                    <option value="top center">상단</option>
                                                    <option value="bottom center">하단</option>
                                                    <option value="left center">좌측</option>
                                                    <option value="right center">우측</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small">반복 (repeat)</label>
                                                <select id="modal_bg_repeat" class="form-select">
                                                    <option value="no-repeat">반복 없음</option>
                                                    <option value="repeat">전체 반복</option>
                                                    <option value="repeat-x">가로 반복</option>
                                                    <option value="repeat-y">세로 반복</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small">스크롤</label>
                                                <select id="modal_bg_attachment" class="form-select">
                                                    <option value="scroll">스크롤</option>
                                                    <option value="fixed">고정 (패럴랙스)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 테두리 -->
                        <div class="card">
                            <div class="card-hero text-pastel-purple">
                                <i class="bi bi-border-outer"></i>
                                <span>테두리</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">테두리 두께</label>
                                        <input type="text" id="modal_border_width" class="form-control" placeholder="1px">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">테두리 색상</label>
                                        <input type="text" id="modal_border_color" class="form-control" placeholder="#e5e5e5">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">테두리 라운드</label>
                                        <input type="text" id="modal_border_radius" class="form-control" placeholder="8px">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 제목 탭 -->
                    <div class="tab-pane fade" id="tab-title">
                        <div class="form-check form-switch mb-3">
                            <input type="checkbox" id="modal_title_show" class="form-check-input" role="switch">
                            <label class="form-check-label" for="modal_title_show">제목 표시</label>
                        </div>

                        <!-- 제목 상세 설정 (title_show 체크 시 표시) -->
                        <div id="title_detail_wrapper">
                            <!-- 제목 설정 -->
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-sky">
                                    <i class="bi bi-type"></i>
                                    <span>제목 설정</span>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label class="form-label">제목 텍스트</label>
                                            <input type="text" id="modal_title_text" class="form-control" placeholder="최신 게시글" maxlength="25">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">제목 위치</label>
                                            <select id="modal_title_position" class="form-select">
                                                <option value="left">왼쪽</option>
                                                <option value="center">가운데</option>
                                                <option value="right">오른쪽</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">PC 크기</label>
                                            <div class="input-group">
                                                <input type="number" id="modal_title_size_pc" class="form-control" value="16" min="10" max="100">
                                                <span class="input-group-text">px</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">MO 크기</label>
                                            <div class="input-group">
                                                <input type="number" id="modal_title_size_mo" class="form-control" value="14" min="10" max="100">
                                                <span class="input-group-text">px</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">제목 색상</label>
                                            <div class="d-flex align-items-center gap-2">
                                                <input type="color" class="form-control form-control-color p-0"
                                                       id="modal_title_color_picker"
                                                       value="#000000"
                                                       title="색상 선택">
                                                <input type="text" id="modal_title_color" class="form-control" placeholder="#000000">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 제목 이미지 -->
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-orange">
                                    <i class="bi bi-image"></i>
                                    <span>제목 이미지</span>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">PC 이미지</label>
                                            <input type="file" id="modal_title_pc_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                            <input type="hidden" id="modal_title_pc_image">
                                            <div id="modal_title_pc_image_preview" class="mt-2" style="display: none;">
                                                <img src="" alt="PC 제목 이미지" style="max-height: 60px;">
                                                <div class="form-check form-check-inline ms-2">
                                                    <input type="checkbox" id="modal_title_pc_image_del" class="form-check-input">
                                                    <label class="form-check-label text-danger" for="modal_title_pc_image_del">삭제</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">MO 이미지</label>
                                            <input type="file" id="modal_title_mo_image_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                            <input type="hidden" id="modal_title_mo_image">
                                            <div id="modal_title_mo_image_preview" class="mt-2" style="display: none;">
                                                <img src="" alt="MO 제목 이미지" style="max-height: 60px;">
                                                <div class="form-check form-check-inline ms-2">
                                                    <input type="checkbox" id="modal_title_mo_image_del" class="form-check-input">
                                                    <label class="form-check-label text-danger" for="modal_title_mo_image_del">삭제</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 문구 -->
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-red">
                                    <i class="bi bi-chat-quote"></i>
                                    <span>문구 설정</span>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label class="form-label">문구 텍스트</label>
                                            <input type="text" id="modal_copytext" class="form-control" placeholder="새로운 소식을 확인하세요" maxlength="50">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">문구 위치</label>
                                            <select id="modal_copytext_position" class="form-select">
                                                <option value="">제목과 동일</option>
                                                <option value="left">왼쪽</option>
                                                <option value="center">가운데</option>
                                                <option value="right">오른쪽</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">PC 크기</label>
                                            <div class="input-group">
                                                <input type="number" id="modal_copytext_size_pc" class="form-control" value="14" min="10" max="100">
                                                <span class="input-group-text">px</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">MO 크기</label>
                                            <div class="input-group">
                                                <input type="number" id="modal_copytext_size_mo" class="form-control" value="12" min="10" max="100">
                                                <span class="input-group-text">px</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">문구 색상</label>
                                            <div class="d-flex align-items-center gap-2">
                                                <input type="color" class="form-control form-control-color p-0"
                                                       id="modal_copytext_color_picker"
                                                       value="#666666"
                                                       title="색상 선택">
                                                <input type="text" id="modal_copytext_color" class="form-control" placeholder="#666666">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 더보기 링크 -->
                            <div class="card">
                                <div class="card-hero text-pastel-blue">
                                    <i class="bi bi-link-45deg"></i>
                                    <span>더보기 링크</span>
                                </div>
                                <div class="card-body">
                                    <div class="input-group">
                                        <div class="input-group-text">
                                            <input class="form-check-input mt-0 me-2" type="checkbox" id="modal_more_link">
                                            <label class="form-check-label mb-0" for="modal_more_link">사용</label>
                                        </div>
                                        <input type="text" id="modal_more_url" class="form-control" placeholder="/board/notice">
                                    </div>
                                    <div class="input-group mt-2">
                                        <span class="input-group-text">문구</span>
                                        <input type="text" id="modal_more_text" class="form-control" placeholder="더보기" maxlength="20">
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- 콘텐츠 탭 -->
                    <div class="tab-pane fade" id="tab-content">
                        <!-- 기본 설정 -->
                        <div class="card mb-3">
                            <div class="card-hero text-pastel-green">
                                <i class="bi bi-sliders"></i>
                                <span>기본 설정</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <div class="row g-3">
                                            <div class="col-6">
                                                <label class="form-label">콘텐츠 타입</label>
                                                <select id="modal_content_type" class="form-select">
                                                    <option value="">선택하세요</option>
                                                    <?php foreach ($contentTypeGroups as $kind => $types): ?>
                                                        <?php if (!empty($types)): ?>
                                                        <optgroup label="<?= $kind ?>">
                                                            <?php foreach ($types as $type => $info): ?>
                                                            <option value="<?= $type ?>" data-kind="<?= $kind ?>">
                                                                <?= htmlspecialchars($info['title']) ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6" id="content_skin_wrapper">
                                                <label class="form-label">스킨</label>
                                                <select id="modal_content_skin" class="form-select">
                                                    <option value="">스킨 선택</option>
                                                </select>
                                            </div>
                                            <div class="col-6" id="content_count_wrapper">
                                                <label class="form-label">PC 출력갯수</label>
                                                <input type="number" id="modal_content_count_pc" class="form-control" value="4" min="1" max="100">
                                            </div>
                                            <div class="col-6" id="content_count_mo_wrapper">
                                                <label class="form-label">MO 출력갯수</label>
                                                <input type="number" id="modal_content_count_mo" class="form-control" value="4" min="1" max="100">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4" id="content_aos_wrapper" style="display: none;">
                                        <label class="form-label">출력 이벤트</label>
                                        <select id="modal_aos_effect" class="form-select">
                                            <option value="">없음</option>
                                            <optgroup label="Fade">
                                                <option value="fade-up">Fade Up</option>
                                                <option value="fade-down">Fade Down</option>
                                                <option value="fade-left">Fade Left</option>
                                                <option value="fade-right">Fade Right</option>
                                                <option value="fade-up-right">Fade Up Right</option>
                                                <option value="fade-up-left">Fade Up Left</option>
                                                <option value="fade-down-right">Fade Down Right</option>
                                                <option value="fade-down-left">Fade Down Left</option>
                                            </optgroup>
                                            <optgroup label="Flip">
                                                <option value="flip-up">Flip Up</option>
                                                <option value="flip-down">Flip Down</option>
                                                <option value="flip-left">Flip Left</option>
                                                <option value="flip-right">Flip Right</option>
                                            </optgroup>
                                            <optgroup label="Slide">
                                                <option value="slide-up">Slide Up</option>
                                                <option value="slide-down">Slide Down</option>
                                                <option value="slide-left">Slide Left</option>
                                                <option value="slide-right">Slide Right</option>
                                            </optgroup>
                                            <optgroup label="Zoom">
                                                <option value="zoom-in">Zoom In</option>
                                                <option value="zoom-in-up">Zoom In Up</option>
                                                <option value="zoom-in-down">Zoom In Down</option>
                                                <option value="zoom-in-left">Zoom In Left</option>
                                                <option value="zoom-in-right">Zoom In Right</option>
                                                <option value="zoom-out">Zoom Out</option>
                                                <option value="zoom-out-up">Zoom Out Up</option>
                                                <option value="zoom-out-down">Zoom Out Down</option>
                                                <option value="zoom-out-left">Zoom Out Left</option>
                                                <option value="zoom-out-right">Zoom Out Right</option>
                                            </optgroup>
                                        </select>
                                        <label class="form-label mt-3">시간(ms)</label>
                                        <input type="number" id="modal_aos_duration" class="form-control" value="600" min="100" max="3000" step="100">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 출력 스타일 (board, boardgroup 등에서 사용) -->
                        <div id="content_style_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-purple">
                                    <i class="bi bi-grid-3x3-gap"></i>
                                    <span>출력 스타일</span>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <h6 class="mb-2 text-muted">PC 출력</h6>
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <label class="form-label">스타일</label>
                                                    <select id="modal_pc_style" class="form-select">
                                                        <option value="list">리스트형</option>
                                                        <option value="slide">슬라이드형</option>
                                                        <option value="none">숨김</option>
                                                    </select>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label">1줄 출력갯수</label>
                                                    <select id="modal_pc_cols" class="form-select">
                                                        <?php for ($n = 1; $n <= 12; $n++): ?>
                                                        <option value="<?= $n ?>" <?= $n == 4 ? 'selected' : '' ?>><?= $n ?>개</option>
                                                        <?php endfor; ?>
                                                        <option value="auto">자동</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div id="pc_slide_options" class="mt-2" style="display: none;">
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <div class="form-check mb-0">
                                                        <input type="checkbox" class="form-check-input" id="modal_pc_autoplay_check">
                                                        <label class="form-check-label" for="modal_pc_autoplay_check">자동재생</label>
                                                    </div>
                                                    <div class="input-group input-group-sm" style="width: 8rem;">
                                                        <input type="number" id="modal_pc_autoplay_delay" class="form-control"
                                                               value="5000" min="1000" max="30000" step="500" disabled>
                                                        <span class="input-group-text">ms</span>
                                                    </div>
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input type="checkbox" class="form-check-input" id="modal_pc_loop">
                                                    <label class="form-check-label" for="modal_pc_loop">무한반복</label>
                                                </div>
                                                <div class="mb-2">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="modal_pc_slide_cover">
                                                        <label class="form-check-label" for="modal_pc_slide_cover">이미지 높이 맞춤 (cover)</label>
                                                    </div>
                                                    <div class="form-text">가장 작은 이미지 높이에 맞춰 나머지를 크롭</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="mb-2 text-muted">모바일 출력</h6>
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <label class="form-label">스타일</label>
                                                    <select id="modal_mo_style" class="form-select">
                                                        <option value="list">리스트형</option>
                                                        <option value="slide">슬라이드형</option>
                                                        <option value="none">숨김</option>
                                                    </select>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label">1줄 출력갯수</label>
                                                    <select id="modal_mo_cols" class="form-select">
                                                        <?php for ($n = 1; $n <= 12; $n++): ?>
                                                        <option value="<?= $n ?>" <?= $n == 2 ? 'selected' : '' ?>><?= $n ?>개</option>
                                                        <?php endfor; ?>
                                                        <option value="auto">자동</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div id="mo_slide_options" class="mt-2" style="display: none;">
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <div class="form-check mb-0">
                                                        <input type="checkbox" class="form-check-input" id="modal_mo_autoplay_check">
                                                        <label class="form-check-label" for="modal_mo_autoplay_check">자동재생</label>
                                                    </div>
                                                    <div class="input-group input-group-sm" style="width: 8rem;">
                                                        <input type="number" id="modal_mo_autoplay_delay" class="form-control"
                                                               value="3000" min="1000" max="30000" step="500" disabled>
                                                        <span class="input-group-text">ms</span>
                                                    </div>
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input type="checkbox" class="form-check-input" id="modal_mo_loop">
                                                    <label class="form-check-label" for="modal_mo_loop">무한반복</label>
                                                </div>
                                                <div class="mb-2">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="modal_mo_slide_cover">
                                                        <label class="form-check-label" for="modal_mo_slide_cover">이미지 높이 맞춤 (cover)</label>
                                                    </div>
                                                    <div class="form-text">가장 작은 이미지 높이에 맞춰 나머지를 크롭</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 아이템 선택 (board, boardgroup, menu) -->
                        <div id="content_items_container" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-sky">
                                    <i class="bi bi-list-check"></i>
                                    <span>아이템 선택</span>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-info small py-2 mb-3">
                                        <i class="bi bi-info-circle"></i> 왼쪽에서 아이템을 선택하여 오른쪽으로 이동하세요. 더블클릭 또는 버튼을 사용할 수 있습니다.
                                        <span id="content_items_limit_hint"></span>
                                    </div>
                                    <div class="dual-listbox-wrapper">
                                        <p class="text-muted">콘텐츠 타입을 선택하면 아이템 목록이 표시됩니다.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- outlogin 타입용 설정 -->
                        <div id="outlogin_config_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-orange">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    <span>출력 대상</span>
                                </div>
                                <div class="card-body">
                                    <div class="form-check form-check-inline">
                                        <input type="checkbox" class="form-check-input" id="modal_outlogin_show_pc" checked>
                                        <label class="form-check-label" for="modal_outlogin_show_pc">PC 출력</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input type="checkbox" class="form-check-input" id="modal_outlogin_show_mobile" checked>
                                        <label class="form-check-label" for="modal_outlogin_show_mobile">모바일 출력</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- menu 타입용 설정 (렌더러의 scope/depth/orientation 연결) -->
                        <div id="menu_config_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-sky">
                                    <i class="bi bi-list-ul"></i>
                                    <span>메뉴 설정</span>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">출력 범위</label>
                                            <select id="modal_menu_scope" class="form-select">
                                                <option value="all">전체 메뉴 트리</option>
                                                <option value="current">현재 위치의 하위 메뉴</option>
                                            </select>
                                            <div class="form-text">"현재 위치"는 방문 중인 페이지가 속한 1차 메뉴의 하위만 출력합니다. 하위가 없는 페이지에서는 자동 숨김됩니다.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">방향</label>
                                            <select id="modal_menu_orientation" class="form-select">
                                                <option value="horizontal">가로 (horizontal)</option>
                                                <option value="vertical">세로 (vertical)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">최대 깊이</label>
                                            <select id="modal_menu_depth" class="form-select">
                                                <option value="1">1단 (1차 메뉴만)</option>
                                                <option value="2" selected>2단</option>
                                                <option value="3">3단</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-text"><i class="bi bi-info-circle"></i> 아래 "아이템 선택"에서 특정 1차 메뉴를 고르면 그 메뉴만 출력됩니다. 선택하지 않으면 전체가 출력됩니다.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- include 타입용 파일 선택 -->
                        <div id="include_path_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-red">
                                    <i class="bi bi-file-earmark-code"></i>
                                    <span>포함 파일</span>
                                </div>
                                <div class="card-body">
                                    <label class="form-label" for="modal_include_path">포함할 파일</label>
                                    <select id="modal_include_path" class="form-select"<?= empty($canEditInclude) ? ' disabled' : '' ?>>
                                        <option value="">선택하세요</option>
                                        <?php
                                        $includeFiles = \Mublo\Core\Block\Renderer\IncludeRenderer::getAvailableFiles();
                                        foreach ($includeFiles as $incFile): ?>
                                        <option value="<?= htmlspecialchars($incFile) ?>"><?= htmlspecialchars($incFile) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($includeFiles)): ?>
                                    <div class="form-text">views/Block/include/ 디렉토리에 PHP 파일을 추가하세요.</div>
                                    <?php else: ?>
                                    <div class="form-text">views/Block/include/ 내 파일 목록</div>
                                    <?php endif; ?>
                                    <?php if (empty($canEditInclude)): ?>
                                    <div class="form-text text-danger">Include 블록은 최고관리자만 선택·수정할 수 있습니다.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- image 타입용 설정 -->
                        <div id="image_config_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-blue">
                                    <i class="bi bi-image"></i>
                                    <span>이미지 설정</span>
                                    <button type="button" class="btn btn-xs btn-outline-secondary ms-auto" id="btn_add_image">
                                        <i class="bi bi-plus-lg"></i> 이미지 추가
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3" id="image_items_container">
                                        <!-- 이미지 아이템들이 여기에 동적으로 추가됩니다 -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- movie 타입용 설정 -->
                        <div id="movie_config_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-green">
                                    <i class="bi bi-play-circle"></i>
                                    <span>동영상 설정</span>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">동영상 타입</label>
                                            <select id="modal_video_type" class="form-select">
                                                <option value="youtube">YouTube</option>
                                                <option value="vimeo">Vimeo</option>
                                                <option value="url">직접 URL</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label" id="video_input_label">YouTube URL 또는 영상 ID</label>
                                            <input type="text" id="modal_video_url" class="form-control"
                                                   placeholder="https://www.youtube.com/watch?v=... 또는 영상 ID">
                                            <div class="form-text" id="video_input_hint">YouTube 링크를 붙여넣거나 영상 ID만 입력하세요.</div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" id="modal_video_autoplay" class="form-check-input">
                                                <label class="form-check-label" for="modal_video_autoplay">자동 재생</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" id="modal_video_muted" class="form-check-input" checked>
                                                <label class="form-check-label" for="modal_video_muted">음소거 (자동재생 시 필수)</label>
                                            </div>
                                        </div>
                                        <div class="col-12" id="video_preview_area" style="display: none;">
                                            <label class="form-label">미리보기</label>
                                            <div class="ratio ratio-16x9 border rounded overflow-hidden">
                                                <iframe id="modal_video_preview" src="" allowfullscreen></iframe>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- HTML 콘텐츠용 에디터 (html 타입 선택 시 표시) -->
                        <div id="html_editor_wrapper" style="display: none;">
                            <div class="card mb-3">
                                <div class="card-hero text-pastel-purple">
                                    <i class="bi bi-code-slash"></i>
                                    <span>HTML 콘텐츠</span>
                                </div>
                                <div class="card-body">
                                  <div class="row g-3">
                                    <div class="col-xl-4">
                                    <div class="card border-primary-subtle mb-3" id="row_html_ai_panel">
                                        <div class="card-body p-3">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <strong><i class="bi bi-stars"></i> AI로 HTML 만들기</strong>
                                                <a href="/admin/ai-settings" target="_blank" rel="noopener" id="row_html_ai_settings" class="ms-auto small text-nowrap">AI 설정</a>
                                            </div>
                                            <textarea id="row_html_ai_prompt" class="form-control form-control-sm" rows="7" maxlength="4000"
                                                      placeholder="예: 서비스 장점을 3개의 카드로 보여주는 반응형 섹션을 만들어줘"></textarea>
                                            <div class="text-muted mt-1" style="font-size:10.5px;">슬라이드·탭·아코디언은 검증된 Core 동작으로 안전하게 생성됩니다.</div>
                                            <div class="d-flex align-items-center mt-2 mb-1">
                                                <strong class="small">참고 자료</strong>
                                                <label class="btn btn-sm btn-outline-secondary ms-auto mb-0" style="cursor:pointer;">
                                                    <i class="bi bi-paperclip"></i> 업로드
                                                    <input type="file" id="row_html_ai_files" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.csv,.json,.docx,.xlsx,.pptx">
                                                </label>
                                            </div>
                                            <div class="text-muted mb-1" style="font-size:10.5px;">이미지·PDF·TXT·MD·CSV·JSON·DOCX·XLSX·PPTX</div>
                                            <div id="row_html_ai_assets" class="d-grid gap-1 overflow-auto" style="max-height:250px;"><span class="text-muted small">자료를 불러오는 중…</span></div>
                                            <details class="mt-2">
                                                <summary class="small" style="cursor:pointer;">최근 생성 기록</summary>
                                                <div id="row_html_ai_history" class="row-html-ai-history d-grid gap-1"></div>
                                            </details>
                                            <div class="row-html-ai-actions">
                                                <select id="row_html_ai_mode" class="form-select form-select-sm">
                                                    <option value="create">새로 만들기</option>
                                                    <option value="modify">현재 내용 수정</option>
                                                </select>
                                                <button type="button" class="btn btn-sm btn-primary" id="row_html_ai_generate"><i class="bi bi-stars"></i> 생성</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="row_html_ai_undo" style="display:none;"><i class="bi bi-arrow-counterclockwise"></i> 되돌리기</button>
                                            </div>
                                            <span id="row_html_ai_status" class="text-muted small row-html-ai-status" aria-live="polite"></span>
                                        </div>
                                    </div>
                                    </div>
                                    <div class="col-xl-8 d-flex flex-column">
                                    <?php /* 라이트로 고정하는 이유: 미리보기가 라이트 고정이라, 편집 캔버스도
                                       라이트로 맞춰 편집 화면과 미리보기를 일치시킨다(편집 중 보는 모습 = 미리보기 결과).
                                       테마 결정은 호스트(이 폼)가 로드 시 담당하고, 에디터 JS는 테마 불가지론. */ ?>
                                    <div class="editor-wrapper block-html-editor-wrapper" data-editor-id="modal_html_content" data-bs-theme="light">
                                        <textarea id="modal_html_content"
                                                  name="modal_html_content"
                                                  class="block-html-visual-editor"
                                                  data-height="500"
                                                  data-toolbar="landing"
                                                  data-toolbar-items="source,separator,undo,redo,separator,fontsize,separator,bold,italic,underline,separator,forecolor,backcolor,separator,alignleft,aligncenter,alignright,separator,link,unlink,image,video,separator,fullscreen"
                                                  data-upload-url="/api/v1/editor/upload"
                                                  data-upload-csrf="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>"></textarea>
                                    </div>

                                    <!-- CSS / JS 입력 (접이식) -->
                                    <div class="mt-3">
                                        <div class="d-flex gap-2 mb-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#html_css_collapse">
                                                <i class="bi bi-filetype-css"></i> CSS
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#html_js_collapse">
                                                <i class="bi bi-filetype-js"></i> JavaScript
                                            </button>
                                        </div>
                                        <div class="collapse" id="html_css_collapse">
                                            <label class="form-label small text-muted mb-1">CSS <small>(&lt;style&gt; 태그 없이 작성)</small></label>
                                            <textarea id="modal_html_css" class="form-control font-monospace" rows="8" placeholder=".my-section { padding: 60px 20px; }&#10;.my-section h2 { font-size: 32px; }" style="font-size: 13px; tab-size: 4; line-height: 1.5;"></textarea>
                                            <div class="form-text">이 블록 안에서만 적용됩니다 (자동 스코핑) — 다른 블록이나 페이지 전역에는 영향을 주지 않습니다.</div>
                                        </div>
                                        <div class="collapse mt-2" id="html_js_collapse">
                                            <label class="form-label small text-muted mb-1">JavaScript <small>(&lt;script&gt; 태그 없이 작성)</small></label>
                                            <textarea id="modal_html_js" class="form-control font-monospace" rows="8" placeholder="// block = 이 블록의 컨테이너 요소&#10;block.querySelector('.my-btn')?.addEventListener('click', function(){&#10;    // your code here&#10;});" style="font-size: 13px; tab-size: 4; line-height: 1.5;"></textarea>
                                            <div class="form-text">코드는 이 블록의 컨테이너 요소를 <code>block</code> 변수로 받는 함수로 감싸져 실행됩니다. 직접 작성한 코드는 유지되며, AI의 선언형 동작 코드는 Core 관리 구간으로 추가·교체됩니다.</div>
                                        </div>
                                    </div>
                                    </div>
                                  </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" onclick="saveColumnSettings()">적용</button>
            </div>
        </div>
    </div>
</div>

<!-- BlockRow Form JS -->
<script>
// 프론트 미리보기 토큰 — 칸 에디터 캔버스·미리보기 iframe 이 프론트와 같은 캐스케이드
// (tokens.css → 프레임 스킨 변수 rebind → 도메인 브랜드색)를 재현한다.
// 소비자: 바로 아래 front-preview-tokens.js (MubloFrontPreviewCss)
window.MubloFrontPreviewTokens = <?= json_encode($frontPreviewTokens ?? [], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= asset('/assets/js/admin/front-preview-tokens.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-html-editor/BlockHtmlEditorBase.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-html-editor/index.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-preview-iframe.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-content-capabilities.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-content-editor-adapters.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/blockrow-form.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-content-stack.js') ?>"></script>
<script>
// 저장 완료 콜백
MubloRequest.registerCallback('blockrowSaved', function(response) {
    if (response.result === 'success') {
        MubloRequest.showToast(response.message || '<?= $isEdit ? '수정' : '등록' ?>되었습니다.', 'success');
        if (response.data && response.data.redirect) {
            location.href = response.data.redirect;
        }
    } else {
        MubloRequest.showAlert(response.message || '저장에 실패했습니다.', 'error');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const revisionButton = document.getElementById('rowRevisionButton');
    if (revisionButton) {
        revisionButton.addEventListener('click', async function () {
            const list = document.getElementById('rowRevisionList');
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('rowRevisionModal'));
            modal.show();
            list.innerHTML = '<p class="text-muted mb-0">이력을 불러오는 중입니다.</p>';
            try {
                const response = await fetch('/admin/block-row/revisions?row_id=<?= (int) $rowId ?>');
                const json = await response.json();
                const items = json.data?.items || [];
                if (!json.success || items.length === 0) {
                    list.innerHTML = '<p class="text-muted mb-0">저장된 변경 이력이 없습니다.</p>';
                    return;
                }
                list.innerHTML = items.map(item => `
                    <div class="d-flex align-items-center justify-content-between border rounded p-3 mb-2">
                        <div><strong>버전 ${Number(item.row_revision_no)}</strong>
                            <span class="badge text-bg-light ms-2">${String(item.source)}</span>
                            <div class="small text-muted mt-1">${String(item.created_at)}</div></div>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-restore-revision="${Number(item.revision_id)}">이 상태로 복구</button>
                    </div>`).join('');
                list.querySelectorAll('[data-restore-revision]').forEach(button => {
                    button.addEventListener('click', async function () {
                        if (!confirm('현재 상태를 이력에 보관한 뒤 선택한 버전으로 복구합니다. 계속할까요?')) return;
                        const body = new FormData();
                        body.append('_token', <?= json_encode($csrfToken ?? '') ?>);
                        body.append('revision_id', this.dataset.restoreRevision);
                        body.append('revision_no', document.querySelector('[name="formData[revision_no]"]').value);
                        const restore = await fetch('/admin/block-row/restore-revision', {method: 'POST', body});
                        const result = await restore.json();
                        if (!result.success) throw new Error(result.message || '복구하지 못했습니다.');
                        location.reload();
                    });
                });
            } catch (error) {
                list.innerHTML = `<div class="alert alert-danger mb-0">${String(error.message || error)}</div>`;
            }
        });
    }

    BlockRowForm.init({
        contentTypes: <?= json_encode($contentTypes) ?>,
        contentTypeGroups: <?= json_encode($contentTypeGroups) ?>,
        skinLists: <?= json_encode($skinLists ?? []) ?>,
        domainId: <?= json_encode($domainId ?? 1) ?>,
        rowId: <?= (int) $rowId ?>,
        csrfToken: <?= json_encode($csrfToken ?? '') ?>,
        canEditInclude: <?= !empty($canEditInclude) ? 'true' : 'false' ?>
    });

    // 와이드 가능 위치 (컨테이너 외부에 렌더링되는 위치)
    // topbar(헤더 위 최상단 바)도 컨테이너 밖이므로 와이드 허용.
    var wideAllowedPositions = ['topbar', 'index', 'subhead', 'subfoot'];

    // 출력 위치 변경 시 position_menu 표시/숨김 + 넓이 타입 제한 + topbar 전용 UI
    var posSelect = document.getElementById('position_select');
    var menuWrapper = document.getElementById('position_menu_wrapper');
    var menuSelect = document.getElementById('position_menu_select');
    var widthTypeSelect = document.getElementById('width_type_select');
    var widthTypeHint = document.getElementById('width_type_hint');
    var topbarDismissCard = document.getElementById('topbar_dismiss_card');

    // topbar 일 때만 "보지 않기" 카드 노출
    function updateTopbarDismiss() {
        if (!topbarDismissCard) return;
        topbarDismissCard.style.display = (posSelect && posSelect.value === 'topbar') ? '' : 'none';
    }

    function updateWidthTypeByPosition() {
        if (!posSelect || !widthTypeSelect) return;
        var pos = posSelect.value;
        var isConstrained = pos && wideAllowedPositions.indexOf(pos) === -1;

        // disabled select는 FormData에 포함되지 않으므로 hidden input으로 보완
        var hiddenInput = document.getElementById('width_type_hidden');
        if (isConstrained) {
            widthTypeSelect.value = '1';
            widthTypeSelect.disabled = true;
            if (widthTypeHint) widthTypeHint.style.display = '';
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.id = 'width_type_hidden';
                hiddenInput.name = 'formData[width_type]';
                widthTypeSelect.parentNode.appendChild(hiddenInput);
            }
            hiddenInput.value = '1';
        } else {
            widthTypeSelect.disabled = false;
            if (widthTypeHint) widthTypeHint.style.display = 'none';
            if (hiddenInput) hiddenInput.remove();
        }
    }

    if (posSelect) {
        posSelect.addEventListener('change', function() {
            // position_menu 표시/숨김
            if (menuWrapper) {
                if (this.value === 'index') {
                    menuWrapper.style.display = 'none';
                    if (menuSelect) menuSelect.value = '';
                } else {
                    menuWrapper.style.display = '';
                }
            }
            // 넓이 타입 제한
            updateWidthTypeByPosition();
            // topbar 전용 UI
            updateTopbarDismiss();
        });

        // 초기 상태 적용
        updateWidthTypeByPosition();
        updateTopbarDismiss();
    }

    // 배경 색상: 컬러 픽커 ↔ 텍스트 입력 동기화
    var colorPicker = document.getElementById('row_bg_color_picker');
    var colorText = document.getElementById('row_bg_color');
    if (colorPicker && colorText) {
        colorPicker.addEventListener('input', function() {
            colorText.value = this.value;
        });
        colorText.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                colorPicker.value = this.value;
            }
        });
    }

    // 배경 이미지: 옵션 영역 표시/숨김
    var bgImageInput = document.getElementById('row_bg_image');
    var bgImageOptions = document.getElementById('bg_image_options');
    var bgImageDel = document.getElementById('bg_image_del');
    if (bgImageInput && bgImageOptions) {
        bgImageInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                bgImageOptions.classList.remove('d-none');
            }
        });
    }
    if (bgImageDel && bgImageOptions) {
        bgImageDel.addEventListener('change', function() {
            bgImageOptions.classList.toggle('d-none', this.checked);
        });
    }
});
</script>

<style>
/* AI HTML 생성 패널 — 임베드 여부와 무관하게 필요하다(칸 설정 모달은 비임베드로도 열린다). */
.row-html-ai-history { margin-top:.5rem; }
.row-html-ai-actions { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:.5rem; align-items:center; margin-top:.75rem; }
.row-html-ai-actions .form-select { width:100%; min-width:0; }
.row-html-ai-actions .btn { min-width:82px; white-space:nowrap; }
.row-html-ai-status { display:block; min-height:1.25rem; margin-top:.5rem; overflow-wrap:anywhere; }
</style>

<?php if (!empty($embedMode)): ?>
<!-- =====================================================================
     임베드 모드 — 블록 에디터의 모달 iframe 안에서 열렸다(블록 에디터 설계 6).
     페이지 크롬을 걷어내고, 저장/취소를 부모(에디터)와의 postMessage 로 바꾼다.
     ===================================================================== -->
<style>
.page-title .page-title-actions { display: none; }
.page-container { padding: 6px 18px 90px; }
.page-title { margin-bottom: 8px; }
.page-title-text p { display: none; }
</style>
<script>
(function () {
    // 저장 콜백 교체 — 인라인 등록(blockrowSaved)보다 뒤에 실행되어 이긴다.
    // 리다이렉트 대신 부모에 알리고, 모달 닫기와 미리보기 갱신은 에디터가 한다.
    MubloRequest.registerCallback('blockrowSaved', function (response) {
        if (response.result === 'success') {
            window.parent.postMessage({ type: 'bke:row-saved', rowId: <?= (int) $rowId ?> }, window.location.origin);
        } else {
            MubloRequest.showAlert(response.message || '저장에 실패했습니다.', 'error');
        }
    });

    // 취소는 목록 이동 대신 모달 닫기
    document.querySelectorAll('.sticky-act a.btn-default').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            window.parent.postMessage({ type: 'bke:close' }, window.location.origin);
        });
    });

    <?php if (($openColumn ?? -1) >= 0): ?>
    // 에디터에서 칸을 클릭해 들어왔다 — 해당 칸 설정 모달을 바로 연다.
    document.addEventListener('DOMContentLoaded', function () {
        // BlockRowForm.init(같은 이벤트의 앞선 리스너) 이후에 열리도록 한 틱 미룬다.
        setTimeout(function () {
            if (typeof openColumnModal === 'function') {
                openColumnModal(<?= (int) $openColumn ?>);
                // 에디터에서 칸을 지정해 들어온 목적은 대부분 내용 교체다 —
                // 스타일 탭 대신 콘텐츠 탭(전용 셀렉터 포함)을 기본으로 연다.
                var contentTab = document.querySelector('[data-bs-target="#tab-content"]');
                if (contentTab && window.bootstrap) {
                    bootstrap.Tab.getOrCreateInstance(contentTab).show();
                }
            }
        }, 80);
    });
    <?php endif; ?>
})();
</script>
<?php endif; ?>
