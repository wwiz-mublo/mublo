<?php
/**
 * Admin Member Fields - Form
 *
 * 회원 추가 필드 생성/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var array|null $field 필드 정보 (수정 시)
 * @var array $fieldTypeOptions 필드 타입 옵션
 */

$isEdit = !empty($field);
$fieldId = $field['field_id'] ?? 0;
?>
<form id="field-form">
    <input type="hidden" name="formData[field_id]" value="<?= $fieldId ?>">

    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle ?? '필드 관리') ?></h3>
                <p>
                    <a href="/admin/member-field">회원 추가 필드 관리</a>
                    <i class="bi bi-chevron-right mx-1"></i>
                    <?= $isEdit ? '수정' : '추가' ?>
                </p>
            </div>
            <div class="page-title-actions">
                <a href="/admin/member-field" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button" class="btn btn-sm btn-primary" id="btn-save">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <!-- 폼 내용 -->
        <div class="page-block row">
            <!-- 기본 정보 -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-info-circle text-pastel-blue"></i>
                        <span>기본 정보</span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    필드명 <span class="text-danger">*</span>
                                </label>
                                <input type="text"
                                       name="formData[field_name]"
                                       id="field-name-input"
                                       class="form-control"
                                       value="<?= htmlspecialchars($field['field_name'] ?? '') ?>"
                                       placeholder="예: phone, address"
                                       pattern="^[a-z][a-z0-9_]*$"
                                       required
                                       <?= $isEdit ? 'readonly' : '' ?>>
                                <div class="form-text">영문 소문자, 숫자, 언더스코어만 사용 (수정 불가)</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    필드 라벨 <span class="text-danger">*</span>
                                </label>
                                <input type="text"
                                       name="formData[field_label]"
                                       class="form-control"
                                       value="<?= htmlspecialchars($field['field_label'] ?? '') ?>"
                                       placeholder="예: 전화번호, 주소"
                                       required>
                                <div class="form-text">사용자에게 표시되는 이름</div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">필드 타입</label>
                                <select name="formData[field_type]" class="form-select" id="field-type">
                                    <?php foreach ($fieldTypeOptions as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>"
                                            <?= ($field['field_type'] ?? 'text') === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="address-type-notice" class="form-text text-info" style="display: none;">
                                    <i class="bi bi-info-circle"></i>
                                    주소 필드는 우편번호 검색 API를 사용합니다. 검색 활성화 시 우편번호 기준으로 검색됩니다.
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">검증 규칙 (정규식)</label>
                                <input type="text"
                                       name="formData[validation_rule]"
                                       class="form-control"
                                       value="<?= htmlspecialchars($field['validation_rule'] ?? '') ?>"
                                       placeholder="예: ^[0-9]{2,3}-[0-9]{3,4}-[0-9]{4}$">
                                <div class="form-text">비어있으면 타입 기본 검증만 적용</div>
                            </div>
                        </div>

                        <!-- 선택형 필드 옵션 (select, radio, checkbox) -->
                        <div id="field-options-container" class="mb-3" style="display: none;">
                            <label class="form-label">선택 항목</label>
                            <div id="field-options-list">
                                <!-- JS로 동적 생성 -->
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add-option-btn">
                                <i class="bi bi-plus"></i> 항목 추가
                            </button>
                            <div class="form-text">선택형 필드의 선택 항목을 입력하세요.</div>
                        </div>

                        <!-- 파일 타입 설정 (file) -->
                        <div id="file-config-container" class="mb-3" style="display: none;">
                            <label class="form-label">파일 업로드 설정</label>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label form-label-sm">최대 파일 크기 (MB)</label>
                                    <?php $config = json_decode($field['field_config'] ?? '{}', true) ?: []; ?>
                                    <input type="number"
                                           id="file-max-size"
                                           class="form-control"
                                           value="<?= htmlspecialchars($config['max_size'] ?? '5') ?>"
                                           min="1" max="100" step="1">
                                    <div class="form-text">기본값: 5MB</div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label form-label-sm">허용 확장자</label>
                                    <input type="text"
                                           id="file-allowed-ext"
                                           class="form-control"
                                           value="<?= htmlspecialchars($config['allowed_ext'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip') ?>"
                                           placeholder="jpg,png,pdf">
                                    <div class="form-text">콤마로 구분 (비우면 기본 확장자 적용)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 설정 -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-eye text-pastel-green"></i>
                        <span>표시 설정</span>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="formData[is_required]" value="0">
                            <input type="checkbox"
                                   name="formData[is_required]"
                                   class="form-check-input"
                                   id="is_required"
                                   value="1"
                                   <?= ($field['is_required'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_required">
                                필수 입력
                            </label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="formData[is_visible_signup]" value="0">
                            <input type="checkbox"
                                   name="formData[is_visible_signup]"
                                   class="form-check-input"
                                   id="is_visible_signup"
                                   value="1"
                                   <?= ($field['is_visible_signup'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_visible_signup">
                                회원가입 시 표시
                            </label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="formData[is_visible_profile]" value="0">
                            <input type="checkbox"
                                   name="formData[is_visible_profile]"
                                   class="form-check-input"
                                   id="is_visible_profile"
                                   value="1"
                                   <?= ($field['is_visible_profile'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_visible_profile">
                                프로필 수정 시 표시
                            </label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="formData[is_visible_list]" value="0">
                            <input type="checkbox"
                                   name="formData[is_visible_list]"
                                   class="form-check-input"
                                   id="is_visible_list"
                                   value="1"
                                   <?= ($field['is_visible_list'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_visible_list">
                                회원 목록에 표시
                            </label>
                        </div>
                        <hr class="my-3">
                        <div class="mb-2">
                            <div class="form-check form-switch">
                                <input type="hidden" name="formData[is_admin_only]" value="0">
                                <input type="checkbox"
                                    name="formData[is_admin_only]"
                                    class="form-check-input"
                                    id="is_admin_only"
                                    value="1"
                                    <?= ($field['is_admin_only'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_admin_only">
                                    관리자 전용
                                </label>
                            </div>
                            <div class="form-text">사용자 페이지(가입/프로필)에서 숨김</div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-shield-lock text-pastel-purple"></i>
                        <span>보안 설정</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <div class="form-check form-switch">
                                <input type="hidden" name="formData[is_encrypted]" value="0">
                                <input type="checkbox"
                                       name="formData[is_encrypted]"
                                       class="form-check-input"
                                       id="is_encrypted"
                                       value="1"
                                       <?= ($field['is_encrypted'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_encrypted">
                                    <i class="bi bi-lock-fill text-primary"></i> 암호화 저장
                                </label>
                            </div>
                            <div class="form-text">개인정보(이메일, 전화번호 등)에 권장</div>
                        </div>
                        <div class="mb-2">
                            <div class="form-check form-switch">
                                <input type="hidden" name="formData[is_searched]" value="0">
                                <input type="checkbox"
                                       name="formData[is_searched]"
                                       class="form-check-input"
                                       id="is_searched"
                                       value="1"
                                       <?= ($field['is_searched'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_searched">
                                    <i class="bi bi-search text-info"></i> 검색 가능
                                </label>
                            </div>
                            <div class="form-text">관리자 회원 검색에서 사용</div>
                        </div>
                        <div class="mb-2">
                            <div class="form-check form-switch">
                                <input type="hidden" name="formData[is_unique]" value="0">
                                <input type="checkbox"
                                    name="formData[is_unique]"
                                    class="form-check-input"
                                    id="is_unique"
                                    value="1"
                                    <?= ($field['is_unique'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_unique">
                                    <i class="bi bi-fingerprint text-warning"></i> 중복 불가
                                </label>
                            </div>
                            <div class="form-text">회원가입/수정 시 중복 체크 버튼 표시</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
var existingOptions = <?= json_encode($field['field_options'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

document.addEventListener('DOMContentLoaded', function() {
    var fieldTypeSelect = document.getElementById('field-type');
    var optionsContainer = document.getElementById('field-options-container');
    var optionsList = document.getElementById('field-options-list');
    var addOptionBtn = document.getElementById('add-option-btn');

    var addressNotice = document.getElementById('address-type-notice');
    var fileConfigContainer = document.getElementById('file-config-container');
    var fieldNameInput = document.getElementById('field-name-input');
    var isEditMode = <?= $isEdit ? 'true' : 'false' ?>;

    var validationInput = document.querySelector('input[name="formData[validation_rule]"]');

    // 보안 설정 체크박스들 (file 타입일 때 비활성화)
    var securityCheckboxes = {
        encrypted: document.getElementById('is_encrypted'),
        searched: document.getElementById('is_searched'),
        unique: document.getElementById('is_unique'),
    };

    // 타입별 기본 검증 정규식
    var typeValidationRules = {
        'email': '^[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+\\.[a-zA-Z]{2,}$',
        'tel': '^[0-9]{2,3}-[0-9]{3,4}-[0-9]{4}$'
    };

    // 허용 확장자 기본값 (file / avatar)
    var FILE_DEFAULT_EXT = 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip';
    var AVATAR_DEFAULT_EXT = 'jpg,jpeg,png,gif,webp';

    // 필드 타입 변경 시 옵션 입력란/안내문 표시/숨김
    function toggleOptionsContainer() {
        var type = fieldTypeSelect.value;
        if (['select', 'radio', 'checkbox'].includes(type)) {
            optionsContainer.style.display = 'block';
        } else {
            optionsContainer.style.display = 'none';
        }

        // 주소 타입 안내 메시지
        if (type === 'address') {
            addressNotice.style.display = 'block';
        } else {
            addressNotice.style.display = 'none';
        }

        // 아바타는 도메인당 1개 예약 필드 → 필드명을 'avatar'로 고정(생성 시)
        if (fieldNameInput && !isEditMode) {
            if (type === 'avatar') {
                fieldNameInput.dataset.prevName = fieldNameInput.dataset.prevName ?? fieldNameInput.value;
                fieldNameInput.value = 'avatar';
                fieldNameInput.readOnly = true;
            } else if (fieldNameInput.readOnly && fieldNameInput.value === 'avatar') {
                fieldNameInput.value = fieldNameInput.dataset.prevName || '';
                fieldNameInput.readOnly = false;
                delete fieldNameInput.dataset.prevName;
            }
        }

        // 파일/아바타 타입 설정 (avatar는 file 업로드 파이프라인 공용)
        if (type === 'file' || type === 'avatar') {
            fileConfigContainer.style.display = 'block';
            // avatar 선택 시 이미지 확장자를 기본값으로 채움(사용자 편집 값은 보존)
            if (type === 'avatar') {
                var extInput = document.getElementById('file-allowed-ext');
                if (extInput && (extInput.value.trim() === '' || extInput.value.trim() === FILE_DEFAULT_EXT)) {
                    extInput.value = AVATAR_DEFAULT_EXT;
                }
            }
            // 파일/아바타는 암호화/검색/유일성 비활성
            Object.values(securityCheckboxes).forEach(function(cb) {
                if (cb) { cb.checked = false; cb.disabled = true; }
            });
            if (validationInput) validationInput.disabled = true;
        } else {
            fileConfigContainer.style.display = 'none';
            Object.values(securityCheckboxes).forEach(function(cb) {
                if (cb) cb.disabled = false;
            });
            if (validationInput) validationInput.disabled = false;
        }
    }

    // 타입 변경 시 검증 정규식 자동 입력
    function autoFillValidation() {
        var type = fieldTypeSelect.value;
        var currentVal = validationInput.value.trim();

        // 비어있거나 다른 타입의 자동 정규식이면 교체
        var isAutoFilled = !currentVal;
        Object.values(typeValidationRules).forEach(function(rule) {
            if (currentVal === rule) isAutoFilled = true;
        });

        if (isAutoFilled && typeValidationRules[type]) {
            validationInput.value = typeValidationRules[type];
        } else if (isAutoFilled) {
            validationInput.value = '';
        }
    }

    fieldTypeSelect.addEventListener('change', function() {
        toggleOptionsContainer();
        autoFillValidation();
    });
    toggleOptionsContainer();

    // 옵션 항목 추가
    function addOptionRow(value = '', label = '') {
        var row = document.createElement('div');
        row.className = 'input-group mb-2 option-row';
        row.innerHTML = `
            <input type="text" class="form-control option-value" placeholder="값" value="${escapeHtml(value)}">
            <input type="text" class="form-control option-label" placeholder="표시 라벨" value="${escapeHtml(label)}">
            <button type="button" class="btn btn-outline-danger remove-option-btn">
                <i class="bi bi-x"></i>
            </button>
        `;
        optionsList.appendChild(row);

        row.querySelector('.remove-option-btn').addEventListener('click', function() {
            row.remove();
        });
    }

    addOptionBtn.addEventListener('click', function() {
        addOptionRow();
    });

    // 기존 옵션 로드
    if (existingOptions && Array.isArray(existingOptions)) {
        existingOptions.forEach(function(opt) {
            addOptionRow(opt.value || '', opt.label || '');
        });
    } else if (existingOptions && typeof existingOptions === 'object') {
        Object.keys(existingOptions).forEach(function(key) {
            addOptionRow(key, existingOptions[key]);
        });
    }

    // 옵션이 없으면 기본 2개 추가
    if (optionsList.children.length === 0 && ['select', 'radio', 'checkbox'].includes(fieldTypeSelect.value)) {
        addOptionRow();
        addOptionRow();
    }

    // 저장 버튼 클릭
    document.getElementById('btn-save').addEventListener('click', function() {
        var form = document.getElementById('field-form');
        var fd = new FormData(form);
        var formData = {};

        // formData[key] 형식에서 key 추출
        fd.forEach(function(value, key) {
            var match = key.match(/^formData\[(.+)\]$/);
            if (match) {
                formData[match[1]] = value;
            }
        });

        // 선택형 필드 옵션 수집
        if (['select', 'radio', 'checkbox'].includes(formData.field_type)) {
            var options = [];
            document.querySelectorAll('.option-row').forEach(function(row) {
                var value = row.querySelector('.option-value').value.trim();
                var label = row.querySelector('.option-label').value.trim();
                if (value) {
                    options.push({ value: value, label: label || value });
                }
            });
            formData.field_options = options;
        }

        // 파일/아바타 타입 설정 수집
        if (formData.field_type === 'file' || formData.field_type === 'avatar') {
            var maxSize = document.getElementById('file-max-size').value || '5';
            var allowedExt = document.getElementById('file-allowed-ext').value.trim();
            formData.field_config = {
                max_size: parseInt(maxSize, 10),
                allowed_ext: allowedExt
            };
        }

        // MubloRequest를 통한 저장 요청
        MubloRequest.requestJson('/admin/member-field/store', { formData: formData }, { loading: true })
            .then(function(result) {
                if (result.result === 'success') {
                    MubloRequest.showToast(result.message || '저장되었습니다.', 'success');
                    setTimeout(function() { location.href = '/admin/member-field'; }, 800);
                } else {
                    MubloRequest.showAlert(result.message || '저장에 실패했습니다.', 'error');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
            });
    });
});

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
