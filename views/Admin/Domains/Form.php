<?php
/**
 * 도메인 관리 - 생성/수정 폼
 *
 * 상위 관리자가 하위 사이트 관리:
 * - 생성: 기본 정보(소유자, 도메인명) 입력
 * - 수정: 기본 정보는 읽기 전용, 운영 상태만 수정 가능
 *
 * @var string $pageTitle
 * @var bool $isEdit
 * @var \Mublo\Entity\Domain\Domain|null $domain
 * @var \Mublo\Entity\Member\Member|null $ownerMember 소유자 회원 정보 (수정 시)
 * @var array $statusOptions
 */

// 도메인 데이터 추출
$domainId = $domain?->getDomainId() ?? 0;
$domainName = $domain?->getDomain() ?? '';
$domainGroup = $domain?->getDomainGroup() ?? '';
$memberId = $domain?->getMemberId();
$status = $domain?->getStatus() ?? 'active';

$actionUrl = $isEdit ? "/admin/domains/update/{$domainId}" : '/admin/domains/store';
?>

<form id="domain-form">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>
                <a href="/admin/domains">도메인 관리</a>
                <i class="bi bi-chevron-right mx-1"></i>
                <?= $isEdit ? '정보 수정' : '도메인 등록' ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/domains') ?>" class="btn btn-sm btn-outline-secondary">
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
                    <i class="bi bi-globe text-pastel-blue"></i>
                    <span>기본 정보</span>
                    <?php if ($isEdit): ?><span class="badge bg-secondary ms-auto">읽기 전용</span><?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($isEdit): ?>
                        <!-- 수정 모드: 읽기 전용 정보 표시 -->

                        <!-- 소유자 정보 -->
                        <div class="mb-3">
                            <label class="form-label">소유자</label>
                            <?php if (isset($ownerMember) && $ownerMember): ?>
                                <div class="card">
                                    <div class="card-body py-2">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <small class="text-muted">아이디</small>
                                                <div><strong><?= htmlspecialchars($ownerMember->getUserId()) ?></strong></div>
                                            </div>
                                            <div class="col-md-6">
                                                <small class="text-muted">회원등급</small>
                                                <div><?= htmlspecialchars($ownerMember->getLevelName() ?? '-') ?> (<?= htmlspecialchars($ownerMember->getLevelType() ?? '-') ?>)</div>
                                            </div>
                                        </div>
                                        <?php
                                        $ownerName = $ownerMember->getName();
                                        $ownerEmail = $ownerMember->getEmail();
                                        $ownerPhone = $ownerMember->getPhone();
                                        if ($ownerName || $ownerEmail || $ownerPhone):
                                        ?>
                                        <hr class="my-2">
                                        <div class="row">
                                            <?php if ($ownerName): ?>
                                            <div class="col-md-4">
                                                <small class="text-muted">이름</small>
                                                <div><?= htmlspecialchars($ownerName) ?></div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($ownerEmail): ?>
                                            <div class="col-md-4">
                                                <small class="text-muted">이메일</small>
                                                <div><?= htmlspecialchars($ownerEmail) ?></div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($ownerPhone): ?>
                                            <div class="col-md-4">
                                                <small class="text-muted">연락처</small>
                                                <div><?= htmlspecialchars($ownerPhone) ?></div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning py-2 mb-0">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    소유자 정보를 찾을 수 없습니다. (회원 ID: <?= $memberId ?>)
                                </div>
                            <?php endif; ?>
                            <input type="hidden" name="formData[member_id]" value="<?= $memberId ?>">
                        </div>

                        <!-- 도메인명 / 도메인 그룹 (읽기 전용) -->
                        <div class="row mb-0">
                            <div class="col-md-6">
                                <label class="form-label">도메인명</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($domainName) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">도메인 그룹</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($domainGroup) ?>" readonly>
                                <div class="form-text">도메인 그룹은 생성 시 자동으로 설정되며 변경할 수 없습니다.</div>
                            </div>
                        </div>

                        <?php if (!empty($formExtras)): ?>
                        <?php foreach ($formExtras as $extraHtml): ?>
                        <?= $extraHtml ?>
                        <?php endforeach; ?>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- 생성 모드: 입력 폼 -->

                        <!-- 소유자 회원 (필수) - 아이디로 검증 -->
                        <div class="mb-3">
                            <label class="form-label">소유자 회원 아이디 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" id="owner-user-id" class="form-control"
                                       placeholder="회원 아이디 입력" required>
                                <button type="button" class="btn btn-outline-secondary" id="btn-check-owner">
                                    <i class="bi bi-person-check"></i> 소유자 확인
                                </button>
                            </div>
                            <div id="owner-check-result" class="mt-2"></div>
                            <input type="hidden" name="formData[member_id]" id="member-id" value="">
                            <div class="form-text">
                                <i class="bi bi-info-circle text-info"></i>
                                도메인 운영 권한이 있는 회원만 소유자로 등록할 수 있습니다. (최대 1개 사이트)
                            </div>
                        </div>

                        <!-- 도메인명 -->
                        <div class="mb-0">
                            <label class="form-label">도메인명 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="formData[domain]" id="domain-name" class="form-control"
                                       placeholder="example.com" required>
                                <button type="button" class="btn btn-outline-secondary" id="btn-check-domain">
                                    중복 확인
                                </button>
                            </div>
                            <div class="form-text">영문, 숫자, 하이픈, 점만 사용 가능합니다.</div>
                            <div id="domain-check-result" class="mt-1"></div>
                        </div>

                        <?php if (!empty($formExtras)): ?>
                        <?php foreach ($formExtras as $extraHtml): ?>
                        <?= $extraHtml ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 운영 상태 -->
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-toggle-on text-pastel-green"></i>
                    <span>운영 상태</span>
                </div>
                <div class="card-body">
                    <div class="row mb-0">
                        <div class="col-md-6">
                            <label class="form-label">상태 <span class="text-danger">*</span></label>
                            <select name="formData[status]" class="form-select" <?= $domainId === 1 ? 'disabled' : '' ?>>
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($domainId === 1): ?>
                                <input type="hidden" name="formData[status]" value="active">
                                <div class="form-text text-warning">기본 도메인은 항상 활성 상태입니다.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($isEdit): ?>
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-link-45deg text-pastel-purple"></i>
                        <span>도메인 정보</span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">도메인 ID</dt>
                            <dd class="col-sm-8"><?= $domainId ?></dd>

                            <dt class="col-sm-4">등록일</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($domain?->getCreatedAt() ?? '-') ?></dd>

                            <dt class="col-sm-4">수정일</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($domain?->getUpdatedAt() ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-sky"></i>
                    <span>안내</span>
                </div>
                <div class="card-body">
                    <?php if ($isEdit): ?>
                        <p class="small text-muted mb-2">
                            <i class="bi bi-lock"></i>
                            기본 정보(소유자, 도메인명, 그룹)는 변경할 수 없습니다.
                        </p>
                        <p class="small text-muted mb-0">
                            <i class="bi bi-shield-check"></i>
                            사이트 세부 설정은 해당 사이트 관리자가 직접 관리합니다.
                        </p>
                    <?php else: ?>
                        <p class="small text-muted mb-2">
                            <i class="bi bi-info-circle"></i>
                            도메인 그룹은 생성 시 자동으로 설정됩니다.
                        </p>
                        <p class="small text-muted mb-0">
                            <i class="bi bi-shield-check"></i>
                            사이트 세부 설정은 해당 사이트 관리자가 직접 관리합니다.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('domain-form');
    const domainId = <?= $domainId ?>;
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    const actionUrl = <?= json_encode((string) $actionUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    <?php if (!$isEdit): ?>
    // =========================================================================
    // 소유자 검증 (생성 시에만)
    // =========================================================================
    let ownerValidated = false;
    const btnCheckOwner = document.getElementById('btn-check-owner');
    const ownerUserIdInput = document.getElementById('owner-user-id');
    const ownerResultDiv = document.getElementById('owner-check-result');
    const memberIdInput = document.getElementById('member-id');

    if (btnCheckOwner && ownerUserIdInput) {
        btnCheckOwner.addEventListener('click', function() {
            const userId = ownerUserIdInput.value.trim();
            if (!userId) {
                ownerResultDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle"></i> 회원 아이디를 입력해주세요.</div>';
                return;
            }

            btnCheckOwner.disabled = true;
            btnCheckOwner.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 확인 중...';

            MubloRequest.requestJson('/admin/domains/check-owner', {
                user_id: userId
            }).then(response => {
                if (response.result === 'success') {
                    const data = response.data || {};
                    ownerResultDiv.innerHTML = `
                        <div class="alert alert-success py-2 mb-0">
                            <i class="bi bi-check-circle-fill"></i> ${response.message || '소유자로 등록 가능합니다.'}
                            <br><small class="text-muted">회원 ID: ${data.member_id} / 등급: ${data.level_name || '-'} (${data.level_type || '-'})</small>
                        </div>`;
                    memberIdInput.value = data.member_id;
                    ownerValidated = true;
                } else {
                    ownerResultDiv.innerHTML = `<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill"></i> ${response.message || '소유자로 등록할 수 없습니다.'}</div>`;
                    memberIdInput.value = '';
                    ownerValidated = false;
                }
            }).catch(err => {
                ownerResultDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill"></i> 확인 중 오류가 발생했습니다.</div>';
                console.error(err);
            }).finally(() => {
                btnCheckOwner.disabled = false;
                btnCheckOwner.innerHTML = '<i class="bi bi-person-check"></i> 소유자 확인';
            });
        });

        ownerUserIdInput.addEventListener('input', function() {
            ownerResultDiv.innerHTML = '';
            memberIdInput.value = '';
            ownerValidated = false;
        });
    }

    // =========================================================================
    // 도메인 중복 확인 (생성 시에만)
    // =========================================================================
    const btnCheckDomain = document.getElementById('btn-check-domain');
    const domainInput = document.getElementById('domain-name');
    const domainResultDiv = document.getElementById('domain-check-result');

    if (btnCheckDomain && domainInput) {
        btnCheckDomain.addEventListener('click', function() {
            const domain = domainInput.value.trim();
            if (!domain) {
                domainResultDiv.innerHTML = '<span class="text-danger">도메인명을 입력해주세요.</span>';
                return;
            }

            MubloRequest.requestJson('/admin/domains/check-duplicate', {
                domain: domain
            }).then(response => {
                if (response.result === 'success') {
                    domainResultDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> ' + (response.message || '사용 가능한 도메인입니다.') + '</span>';
                } else {
                    domainResultDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (response.message || '이미 등록된 도메인입니다.') + '</span>';
                }
            }).catch(err => {
                domainResultDiv.innerHTML = '<span class="text-danger">확인 중 오류가 발생했습니다.</span>';
            });
        });

        domainInput.addEventListener('input', function() {
            domainResultDiv.innerHTML = '';
        });
    }
    <?php endif; ?>

    // =========================================================================
    // 저장 버튼
    // =========================================================================
    document.querySelector('.btn-save-form').addEventListener('click', function() {
        <?php if (!$isEdit): ?>
        // 생성 시 필수 검증
        const domainInput = document.getElementById('domain-name');
        const memberIdInput = document.getElementById('member-id');

        if (!domainInput.value.trim()) {
            MubloRequest.showAlert('도메인명을 입력해주세요.', 'warning');
            domainInput.focus();
            return;
        }

        if (!memberIdInput.value.trim()) {
            MubloRequest.showAlert('소유자를 확인해주세요.\n회원 아이디를 입력하고 "소유자 확인" 버튼을 클릭하세요.', 'warning');
            document.getElementById('owner-user-id').focus();
            return;
        }

        if (!ownerValidated) {
            MubloRequest.showAlert('소유자 검증이 필요합니다.\n"소유자 확인" 버튼을 클릭하여 소유자를 검증해주세요.', 'warning');
            document.getElementById('owner-user-id').focus();
            return;
        }
        <?php endif; ?>

        const formData = new FormData(form);

        MubloRequest.sendRequest({
            method: 'POST',
            url: actionUrl,
            payloadType: MubloRequest.PayloadType.FORM,
            data: formData,
            loading: true
        }).then(response => {
            if (response.result === 'success') {
                const redirect = response.data?.redirect || response.redirect || <?= json_encode($listUrl ?? '/admin/domains') ?>;
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
});
</script>
