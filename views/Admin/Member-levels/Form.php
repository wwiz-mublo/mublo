<?php
/**
 * 회원 등급 관리 - 생성/수정 폼
 *
 * @var string $pageTitle
 * @var bool $isEdit
 * @var \Mublo\Entity\Member\MemberLevel|null $level
 * @var array $levelTypeOptions
 * @var int|null $memberCount 수정 시 해당 등급 사용 회원 수
 */

// 등급 데이터 추출
$levelId = $level?->getLevelId() ?? 0;
$levelValue = $level?->getLevelValue() ?? '';
$levelName = $level?->getLevelName() ?? '';
$levelType = $level?->getLevelType() ?? 'BASIC';
$isSuper = $level?->isSuper() ?? false;
$isAdmin = $level !== null && method_exists($level, 'canAccessAdmin') ? $level->canAccessAdmin() && !$isSuper : false;
$canOperateDomain = $level?->canOperateDomain() ?? false;

$actionUrl = $isEdit ? "/admin/member-levels/update/{$levelId}" : '/admin/member-levels/store';
?>

<form id="level-form">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>
                <a href="/admin/member-levels">회원 등급 관리</a>
                <i class="bi bi-chevron-right mx-1"></i>
                <?= $isEdit ? '정보 수정' : '등급 등록' ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/member-levels') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-sm btn-primary btn-save-form">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <div class="page-block row">
        <div class="col-lg-8">
            <!-- 기본 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-tag text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- 레벨값 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">레벨값 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="formData[level_value]" id="level-value" class="form-control"
                                       value="<?= $levelValue ?>"
                                       min="1" max="255" required
                                       <?= $isEdit && $isSuper ? 'readonly' : '' ?>>
                                <?php if (!($isEdit && $isSuper)): ?>
                                    <button type="button" class="btn btn-outline-secondary" id="btn-check-value">
                                        중복 확인
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">1~255 사이의 고유한 값</div>
                            <div id="value-check-result" class="mt-1"></div>
                        </div>

                        <!-- 등급명 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">등급명 <span class="text-danger">*</span></label>
                            <input type="text" name="formData[level_name]" class="form-control"
                                   value="<?= htmlspecialchars($levelName) ?>"
                                   placeholder="예: 일반회원, 스태프" required maxlength="50">
                        </div>

                        <!-- 레벨 타입 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">레벨 타입 <span class="text-danger">*</span></label>
                            <select name="formData[level_type]" class="form-select">
                                <?php foreach ($levelTypeOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $levelType === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">등급의 역할 구분용</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 역할 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-person-badge text-pastel-green"></i>
                    <span>역할 설정</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- 최고관리자 -->
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="is-super"
                                       name="formData[is_super]" value="1"
                                       <?= $isSuper ? 'checked' : '' ?>
                                       <?= $isEdit && $isSuper ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="is-super">
                                    <strong>최고관리자</strong>
                                </label>
                            </div>
                            <div class="form-text">전체 시스템 관리 권한</div>
                            <?php if ($isEdit && $isSuper): ?>
                                <input type="hidden" name="formData[is_super]" value="1">
                            <?php endif; ?>
                        </div>

                        <!-- 관리자 모드 접근 -->
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="is-admin"
                                       name="formData[is_admin]" value="1"
                                       <?= $isAdmin ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is-admin">
                                    <strong>관리자 모드 접근</strong>
                                </label>
                            </div>
                            <div class="form-text">관리자 페이지 접근 가능</div>
                        </div>

                        <!-- 도메인 운영 가능 -->
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="can-operate-domain"
                                       name="formData[can_operate_domain]" value="1"
                                       <?= $canOperateDomain ? 'checked' : '' ?>>
                                <label class="form-check-label" for="can-operate-domain">
                                    <strong>도메인 운영 가능</strong>
                                </label>
                            </div>
                            <div class="form-text">하위 사이트 소유자 지정 가능</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="col-lg-4">
            <?php if ($isEdit): ?>
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-info-circle text-pastel-sky"></i>
                        <span>정보</span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">등급 ID</dt>
                            <dd class="col-sm-7"><?= $levelId ?></dd>

                            <dt class="col-sm-5">사용 회원 수</dt>
                            <dd class="col-sm-7">
                                <?php if (isset($memberCount) && $memberCount > 0): ?>
                                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?= number_format($memberCount) ?>명</span>
                                <?php else: ?>
                                    <span class="text-muted">없음</span>
                                <?php endif; ?>
                            </dd>

                            <dt class="col-sm-5">등록일</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($level?->getCreatedAt() ?? '-') ?></dd>

                            <dt class="col-sm-5">수정일</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($level?->getUpdatedAt() ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-question-circle text-pastel-purple"></i>
                    <span>역할 설명</span>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p class="mb-2">
                            <strong class="text-danger">최고관리자</strong><br>
                            전체 시스템을 관리합니다. 모든 권한을 가집니다.
                        </p>
                        <p class="mb-2">
                            <strong class="text-warning">관리자 모드 접근</strong><br>
                            /admin 경로에 접근할 수 있습니다.
                        </p>
                        <p class="mb-0">
                            <strong class="text-info">도메인 운영 가능</strong><br>
                            하위 사이트의 소유자로 지정될 수 있습니다.
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-list-ul text-pastel-orange"></i>
                    <span>레벨 타입 설명</span>
                </div>
                <div class="card-body">
                    <div class="small">
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($levelTypeOptions as $type => $label): ?>
                                <li class="mb-1"><strong><?= $type ?></strong>: <?= htmlspecialchars($label) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('level-form');
    const levelId = <?= $levelId ?>;
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    const actionUrl = <?= json_encode((string) $actionUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // 저장 버튼
    document.querySelector('.btn-save-form').addEventListener('click', function() {
        // 필수 필드 검증
        const levelValueInput = document.getElementById('level-value');
        const levelNameInput = form.querySelector('[name="formData[level_name]"]');

        if (!levelValueInput.value.trim()) {
            MubloRequest.showAlert('레벨값을 입력해주세요.', 'warning');
            levelValueInput.focus();
            return;
        }

        const levelValue = parseInt(levelValueInput.value, 10);
        if (levelValue < 1 || levelValue > 255) {
            MubloRequest.showAlert('레벨값은 1~255 사이여야 합니다.', 'warning');
            levelValueInput.focus();
            return;
        }

        if (!levelNameInput.value.trim()) {
            MubloRequest.showAlert('등급명을 입력해주세요.', 'warning');
            levelNameInput.focus();
            return;
        }

        // FormData로 전송 (FORM PayloadType 사용)
        const formData = new FormData(form);

        MubloRequest.sendRequest({
            method: 'POST',
            url: actionUrl,
            payloadType: MubloRequest.PayloadType.FORM,
            data: formData,
            loading: true
        }).then(response => {
            const redirect = response.data?.redirect || response.redirect || <?= json_encode($listUrl ?? '/admin/member-levels') ?>;
            if (redirect) {
                location.href = redirect;
            } else {
                MubloRequest.showToast(response.message || '저장되었습니다.', 'success');
                location.reload();
            }
        }).catch(err => {
            console.error(err);
        });
    });

    // 레벨값 중복 확인
    const btnCheckValue = document.getElementById('btn-check-value');
    const levelValueInput = document.getElementById('level-value');
    const resultDiv = document.getElementById('value-check-result');

    if (btnCheckValue && levelValueInput) {
        btnCheckValue.addEventListener('click', function() {
            const levelValue = levelValueInput.value.trim();
            if (!levelValue) {
                resultDiv.innerHTML = '<span class="text-danger">레벨값을 입력해주세요.</span>';
                return;
            }

            const value = parseInt(levelValue, 10);
            if (value < 1 || value > 255) {
                resultDiv.innerHTML = '<span class="text-danger">레벨값은 1~255 사이여야 합니다.</span>';
                return;
            }

            MubloRequest.requestJson('/admin/member-levels/check-value', {
                level_value: value,
                exclude_id: isEdit ? levelId : null
            }).then(response => {
                if (response.result === 'success') {
                    resultDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> ' + (response.message || '사용 가능한 레벨값입니다.') + '</span>';
                } else {
                    resultDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (response.message || '이미 사용 중인 레벨값입니다.') + '</span>';
                }
            }).catch(err => {
                resultDiv.innerHTML = '<span class="text-danger">확인 중 오류가 발생했습니다.</span>';
            });
        });
    }

    // 레벨값 입력 시 결과 초기화
    if (levelValueInput && resultDiv) {
        levelValueInput.addEventListener('input', function() {
            resultDiv.innerHTML = '';
        });
    }

    // 최고관리자 체크 시 관리자 모드/도메인 운영 자동 체크
    const isSuperCheckbox = document.getElementById('is-super');
    const isAdminCheckbox = document.getElementById('is-admin');
    const canOperateDomainCheckbox = document.getElementById('can-operate-domain');

    if (isSuperCheckbox) {
        isSuperCheckbox.addEventListener('change', function() {
            if (this.checked) {
                isAdminCheckbox.checked = true;
                canOperateDomainCheckbox.checked = true;
            }
        });
    }
});
</script>
