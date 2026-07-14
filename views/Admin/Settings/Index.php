<?php
/**
 * Admin Settings - Index
 *
 * 환경 설정 메인 페이지
 *
 * @var string $pageTitle 페이지 제목
 * @var array $anchor 탭 메뉴
 * @var array $siteConfig 사이트 설정
 * @var array $companyConfig 회사 정보
 * @var array $seoConfig SEO 설정
 * @var array $themeConfig 테마 설정
 * @var array $editorOptions 에디터 옵션
 * @var array $snsTypes SNS 타입 목록
 */
?>
<form name="frm" id="frm" enctype="multipart/form-data">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= $pageTitle ?? '' ?></h3>
                <p><?= $description ?? '' ?></p>
            </div>
            <div class="page-title-actions">
                <button type="button"
                    class="btn btn-sm btn-primary mublo-submit"
                    data-target="/admin/settings/update"
                    data-callback="settingsSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <?php if (!empty($migrationResult)): ?>
        <?php if ($migrationResult['success'] && !empty($migrationResult['executed'])): ?>
        <div class="alert alert-success alert-dismissible d-flex align-items-start gap-2 mt-3 mb-0" role="alert">
            <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i>
            <div>
                <strong>DB 업데이트가 완료되었습니다.</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <?php foreach ($migrationResult['executed'] as $file): ?>
                    <li class="small text-muted"><?= htmlspecialchars($file) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
        <?php elseif (!$migrationResult['success']): ?>
        <div class="alert alert-danger alert-dismissible d-flex align-items-start gap-2 mt-3 mb-0" role="alert">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
            <div>
                <strong>DB 업데이트 중 오류가 발생했습니다.</strong>
                <?php if (!empty($migrationResult['error'])): ?>
                <p class="mb-0 mt-1 small"><?= htmlspecialchars($migrationResult['error']) ?></p>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="page-block sticky-spy">
            <div class="sticky-top">
                <nav id="my-nav" class="navbar">
                    <ul class="nav nav-tabs w-100">
                        <?php $isFirst = true; foreach ($anchor as $id => $tabs): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $isFirst ? 'active' : ''; ?>" href="#<?= $id; ?>">
                                <?= $tabs; ?>
                            </a>
                        </li>
                        <?php $isFirst = false; endforeach; ?>
                    </ul>
                </nav>
            </div>

            <div class="sticky-section" data-bs-spy="scroll" data-bs-target="#my-nav">
                <?php foreach ($anchor ?? [] as $id => $section): ?>
                <section id="<?= htmlspecialchars($id) ?>"
                        data-section="<?= htmlspecialchars($id) ?>">
                    <h5 class="mb-4"><?= htmlspecialchars($section) ?></h5>
                    <?php
                    $configFile = __DIR__ . '/config-' . $id . '.php';
                    if (is_file($configFile)) {
                        include $configFile;
                    }
                    ?>
                </section>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sticky-act sticky-status">
            <button 
                type="button"
                class="btn btn-primary mublo-submit"
                data-target="/admin/settings/update"
                data-callback="settingsSaved"
            >
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>

    </div>
</form>

<script>
var siteConfig = <?= json_encode($siteConfig ?? [], JSON_UNESCAPED_UNICODE) ?>;
var companyConfig = <?= json_encode($companyConfig ?? [], JSON_UNESCAPED_UNICODE) ?>;
var seoConfig = <?= json_encode($seoConfig ?? [], JSON_UNESCAPED_UNICODE) ?>;
var themeConfig = <?= json_encode($themeConfig ?? [], JSON_UNESCAPED_UNICODE) ?>;

document.addEventListener('DOMContentLoaded', function() {
    // 사이트 설정 채우기
    MubloForm.fill(siteConfig, 'formData[site]');
    // 회사 정보 채우기
    MubloForm.fill(companyConfig, 'formData[company]');
    // SEO 설정 채우기 (sns_channels, 이미지 필드 제외 - 별도 처리)
    MubloForm.fill(seoConfig, 'formData[seo]');
    // 테마 설정 채우기
    MubloForm.fill(themeConfig, 'formData[theme]');

    // 이미지 미리보기 초기화
    initImagePreviews();

    // SNS 채널 초기화
    initSnsChannels();
});

// 폼 데이터 채우기 헬퍼
MubloForm.fill = function(data, prefix) {
    if (!data) return;
    Object.keys(data).forEach(function(key) {
        // 별도 처리가 필요한 키 건너뜀 (배열 값은 단일 input에 설정 불가)
        if (key === 'sns_channels') return;
        if (Array.isArray(data[key])) return;

        var inputs = document.querySelectorAll('[name="' + prefix + '[' + key + ']"]');
        if (inputs.length === 0) return;

        inputs.forEach(function(input) {
            if (input.type === 'radio') {
                input.checked = (input.value == data[key]);
            } else if (input.type === 'checkbox') {
                input.checked = (input.value == data[key]);
            } else {
                input.value = data[key] ?? '';
            }
        });
    });
};

// =====================================================
// 이미지 미리보기
// =====================================================
function initImagePreviews() {
    var imageFields = ['logo_pc', 'logo_mobile', 'favicon', 'app_icon', 'og_image'];

    imageFields.forEach(function(field) {
        var fileInput = document.getElementById('file_' + field);
        var hiddenInput = document.getElementById(field);
        var previewContainer = document.getElementById('preview_' + field);

        if (!fileInput || !previewContainer) return;

        // 기존 이미지 미리보기
        var existingUrl = seoConfig ? seoConfig[field] : null;
        if (existingUrl) {
            hiddenInput.value = existingUrl;
            showImagePreview(previewContainer, existingUrl, field, hiddenInput);
        }

        // 파일 선택 시 미리보기
        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (file) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    showImagePreview(previewContainer, e.target.result, field, hiddenInput, true);
                };
                reader.readAsDataURL(file);
            }
        });
    });
}

function showImagePreview(container, src, field, hiddenInput, isNew) {
    var maxHeight = field === 'favicon' ? '32px' : '60px';
    var html = '<div class="d-flex align-items-center gap-2">';
    html += '<img src="' + src + '" alt="Preview" style="max-height: ' + maxHeight + '; max-width: 200px;" class="border rounded">';
    if (!isNew) {
        html += '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeImage(\'' + field + '\')"><i class="bi bi-x-lg"></i></button>';
    }
    html += '</div>';
    container.innerHTML = html;
}

function removeImage(field) {
    var hiddenInput = document.getElementById(field);
    var previewContainer = document.getElementById('preview_' + field);
    var fileInput = document.getElementById('file_' + field);

    if (hiddenInput) hiddenInput.value = '';
    if (previewContainer) previewContainer.innerHTML = '';
    if (fileInput) fileInput.value = '';
}

// =====================================================
// SNS 채널 동적 관리
// =====================================================
function initSnsChannels() {
    var container = document.getElementById('sns-channels-container');
    var addBtn = document.getElementById('btn-add-sns');
    var emptyMsg = document.getElementById('sns-empty-message');

    if (!container || !addBtn) return;

    // 기존 SNS 채널 로드
    if (typeof existingSnsChannels !== 'undefined' && Array.isArray(existingSnsChannels) && existingSnsChannels.length > 0) {
        existingSnsChannels.forEach(function(channel) {
            addSnsRow(channel.type, channel.url);
        });
    } else {
        // 기본 빈 행 1개 추가
        addSnsRow('', '');
    }

    // 추가 버튼
    addBtn.addEventListener('click', function() {
        addSnsRow('', '');
    });

    // 드래그 앤 드롭 순서 변경 — DOM 행 순서가 곧 제출 순서(type[]/url[])라
    // 별도 저장 호출 없이 폼 제출 시 그대로 반영된다.
    if (typeof Sortable !== 'undefined') {
        new Sortable(container, {
            handle: '.drag-handle',
            animation: 150,
        });
    }

    updateEmptyMessage();
}

function addSnsRow(type, url) {
    var container = document.getElementById('sns-channels-container');
    var template = document.getElementById('sns-row-template');

    if (!container || !template) return;

    var clone = template.content.cloneNode(true);
    var row = clone.querySelector('.sns-channel-row');

    // 값 설정
    var selectEl = row.querySelector('.sns-type-select');
    var urlInput = row.querySelector('.sns-url-input');

    if (type) {
        selectEl.value = type;
        updateSnsIcon(selectEl);
    }
    if (url) {
        urlInput.value = url;
    }

    // 이벤트 바인딩
    selectEl.addEventListener('change', function() {
        updateSnsIcon(this);
        updatePlaceholder(this);
    });

    row.querySelector('.btn-remove-sns').addEventListener('click', function() {
        row.remove();
        updateEmptyMessage();
    });

    container.appendChild(clone);
    updateEmptyMessage();
}

function updateSnsIcon(selectEl) {
    var row = selectEl.closest('.sns-channel-row');
    var iconPreview = row.querySelector('.sns-icon-preview i');
    var selectedOption = selectEl.options[selectEl.selectedIndex];

    if (selectedOption && selectedOption.dataset.icon) {
        iconPreview.className = 'bi ' + selectedOption.dataset.icon;
        iconPreview.style.color = selectedOption.dataset.color || '#6c757d';
    } else {
        iconPreview.className = 'bi bi-link-45deg';
        iconPreview.style.color = '#6c757d';
    }
}

function updatePlaceholder(selectEl) {
    var row = selectEl.closest('.sns-channel-row');
    var urlInput = row.querySelector('.sns-url-input');
    var selectedOption = selectEl.options[selectEl.selectedIndex];

    if (selectedOption && selectedOption.dataset.placeholder) {
        urlInput.placeholder = selectedOption.dataset.placeholder;
    } else {
        urlInput.placeholder = 'https://';
    }
}

function updateEmptyMessage() {
    var container = document.getElementById('sns-channels-container');
    var emptyMsg = document.getElementById('sns-empty-message');

    if (!container || !emptyMsg) return;

    var rows = container.querySelectorAll('.sns-channel-row');
    emptyMsg.style.display = rows.length === 0 ? 'block' : 'none';
}

// =====================================================
// 저장 콜백
// =====================================================
MubloRequest.registerCallback('settingsSaved', function(response) {
    if (response.result === 'success') {
        MubloRequest.showToast(response.message || '설정이 저장되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(response.message || '저장에 실패했습니다.', 'error');
    }
});
</script>
