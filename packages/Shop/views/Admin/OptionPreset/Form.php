<?php
/**
 * 옵션 프리셋 등록/수정
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array|null $preset 프리셋 데이터
 * @var array $options 옵션+값 [{option_name, option_type, is_required, values: [{value_name, extra_price}]}]
 */
?>

<div class="page-title">
    <div class="page-title-text">
        <h3><?= htmlspecialchars($pageTitle) ?></h3>
        <p>상품 옵션 템플릿을 <?= $isEdit ? '수정' : '등록' ?>합니다.</p>
    </div>
    <div class="page-title-actions">
        <a href="/admin/shop/options" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list"></i> 목록
        </a>
        <button type="button" class="btn btn-primary btn-sm mublo-submit"
            data-target="/admin/shop/options/store"
            data-callback="presetSaved">
            <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
        </button>
    </div>
</div>

<form id="presetForm">
    <?php if ($isEdit && !empty($preset['preset_id'])): ?>
        <input type="hidden" name="formData[preset_id]" value="<?= (int) $preset['preset_id'] ?>">
    <?php endif; ?>

    <!-- 프리셋 기본 정보 -->
    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-info-circle text-pastel-blue"></i>
            <span>프리셋 정보</span>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">프리셋명 <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="formData[name]" class="form-control" value="<?= htmlspecialchars($preset['name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">설명</label>
                <div class="col-sm-6">
                    <input type="text" name="formData[description]" class="form-control" value="<?= htmlspecialchars($preset['description'] ?? '') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">옵션 모드</label>
                <div class="col-sm-6">
                    <?php $currentMode = $preset['option_mode'] ?? 'COMBINATION'; ?>
                    <select name="formData[option_mode]" class="form-select" id="optionModeSelect" onchange="PresetForm.onModeChange(this.value)">
                        <option value="COMBINATION" <?= $currentMode === 'COMBINATION' ? 'selected' : '' ?>>조합형 — 옵션값 조합별 재고·가격</option>
                        <option value="SINGLE" <?= $currentMode === 'SINGLE' ? 'selected' : '' ?>>단독형 — 각 옵션값이 개별 재고·가격</option>
                    </select>
                    <div class="form-text">상품에 프리셋 적용 시 옵션 모드가 함께 설정됩니다.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 옵션 구성 -->
    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-sliders text-pastel-green"></i>
            <span>옵션 구성</span>
            <div class="ms-auto">
                <button type="button" class="btn btn-xs btn-outline-primary" onclick="PresetForm.addOption('BASIC')">
                    <i class="bi bi-plus-lg"></i> 기본 옵션 추가
                </button>
                <button type="button" class="btn btn-xs btn-outline-success ms-1" onclick="PresetForm.addOption('EXTRA')">
                    <i class="bi bi-plus-lg"></i> 추가 옵션 추가
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-3">
                <strong>기본 옵션</strong> — 상품의 본질적 선택 (색상, 사이즈 등). 단독형/조합형 모드에서 재고·가격 관리 대상<br>
                <strong>추가 옵션</strong> — 부가 서비스 (각인, 포장 등). 별도 추가금으로 처리
            </div>
            <div id="preset-options">
                <!-- JS 동적 생성 -->
            </div>
            <div id="preset-options-empty" class="text-center py-4 text-muted" style="display:none;">
                옵션을 추가해주세요.
            </div>
        </div>
    </div>

    <!-- 조합 미리보기 (COMBINATION 모드일 때만 표시) -->
    <div class="card mb-4" id="combo-preview-card" style="display:none;">
        <div class="card-hero">
            <i class="bi bi-grid text-pastel-purple"></i>
            <span>조합 미리보기</span>
            <span class="badge text-bg-secondary ms-auto" id="combo-count">0개</span>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-2">
                기본 옵션의 값을 조합하여 생성될 조합 목록입니다. 상품에 적용 시 각 조합별로 재고·가격을 설정할 수 있습니다.
            </div>
            <div id="combo-preview-content">
                <div class="text-center py-3 text-muted">기본 옵션에 값을 추가하면 조합이 표시됩니다.</div>
            </div>
        </div>
    </div>

    <div class="text-center">
        <a href="/admin/shop/options" class="btn btn-secondary">목록</a>
        <button type="button" class="btn btn-primary mublo-submit"
            data-target="/admin/shop/options/store"
            data-callback="presetSaved"><?= $isEdit ? '수정' : '등록' ?></button>
    </div>
</form>

<script>
const PresetForm = {
    optionIndex: 0,

    init(options) {
        if (options && options.length) {
            options.forEach(opt => this.addOption(opt.option_type || 'BASIC', opt));
        }
        this.updateEmptyState();
    },

    addOption(type, data) {
        const idx = this.optionIndex++;
        const typeName = type === 'EXTRA' ? '추가 옵션' : '기본 옵션';
        const badgeClass = type === 'EXTRA' ? 'text-bg-success' : 'text-bg-primary';
        const isRequired = data?.is_required !== undefined ? Number(data.is_required) : 1;

        const html = `
            <div class="border rounded p-3 mb-3" id="option-${idx}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge ${badgeClass}">${typeName}</span>
                        <strong>옵션 #${idx + 1}</strong>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="PresetForm.removeOption(${idx})">
                        <i class="bi bi-trash"></i> 삭제
                    </button>
                </div>
                <input type="hidden" name="options[${idx}][option_type]" value="${type}">
                <div class="row g-2 mb-2">
                    <div class="col-md-5">
                        <label class="form-label small">옵션명 <span class="text-danger">*</span></label>
                        <input type="text" name="options[${idx}][option_name]" class="form-control form-control-sm"
                            placeholder="${type === 'EXTRA' ? '예: 각인, 포장' : '예: 색상, 사이즈'}"
                            value="${this.escapeHtml(data?.option_name || '')}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">필수 여부</label>
                        <select name="options[${idx}][is_required]" class="form-select form-select-sm">
                            <option value="1" ${isRequired ? 'selected' : ''}>필수</option>
                            <option value="0" ${!isRequired ? 'selected' : ''}>선택</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">정렬</label>
                        <input type="number" name="options[${idx}][sort_order]" class="form-control form-control-sm"
                            value="${data?.sort_order ?? idx}" min="0">
                    </div>
                </div>
                <label class="form-label small mt-1">옵션 값</label>
                <div id="values-${idx}"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="PresetForm.addValue(${idx})">
                    <i class="bi bi-plus"></i> 값 추가
                </button>
            </div>`;

        document.getElementById('preset-options').insertAdjacentHTML('beforeend', html);

        // 옵션명 변경 시 조합 미리보기 갱신
        document.querySelector(`#option-${idx} input[name*="[option_name]"]`)
            ?.addEventListener('input', () => this.updateComboPreview());

        if (data?.values && data.values.length) {
            data.values.forEach(v => this.addValue(idx, v));
        } else {
            this.addValue(idx);
        }
        this.updateEmptyState();
        this.updateComboPreview();
    },

    removeOption(idx) {
        document.getElementById('option-' + idx)?.remove();
        this.updateEmptyState();
        this.updateComboPreview();
    },

    addValue(optIdx, data) {
        const container = document.getElementById('values-' + optIdx);
        const vIdx = container.children.length;
        const html = `
            <div class="row g-2 mb-1 align-items-center">
                <div class="col-md-5">
                    <input type="text" name="options[${optIdx}][values][${vIdx}][value_name]"
                        class="form-control form-control-sm" placeholder="값 (예: 빨강, XL)"
                        value="${this.escapeHtml(data?.value_name || '')}"
                        oninput="PresetForm.updateComboPreview()">
                </div>
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <input type="number" name="options[${optIdx}][values][${vIdx}][extra_price]"
                            class="form-control form-control-sm" placeholder="추가금액"
                            value="${data?.extra_price ?? 0}">
                        <span class="input-group-text">원</span>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-outline-danger"
                        onclick="this.closest('.row').remove(); PresetForm.updateComboPreview()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
    },

    updateEmptyState() {
        const hasOptions = document.getElementById('preset-options').children.length > 0;
        document.getElementById('preset-options-empty').style.display = hasOptions ? 'none' : 'block';
    },

    onModeChange(mode) {
        const card = document.getElementById('combo-preview-card');
        card.style.display = mode === 'COMBINATION' ? 'block' : 'none';
        if (mode === 'COMBINATION') {
            this.updateComboPreview();
        }
    },

    updateComboPreview() {
        const mode = document.getElementById('optionModeSelect').value;
        if (mode !== 'COMBINATION') return;

        const { names, valueSets } = this.getBasicOptionValues();
        const content = document.getElementById('combo-preview-content');
        const countBadge = document.getElementById('combo-count');

        if (names.length === 0 || valueSets.some(v => v.length === 0)) {
            content.innerHTML = '<div class="text-center py-3 text-muted">기본 옵션에 값을 추가하면 조합이 표시됩니다.</div>';
            countBadge.textContent = '0개';
            return;
        }

        const combos = this.cartesianProduct(valueSets);
        countBadge.textContent = combos.length + '개';

        if (combos.length > 100) {
            content.innerHTML = `<div class="alert alert-warning mb-0">조합이 ${combos.length}개로 너무 많습니다. 옵션 값을 줄여주세요.</div>`;
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-bordered table-readable mb-0"><thead><tr class="table-tertiary">';
        names.forEach(n => { html += `<th>${this.escapeHtml(n)}</th>`; });
        html += '<th>조합키</th></tr></thead><tbody>';

        combos.forEach(combo => {
            html += '<tr>';
            combo.forEach(v => { html += `<td>${this.escapeHtml(v)}</td>`; });
            html += `<td class="text-muted">${this.escapeHtml(combo.join('/'))}</td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        content.innerHTML = html;
    },

    getBasicOptionValues() {
        const names = [];
        const valueSets = [];
        const container = document.getElementById('preset-options');

        container.querySelectorAll('[id^="option-"]').forEach(optEl => {
            const typeInput = optEl.querySelector('input[name*="[option_type]"]');
            if (!typeInput || typeInput.value !== 'BASIC') return;

            const nameInput = optEl.querySelector('input[name*="[option_name]"]');
            names.push(nameInput?.value || '');

            const values = [];
            const valContainer = optEl.querySelector('[id^="values-"]');
            if (valContainer) {
                valContainer.querySelectorAll('input[name*="[value_name]"]').forEach(vi => {
                    const v = vi.value.trim();
                    if (v) values.push(v);
                });
            }
            valueSets.push(values);
        });

        return { names, valueSets };
    },

    cartesianProduct(sets) {
        let result = [[]];
        for (const set of sets) {
            const temp = [];
            for (const existing of result) {
                for (const item of set) {
                    temp.push([...existing, item]);
                }
            }
            result = temp;
        }
        return result;
    },

    resetForm() {
        // 폼 필드 초기화
        const form = document.getElementById('presetForm');
        form.querySelector('input[name="formData[name]"]').value = '';
        form.querySelector('input[name="formData[description]"]').value = '';
        document.getElementById('optionModeSelect').value = 'SINGLE';

        // 동적 옵션 전체 제거
        document.getElementById('preset-options').innerHTML = '';
        this.optionIndex = 0;
        this.updateEmptyState();
        this.onModeChange('SINGLE');
    },

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};

// 수정 시 기존 옵션 로드
<?php if ($isEdit && !empty($options)): ?>
PresetForm.init(<?= json_encode($options, JSON_UNESCAPED_UNICODE) ?>);
<?php else: ?>
PresetForm.updateEmptyState();
<?php endif; ?>

// 현재 모드에 따라 조합 미리보기 표시
PresetForm.onModeChange(document.getElementById('optionModeSelect').value);

// 저장 콜백
MubloRequest.registerCallback('presetSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        <?php if ($isEdit): ?>
        // 수정 모드: 목록으로 이동
        location.href = response.data?.redirect || '/admin/shop/options';
        <?php else: ?>
        // 등록 모드: 폼 초기화하여 계속 등록
        PresetForm.resetForm();
        <?php endif; ?>
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});
</script>
