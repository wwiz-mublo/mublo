<?php
/**
 * 약관/정책 관리 - 생성/수정 폼
 *
 * @var string $pageTitle
 * @var bool $isEdit
 * @var \Mublo\Entity\Member\Policy|null $policy
 * @var array $policyTypeOptions
 */
?>
<?= editor_css() ?>
<?php

// 정책 데이터 추출
$policyId = $policy?->getPolicyId() ?? 0;
$policyType = $policy?->getPolicyType() ?? 'custom';
$policyTitle = $policy?->getPolicyTitle() ?? '';
$policyContent = $policy?->getPolicyContent() ?? '';
$policyVersion = $policy?->getPolicyVersion() ?? '1.0';
$slug = $policy?->getSlug() ?? '';
$isRequired = $policy?->isRequired() ?? true;
$isActive = $policy?->isActive() ?? true;
$sortOrder = $policy?->getSortOrder() ?? 0;
$showInRegister = $policy?->showInRegister() ?? true;

$actionUrl = $isEdit ? "/admin/policy/update/{$policyId}" : '/admin/policy/store';
?>

<form id="policy-form">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>
                <a href="/admin/policy">약관/정책 관리</a>
                <i class="bi bi-chevron-right mx-1"></i>
                <?= $isEdit ? '정보 수정' : '정책 등록' ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/policy') ?>" class="btn btn-sm btn-outline-secondary">
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
                    <i class="bi bi-file-text text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- 정책 타입 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">정책 타입 <span class="text-danger">*</span></label>
                            <select name="formData[policy_type]" class="form-select" id="policy-type">
                                <?php foreach ($policyTypeOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $policyType === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">정책의 유형을 선택합니다.</div>
                        </div>

                        <!-- 버전 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">버전</label>
                            <input type="text" name="formData[version]" class="form-control"
                                   value="<?= htmlspecialchars($policyVersion) ?>"
                                   placeholder="1.0" maxlength="20">
                            <div class="form-text">정책 버전 (예: 1.0, 2.1)</div>
                        </div>

                        <!-- 정렬 순서 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">정렬 순서</label>
                            <input type="number" name="formData[sort_order]" class="form-control"
                                   value="<?= $sortOrder ?>" min="0">
                            <div class="form-text">작을수록 먼저 표시</div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- 정책 제목 -->
                        <div class="col-md-8 mb-3">
                            <label class="form-label">정책 제목 <span class="text-danger">*</span></label>
                            <input type="text" name="formData[title]" id="policy-title" class="form-control"
                                   value="<?= htmlspecialchars($policyTitle) ?>"
                                   placeholder="예: 이용약관, 개인정보처리방침" required maxlength="200">
                        </div>

                        <!-- 슬러그 -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label">슬러그</label>
                            <div class="input-group">
                                <input type="text" name="formData[slug]" id="policy-slug" class="form-control"
                                       value="<?= htmlspecialchars($slug) ?>"
                                       placeholder="terms-of-service" maxlength="50"
                                       pattern="[a-z0-9\-]+">
                                <button type="button" class="btn btn-outline-secondary" id="btn-check-slug">
                                    확인
                                </button>
                            </div>
                            <div class="form-text">URL 식별자 (영문 소문자, 숫자, 하이픈만)</div>
                            <div id="slug-check-result" class="mt-1"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 정책 내용 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-pencil text-pastel-green"></i>
                    <span>정책 내용 <span class="text-danger">*</span></span>
                </div>
                <div class="card-body">
                    <?= editor_html('policy-content', $policyContent, [
                        'name' => 'formData[content]',
                        'height' => 500,
                        'toolbar' => 'full',
                        'placeholder' => '정책/약관 내용을 입력하세요',
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- 동의 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-check-square text-pastel-purple"></i>
                    <span>동의 설정</span>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="is-required"
                                   name="formData[is_required]" value="1"
                                   <?= $isRequired ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is-required">
                                <strong>필수 동의</strong>
                            </label>
                        </div>
                        <div class="form-text">회원가입 시 반드시 동의해야 함</div>
                    </div>

                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="is-active"
                                   name="formData[is_active]" value="1"
                                   <?= $isActive ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is-active">
                                <strong>활성화</strong>
                            </label>
                        </div>
                        <div class="form-text">비활성화하면 사용자에게 표시되지 않음</div>
                    </div>
                </div>
            </div>

            <!-- 회원가입 출력 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-person-plus text-pastel-sky"></i>
                    <span>회원가입 출력</span>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input type="hidden" name="formData[show_in_register]" value="0">
                            <input type="checkbox" class="form-check-input" id="show-in-register"
                                   name="formData[show_in_register]" value="1"
                                   <?= $showInRegister ? 'checked' : '' ?>>
                            <label class="form-check-label" for="show-in-register">
                                <strong>회원가입 시 출력</strong>
                            </label>
                        </div>
                        <div class="form-text">회원가입 폼에서 동의 약관으로 표시</div>
                    </div>

                    <?php if ($isEdit && $slug): ?>
                    <div class="mt-3">
                        <label class="form-label">약관 URL</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                            <input type="text" class="form-control" readonly
                                   value="/policy/view/<?= htmlspecialchars($slug) ?>"
                                   id="policy-url-input">
                            <button type="button" class="btn btn-outline-secondary" id="copy-policy-url">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <div class="form-text">이 URL로 약관 내용을 확인할 수 있습니다.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 치환 변수 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-braces text-pastel-orange"></i>
                    <span>치환 변수</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">클릭하면 에디터에 삽입됩니다.</p>
                    <div class="d-flex flex-wrap gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#회사명}" title="회사명">{#회사명}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#대표자}" title="대표자명">{#대표자}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#사이트명}" title="사이트명">{#사이트명}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#홈페이지}" title="홈페이지 주소">{#홈페이지}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#전화번호}" title="대표 전화번호">{#전화번호}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#이메일}" title="대표 이메일">{#이메일}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#책임자}" title="개인정보 보호책임자">{#책임자}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#등록일자}" title="약관 등록일">{#등록일자}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary insert-var" data-var="{#적용일자}" title="약관 적용일">{#적용일자}</button>
                    </div>
                    <hr class="my-3">
                    <div class="small text-muted">
                        <p class="mb-1"><strong>변수 설명:</strong></p>
                        <ul class="mb-0 ps-3" style="font-size: 12px;">
                            <li><code>{#회사명}</code> 회사/상호명</li>
                            <li><code>{#대표자}</code> 대표자명</li>
                            <li><code>{#책임자}</code> 개인정보 보호책임자</li>
                            <li><code>{#사이트명}</code> 사이트 이름</li>
                            <li><code>{#홈페이지}</code> 홈페이지 URL</li>
                            <li><code>{#전화번호}</code> 대표 전화번호</li>
                            <li><code>{#이메일}</code> 대표 이메일</li>
                            <li><code>{#등록일자}</code> 약관 등록일</li>
                            <li><code>{#적용일자}</code> 약관 시행일</li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php if ($isEdit): ?>
                <!-- 정보 -->
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-info-circle text-pastel-slate"></i>
                        <span>정보</span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">정책 ID</dt>
                            <dd class="col-sm-7"><?= $policyId ?></dd>

                            <dt class="col-sm-5">현재 버전</dt>
                            <dd class="col-sm-7">
                                <span class="badge bg-primary"><?= htmlspecialchars($policyVersion) ?></span>
                            </dd>

                            <dt class="col-sm-5">등록일</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($policy?->getCreatedAt() ?? '-') ?></dd>

                            <dt class="col-sm-5">수정일</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($policy?->getUpdatedAt() ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 도움말 -->
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-question-circle text-pastel-slate"></i>
                    <span>정책 타입 설명</span>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p class="mb-2">
                            <strong class="text-primary">이용약관</strong><br>
                            서비스 이용에 관한 기본 약관
                        </p>
                        <p class="mb-2">
                            <strong class="text-danger">개인정보처리방침</strong><br>
                            개인정보 수집/이용에 관한 방침
                        </p>
                        <p class="mb-2">
                            <strong class="text-success">마케팅 수신 동의</strong><br>
                            광고/마케팅 정보 수신 동의
                        </p>
                        <p class="mb-2">
                            <strong class="text-info">위치정보 이용약관</strong><br>
                            위치기반 서비스 이용 약관
                        </p>
                        <p class="mb-0">
                            <strong class="text-secondary">커스텀</strong><br>
                            기타 사용자 정의 정책
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('policy-form');
    const policyId = <?= $policyId ?>;
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    const actionUrl = <?= json_encode((string) $actionUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const copyPolicyUrl = document.getElementById('copy-policy-url');

    if (copyPolicyUrl) {
        copyPolicyUrl.addEventListener('click', function() {
            const input = document.getElementById('policy-url-input');
            if (!input) return;
            navigator.clipboard.writeText(location.origin + input.value)
                .then(() => MubloRequest.showToast('URL이 복사되었습니다.', 'info'));
        });
    }

    // =========================================================================
    // 저장 버튼
    // =========================================================================
    document.querySelector('.btn-save-form').addEventListener('click', function() {
        // 필수 필드 검증
        const titleInput = document.getElementById('policy-title');
        const contentInput = document.getElementById('policy-content');

        if (!titleInput.value.trim()) {
            MubloRequest.showAlert('정책 제목을 입력해주세요.', 'warning');
            titleInput.focus();
            return;
        }

        if (!contentInput.value.trim()) {
            MubloRequest.showAlert('정책 내용을 입력해주세요.', 'warning');
            contentInput.focus();
            return;
        }

        // FormData로 전송
        const formData = new FormData(form);

        MubloRequest.sendRequest({
            method: 'POST',
            url: actionUrl,
            payloadType: MubloRequest.PayloadType.FORM,
            data: formData,
            loading: true
        }).then(response => {
            if (response.result === 'success') {
                const redirect = response.data?.redirect || response.redirect || <?= json_encode($listUrl ?? '/admin/policy') ?>;
                if (redirect) {
                    location.href = redirect;
                } else {
                    MubloRequest.showToast(response.message || '저장되었습니다.', 'success');
                    location.reload();
                }
            } else {
                MubloRequest.showAlert(response.message || '저장에 실패했습니다.', 'error');
            }
        }).catch(err => {
            MubloRequest.showAlert('저장 중 오류가 발생했습니다.', 'error');
            console.error(err);
        });
    });

    // =========================================================================
    // 슬러그 중복 확인
    // =========================================================================
    const btnCheckSlug = document.getElementById('btn-check-slug');
    const slugInput = document.getElementById('policy-slug');
    const slugResultDiv = document.getElementById('slug-check-result');

    if (btnCheckSlug && slugInput) {
        btnCheckSlug.addEventListener('click', function() {
            const slug = slugInput.value.trim();
            if (!slug) {
                slugResultDiv.innerHTML = '<span class="text-muted small">슬러그를 입력해주세요.</span>';
                return;
            }

            // 형식 검증
            if (!/^[a-z0-9\-]+$/.test(slug)) {
                slugResultDiv.innerHTML = '<span class="text-danger small"><i class="bi bi-x-circle"></i> 영문 소문자, 숫자, 하이픈만 사용 가능합니다.</span>';
                return;
            }

            MubloRequest.requestJson('/admin/policy/check-slug', {
                slug: slug,
                exclude_id: isEdit ? policyId : null
            }).then(response => {
                if (response.result === 'success') {
                    slugResultDiv.innerHTML = '<span class="text-success small"><i class="bi bi-check-circle"></i> ' + (response.message || '사용 가능한 슬러그입니다.') + '</span>';
                } else {
                    slugResultDiv.innerHTML = '<span class="text-danger small"><i class="bi bi-x-circle"></i> ' + (response.message || '이미 사용 중인 슬러그입니다.') + '</span>';
                }
            }).catch(err => {
                slugResultDiv.innerHTML = '<span class="text-danger small">확인 중 오류가 발생했습니다.</span>';
            });
        });

        // 슬러그 입력 시 결과 초기화
        slugInput.addEventListener('input', function() {
            slugResultDiv.innerHTML = '';
        });
    }

    // =========================================================================
    // 제목으로 슬러그 자동 생성
    // =========================================================================
    const titleInput = document.getElementById('policy-title');
    if (titleInput && slugInput && !isEdit) {
        titleInput.addEventListener('blur', function() {
            // 슬러그가 비어있을 때만 자동 생성
            if (!slugInput.value.trim()) {
                let slug = titleInput.value.toLowerCase().trim();
                // 영문/숫자만 남기고 공백은 하이픈으로
                slug = slug.replace(/[^a-z0-9\s\-]/gi, '');
                slug = slug.replace(/[\s_]+/g, '-');
                slug = slug.replace(/-+/g, '-');
                slug = slug.replace(/^-|-$/g, '');
                slugInput.value = slug;
            }
        });
    }

    // =========================================================================
    // 정책 타입 변경 시 슬러그/제목 자동 제안
    // =========================================================================
    const policyTypeSelect = document.getElementById('policy-type');
    const typeDefaults = {
        'terms': { title: '이용약관', slug: 'terms-of-service' },
        'privacy': { title: '개인정보처리방침', slug: 'privacy-policy' },
        'marketing': { title: '마케팅 정보 수신 동의', slug: 'marketing-agreement' },
        'location': { title: '위치정보 이용약관', slug: 'location-terms' },
        'custom': { title: '', slug: '' }
    };

    if (policyTypeSelect && !isEdit) {
        policyTypeSelect.addEventListener('change', function() {
            const type = this.value;
            const defaults = typeDefaults[type] || {};

            // 제목이 비어있으면 기본값 설정
            if (!titleInput.value.trim() && defaults.title) {
                titleInput.value = defaults.title;
            }
            // 슬러그가 비어있으면 기본값 설정
            if (!slugInput.value.trim() && defaults.slug) {
                slugInput.value = defaults.slug;
            }
        });
    }

    // =========================================================================
    // 치환 변수 삽입 (MubloEditor 사용)
    // =========================================================================
    document.querySelectorAll('.insert-var').forEach(btn => {
        btn.addEventListener('click', function() {
            const varText = this.dataset.var;
            const editorInstance = MubloEditor.get('policy-content');

            if (editorInstance) {
                // MubloEditor에 텍스트 삽입
                editorInstance.insertContent(varText);
            } else {
                // 폴백: 일반 textarea 처리
                const textarea = document.getElementById('policy-content');
                if (textarea) {
                    const start = textarea.selectionStart;
                    const end = textarea.selectionEnd;
                    const text = textarea.value;
                    textarea.value = text.substring(0, start) + varText + text.substring(end);
                    const newPos = start + varText.length;
                    textarea.setSelectionRange(newPos, newPos);
                    textarea.focus();
                }
            }
        });
    });
});
</script>

<?= editor_js() ?>
