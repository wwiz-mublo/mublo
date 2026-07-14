<?php
/**
 * 위젯 목록 + 설정
 *
 * @var string $pageTitle 페이지 제목
 * @var array $config 위젯 표시 설정 (left_enabled, right_enabled, mobile_enabled, left_skin, right_skin, mobile_skin, left_width, right_width, mobile_width)
 * @var array $items 위젯 항목 목록
 * @var int $totalItems 전체 항목 수
 * @var array $skinOptions 스킨 옵션 ['left' => [...], 'right' => [...], 'mobile' => [...]]
 */
$items = $items ?? [];
$config = $config ?? [];
$totalItems = $totalItems ?? 0;
$skinOptions = $skinOptions ?? [];

$positionLabels = [
    'left' => '좌측',
    'right' => '우측',
    'mobile' => '모바일',
];

$positionColors = [
    'left' => 'primary',
    'right' => 'info',
    'mobile' => 'warning',
];

$typeLabels = [
    'link' => '링크',
    'tel' => '전화',
];

$typeColors = [
    'link' => 'secondary',
    'tel' => 'primary',
];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>위젯을 등록하고 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <button type="button" class="btn btn-sm btn-primary" onclick="openWidgetModal()">
                <i class="bi bi-plus-lg"></i> 위젯 추가
            </button>
        </div>
    </div>

    <div class="page-block">
        <!-- 위젯 표시 설정 -->
        <form id="configForm">
        <div class="row gx-3 mb-3">
            <?php
            $positions = [
                'left'   => ['label' => 'PC 좌측 위젯', 'icon' => 'bi-arrow-bar-left',  'desc' => '화면 왼쪽에 고정 표시 (PC만)'],
                'right'  => ['label' => 'PC 우측 위젯', 'icon' => 'bi-arrow-bar-right', 'desc' => '화면 오른쪽에 고정 표시 (PC만)'],
                'mobile' => ['label' => '모바일 위젯',  'icon' => 'bi-phone',           'desc' => '모바일 화면 우측 하단에 고정 표시'],
            ];
            $defaultWidths = ['left' => 80, 'right' => 80, 'mobile' => 50];
            foreach ($positions as $pos => $info):
                $enabled = !empty($config[$pos . '_enabled']);
                $currentSkin = $config[$pos . '_skin'] ?? 'basic';
                $currentWidth = (int) ($config[$pos . '_width'] ?? $defaultWidths[$pos]);
                $options = $skinOptions[$pos] ?? ['basic' => 'basic'];
            ?>
            <div class="col-md-4 mb-3 mb-md-0">
                <div class="card widget-config-card h-100<?= $enabled ? ' border-primary' : '' ?>">
                    <div class="card-hero">
                        <i class="<?= $info['icon'] ?> text-primary"></i>
                        <span class="small"><?= $info['label'] ?></span>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input type="checkbox" class="form-check-input" id="cfg_<?= $pos ?>"
                                   name="formData[<?= $pos ?>_enabled]" value="1"<?= $enabled ? ' checked' : '' ?>
                                   style="cursor:pointer">
                        </div>
                    </div>
                    <div class="card-body px-3 pt-0 pb-3">
                        <p class="text-muted small mb-2"><?= $info['desc'] ?></p>
                        <div class="row g-2 align-items-end">
                            <div class="col">
                                <label class="form-label small text-muted mb-1">출력 스킨</label>
                                <select name="formData[<?= $pos ?>_skin]" class="form-select form-select-sm">
                                    <?php foreach ($options as $skinKey => $skinLabel): ?>
                                    <option value="<?= htmlspecialchars($skinKey) ?>"<?= $currentSkin === $skinKey ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($skinLabel) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label class="form-label small text-muted mb-1">크기</label>
                                <div class="input-group input-group-sm" style="width:82px">
                                    <input type="number" class="form-control" name="formData[<?= $pos ?>_width]"
                                           value="<?= $currentWidth ?>" min="20" max="200" step="1">
                                    <span class="input-group-text">px</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mb-4 text-center">
            <button type="button" class="btn btn-sm btn-primary mublo-submit"
                    data-target="/admin/widget/config/save" data-callback="onConfigSaved">
                <i class="bi bi-check-lg"></i> 설정 저장
            </button>
        </div>
        </form>
    </div>

    <div class="page-block">
        <!-- 위젯 목록 카운트 -->
        <form method="get" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/widget/list">전체</a></span>
                        <span class="ov-num"><b><?= number_format($totalItems) ?></b> 개</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <input type="text" name="keyword" class="form-control form-control-sm"
                                       placeholder="제목 검색"
                                       value="<?= htmlspecialchars($search['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($search['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/widget/list'"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-default">검색</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <form name="flist" id="flist">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width:40px">
                                <input type="checkbox" class="form-check-input" name="chk_all" id="chk_all">
                            </th>
                            <th style="width:60px">번호</th>
                            <th style="width:60px">아이콘</th>
                            <th>제목</th>
                            <th style="width:80px; text-align:center">위치</th>
                            <th style="width:80px; text-align:center">유형</th>
                            <th style="width:80px; text-align:center">상태</th>
                            <th style="width:80px; text-align:center">순서</th>
                            <th style="width:120px">관리</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">등록된 위젯이 없습니다.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($items as $item):
                            $id = (int) ($item['item_id'] ?? 0);
                            $position = $item['position'] ?? '';
                            $itemType = $item['item_type'] ?? '';
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input" name="chk[]" value="<?= $id ?>">
                            </td>
                            <td class="text-nowrap"><?= $id ?></td>
                            <td class="text-nowrap">
                                <?php if (!empty($item['icon_image'])): ?>
                                <img src="<?= htmlspecialchars($item['icon_image']) ?>" alt="" style="width:50px; height:50px; object-fit:cover;">
                                <?php else: ?>
                                <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($item['title'] ?? '') ?></td>
                            <td class="text-center text-nowrap">
                                <?php $pc = htmlspecialchars($positionColors[$position] ?? 'secondary'); ?>
                                <span class="badge bg-<?= $pc ?>-subtle text-<?= $pc ?>-emphasis border border-<?= $pc ?>-subtle">
                                    <?= htmlspecialchars($positionLabels[$position] ?? $position) ?>
                                </span>
                            </td>
                            <td class="text-center text-nowrap">
                                <?php $tc = htmlspecialchars($typeColors[$itemType] ?? 'secondary'); ?>
                                <span class="badge bg-<?= $tc ?>-subtle text-<?= $tc ?>-emphasis border border-<?= $tc ?>-subtle">
                                    <?= htmlspecialchars($typeLabels[$itemType] ?? $itemType) ?>
                                </span>
                            </td>
                            <td class="text-center text-nowrap">
                                <?php if (!empty($item['is_active'])): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">사용</span>
                                <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">미사용</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"><?= (int) ($item['sort_order'] ?? 0) ?></span>
                            </td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-default" onclick='openWidgetModal(<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>)'>수정</button>
                                <button type="button" class="btn btn-sm btn-default" onclick="deleteWidget(<?= $id ?>)">삭제</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 하단 액션바 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default mublo-submit" data-target="/admin/widget/listDelete" data-callback="afterBulkDelete">
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 위젯 추가/수정 모달 -->
<div class="modal fade" id="widgetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="widgetModalTitle">위젯 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="widgetForm">
                <div class="modal-body">
                    <input type="hidden" name="formData[item_id]" id="wf_item_id" value="0">

                    <div class="mb-3">
                        <label for="wf_title" class="form-label">제목 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="wf_title" name="formData[title]" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="wf_position" class="form-label">위치</label>
                            <select class="form-select" id="wf_position" name="formData[position]">
                                <option value="left">PC 좌측</option>
                                <option value="right">PC 우측</option>
                                <option value="mobile">모바일</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="wf_item_type" class="form-label">유형</label>
                            <select class="form-select" id="wf_item_type" name="formData[item_type]">
                                <option value="link">링크</option>
                                <option value="tel">전화</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="wf_icon_image" class="form-label">아이콘 이미지</label>
                        <input type="file" class="form-control" id="wf_icon_image" name="fileData[icon_image]" accept="image/*">
                        <input type="hidden" name="formData[icon_image]" id="wf_icon_image_hidden" value="">
                        <div id="wf_icon_preview" class="mt-2" style="display:none;">
                            <img id="wf_icon_preview_img" src="" alt="" style="width:50px; height:50px; object-fit:cover; border-radius:50%;">
                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="clearIconPreview()">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="wf_link_url" class="form-label">링크 URL</label>
                        <input type="text" class="form-control" id="wf_link_url" name="formData[link_url]" placeholder="https://... 또는 전화번호">
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label for="wf_link_target" class="form-label">링크 대상</label>
                            <select class="form-select" id="wf_link_target" name="formData[link_target]">
                                <option value="_self">현재 창</option>
                                <option value="_blank">새 창</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="wf_sort_order" class="form-label">정렬 순서</label>
                            <input type="number" class="form-control" id="wf_sort_order" name="formData[sort_order]" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">상태</label>
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="wf_is_active" name="formData[is_active]" value="1" checked>
                            <label class="form-check-label" for="wf_is_active">사용</label>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="button" class="btn btn-primary mublo-submit" data-target="/admin/widget/store" data-callback="onWidgetSaved">저장</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.widget-config-card > div:first-child {
    background: transparent;
    border-bottom: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 전체 선택
    var checkAll = document.getElementById('chk_all');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }

    // 아이콘 파일 미리보기
    var fileInput = document.getElementById('wf_icon_image');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = this.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function (e) {
                var previewImg = document.getElementById('wf_icon_preview_img');
                var previewWrap = document.getElementById('wf_icon_preview');
                previewImg.src = e.target.result;
                previewWrap.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    }

    // 모달 닫힐 때 포커스를 모달 밖으로 이동 (aria-hidden 접근성 경고 방지)
    var widgetModal = document.getElementById('widgetModal');
    if (widgetModal) {
        widgetModal.addEventListener('hide.bs.modal', function () {
            if (this.contains(document.activeElement)) {
                document.activeElement.blur();
            }
        });
    }
});

function esc(str) {
    if (str === null || str === undefined) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
}

/**
 * 위젯 모달 열기
 * @param {object|undefined} data 수정 시 기존 데이터
 */
function openWidgetModal(data) {
    var modal = document.getElementById('widgetModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
    var title = document.getElementById('widgetModalTitle');
    var form = document.getElementById('widgetForm');

    form.reset();
    document.getElementById('wf_icon_image_hidden').value = '';
    document.getElementById('wf_icon_preview').style.display = 'none';
    document.getElementById('wf_icon_preview_img').src = '';
    document.getElementById('wf_is_active').checked = true;

    if (data) {
        title.textContent = '위젯 수정';
        document.getElementById('wf_item_id').value = data.item_id || 0;
        document.getElementById('wf_title').value = esc(data.title || '');
        document.getElementById('wf_position').value = data.position || 'left';
        document.getElementById('wf_item_type').value = data.item_type || 'link';
        document.getElementById('wf_link_url').value = esc(data.link_url || '');
        document.getElementById('wf_link_target').value = data.link_target || '_self';
        document.getElementById('wf_sort_order').value = data.sort_order || 0;
        document.getElementById('wf_is_active').checked = !!parseInt(data.is_active);

        if (data.icon_image) {
            document.getElementById('wf_icon_image_hidden').value = data.icon_image;
            document.getElementById('wf_icon_preview_img').src = data.icon_image;
            document.getElementById('wf_icon_preview').style.display = 'block';
        }
    } else {
        title.textContent = '위젯 추가';
        document.getElementById('wf_item_id').value = 0;
        document.getElementById('wf_sort_order').value = 0;
    }

    bsModal.show();
}

function clearIconPreview() {
    document.getElementById('wf_icon_image').value = '';
    document.getElementById('wf_icon_image_hidden').value = '';
    document.getElementById('wf_icon_preview').style.display = 'none';
    document.getElementById('wf_icon_preview_img').src = '';
}

function onWidgetSaved(data) {
    var modal = bootstrap.Modal.getInstance(document.getElementById('widgetModal'));
    if (modal) modal.hide();
    location.reload();
}

function onConfigSaved(data) {
    alert(data.message || '설정이 저장되었습니다.');
    location.reload();
}

// 토글 변경 시 카드 border 업데이트
document.querySelectorAll('#configForm .form-check-input').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        var card = this.closest('.card');
        if (card) {
            card.classList.toggle('border-primary', this.checked);
        }
    });
});

function deleteWidget(id) {
    if (!confirm('이 위젯을 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/widget/' + id + '/delete', {}, { method: 'POST', loading: true })
        .then(function () {
            location.reload();
        });
}

function afterBulkDelete(data) {
    alert(data.message || '삭제되었습니다.');
    location.reload();
}
</script>
