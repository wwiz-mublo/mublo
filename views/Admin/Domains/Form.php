<?php
/**
 * 도메인 관리 - 생성/수정 폼
 *
 * 도메인 등록/수정:
 * - 생성: 기본 정보(소유자, 도메인명) 입력
 * - 수정: 기본 정보는 읽기 전용, 운영 상태만 수정 가능 (자기 도메인은 호스트명 변경만)
 *
 * @var string $pageTitle
 * @var bool $isEdit
 * @var \Mublo\Entity\Domain\Domain|null $domain
 * @var \Mublo\Entity\Member\Member|null $ownerMember 소유자 회원 정보 (수정 시)
 * @var array $statusOptions
 * @var bool $isSelf 현재 접속 중인 사이트인지
 * @var bool $canChangeDomain 호스트명 변경 권한(최고관리자) 여부
 * @var array $changeHistory 실제 변경 이력 [{changed_at, from, to, actor, verdict}] (최신순)
 */

// 도메인 데이터 추출
$domainId = $domain?->getDomainId() ?? 0;
$domainName = $domain?->getDomain() ?? '';
$domainGroup = $domain?->getDomainGroup() ?? '';
$memberId = $domain?->getMemberId();
$status = $domain?->getStatus() ?? 'active';

$isSelf = (bool) ($isSelf ?? false);
$canChangeDomain = $isEdit && (bool) ($canChangeDomain ?? false);
$changeHistory = $changeHistory ?? [];

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
            <?php if (!$isSelf): ?>
            <button type="button" class="btn btn-sm btn-primary btn-save-form">
                <i class="bi bi-check-lg"></i> 저장
            </button>
            <?php endif; ?>
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
                                <?php if ($canChangeDomain): ?>
                                <div class="form-text">변경은 아래 <strong>도메인(호스트명) 변경</strong>에서 합니다.</div>
                                <?php else: ?>
                                <div class="form-text">
                                    <i class="bi bi-lock"></i>
                                    도메인 변경은 최고관리자만 할 수 있습니다. 변경이 필요하면 최고관리자에게 요청하세요.
                                </div>
                                <?php endif; ?>
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

            <?php if ($canChangeDomain): ?>
            <!-- 도메인(호스트명) 변경 — 최고관리자 전용 -->
            <div class="card mb-4" id="domain-change-card">
                <div class="card-hero">
                    <i class="bi bi-arrow-left-right text-pastel-orange"></i>
                    <span>도메인(호스트명) 변경</span>
                    <span class="badge bg-danger ms-auto">최고관리자</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning py-2">
                        <i class="bi bi-exclamation-triangle"></i>
                        변경하면 <strong><?= htmlspecialchars($domainName) ?></strong> 주소로는 더 이상 이 사이트에 접속할 수 없습니다.
                        <?php if ($isSelf): ?>
                        지금 이 화면도 새 주소로 이동합니다.
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new-domain">새 도메인명 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" id="new-domain" class="form-control" placeholder="example.com" autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="btn-verify-dns">
                                <i class="bi bi-hdd-network"></i> DNS 확인
                            </button>
                        </div>
                        <div class="form-text">
                            먼저 이 도메인의 DNS를 이 서버로 설정한 뒤 <strong>DNS 확인</strong>을 실행하세요.
                            실제로 이 서버에 연결되는지 확인되어야 변경할 수 있습니다.
                        </div>
                        <div id="dns-check-result" class="mt-2"></div>
                    </div>

                    <div class="mb-0" id="domain-change-confirm" hidden>
                        <label class="form-label" for="confirm-domain">확인을 위해 새 도메인명을 다시 입력 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" id="confirm-domain" class="form-control" placeholder="새 도메인명 재입력" autocomplete="off">
                            <button type="button" class="btn btn-danger" id="btn-change-domain">
                                <i class="bi bi-arrow-repeat"></i> 도메인 변경
                            </button>
                        </div>
                    </div>

                </div>

                <?php if (!empty($changeHistory)): ?>
                <!-- 변경 이력 — 실제로 바뀐 기록만(확인만 하고 끝난 시도는 제외). 없으면 섹션 자체를 렌더하지 않는다. -->
                <div class="card-hero border-top">
                    <i class="bi bi-clock-history text-pastel-slate"></i>
                    <span>변경 이력</span>
                    <span class="ms-auto text-muted small fw-normal">최근 <?= count($changeHistory) ?>건</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="text-nowrap">변경 일시</th>
                                    <th class="text-nowrap">이전 → 변경</th>
                                    <th class="text-nowrap">실행자</th>
                                    <th class="text-nowrap">확인</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($changeHistory as $log): ?>
                                <tr>
                                    <td class="text-nowrap"><?= htmlspecialchars($log['changed_at'] ?: '-') ?></td>
                                    <td>
                                        <span class="text-muted"><?= htmlspecialchars($log['from'] ?: '-') ?></span>
                                        <i class="bi bi-arrow-right mx-1"></i>
                                        <strong><?= htmlspecialchars($log['to']) ?></strong>
                                    </td>
                                    <td class="text-nowrap"><?= htmlspecialchars($log['actor'] ?: '-') ?></td>
                                    <td class="text-nowrap">
                                        <?php
                                        // 어떤 근거로 통과됐는지 — dev_local은 도달 확인을 생략한 경우다
                                        $verdictLabels = [
                                            'reachable' => ['연결 확인', 'bg-success'],
                                            'dev_local' => ['개발환경(생략)', 'bg-warning text-dark'],
                                        ];
                                        [$verdictLabel, $verdictClass] = $verdictLabels[$log['verdict']]
                                            ?? [$log['verdict'] ?: '-', 'bg-secondary'];
                                        ?>
                                        <span class="badge <?= $verdictClass ?>"><?= htmlspecialchars($verdictLabel) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-text mt-2 mb-0">확인만 하고 변경하지 않은 시도는 표시되지 않습니다.</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!$isSelf): ?>
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
            <?php else: ?>
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-toggle-on text-pastel-green"></i>
                    <span>운영 상태</span>
                </div>
                <div class="card-body">
                    <div class="mb-0">
                        <span class="badge bg-success"><?= htmlspecialchars($statusOptions[$status] ?? $status) ?></span>
                        <div class="form-text mb-0">
                            지금 접속 중인 사이트의 상태는 이 화면에서 바꿀 수 없습니다.
                            (스스로를 비활성·차단 처리해 관리자 접속이 끊기는 것을 막기 위함)
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
                            <?php if ($canChangeDomain): ?>
                            소유자와 도메인 그룹은 변경할 수 없습니다.
                            <?php else: ?>
                            기본 정보(소유자, 도메인명, 그룹)는 변경할 수 없습니다.
                            <?php endif; ?>
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

    <?php if ($canChangeDomain): ?>
    // =========================================================================
    // 도메인(호스트명) 변경 — DNS 확인 통과 후에만 변경 가능
    //
    // 확인 결과는 서버에 기록되고, 변경 요청 시 서버가 그 기록을 다시 확인한다.
    // 따라서 이 화면의 상태를 조작해도 검증을 건너뛸 수는 없다.
    // =========================================================================
    const isSelfDomain = <?= $isSelf ? 'true' : 'false' ?>;
    const newDomainInput = document.getElementById('new-domain');
    const confirmBlock = document.getElementById('domain-change-confirm');
    const confirmInput = document.getElementById('confirm-domain');
    const dnsResultDiv = document.getElementById('dns-check-result');
    const btnVerifyDns = document.getElementById('btn-verify-dns');
    const btnChangeDomain = document.getElementById('btn-change-domain');

    let verifiedDomain = '';

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function renderDnsReport(report, ok, message) {
        const dns = (report && report.dns) || {};
        const records = []
            .concat(dns.a || [], dns.aaaa || [], (dns.cname || []).map(function(c) { return 'CNAME ' + c; }));

        let detail = records.length
            ? '<div class="small mt-1">DNS 레코드: <code>' + records.map(escapeHtml).join('</code> <code>') + '</code></div>'
            : '<div class="small mt-1 text-muted">DNS 레코드: 없음</div>';

        if (report && report.verdict === 'dev_local') {
            detail += '<div class="small text-warning mt-1"><i class="bi bi-tools"></i> 개발환경 판정(dev_local) — 운영 서버에서는 실제 연결 확인이 필요합니다.</div>';
        }

        dnsResultDiv.innerHTML =
            '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0">' +
            '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'x-circle-fill') + '"></i> ' + escapeHtml(message) +
            detail +
            '</div>';
    }

    function resetVerification() {
        verifiedDomain = '';
        confirmBlock.hidden = true;
        confirmInput.value = '';
        dnsResultDiv.innerHTML = '';
    }

    newDomainInput.addEventListener('input', resetVerification);

    btnVerifyDns.addEventListener('click', function() {
        const candidate = newDomainInput.value.trim();
        if (!candidate) {
            MubloRequest.showAlert('새 도메인명을 입력해주세요.', 'warning');
            newDomainInput.focus();
            return;
        }

        btnVerifyDns.disabled = true;
        btnVerifyDns.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 확인 중...';

        MubloRequest.requestJson('/admin/domains/dns-check', {
            domain: candidate,
            domain_id: domainId
        }).then(response => {
            renderDnsReport(response.data, true, response.message || '확인되었습니다.');
            verifiedDomain = candidate.toLowerCase();
            confirmBlock.hidden = false;
        }).catch(err => {
            // MubloRequest는 실패 응답(result=error, 4xx)을 reject하며 사유 알림까지 띄운다.
            // 여기서는 리포트(어떤 DNS 레코드가 보였는지)만 화면에 남긴다 — 알림 중복 금지.
            verifiedDomain = '';
            confirmBlock.hidden = true;

            const failed = err && err.response;
            if (failed && failed.data) {
                renderDnsReport(failed.data, false, failed.message || '확인에 실패했습니다.');
            } else {
                dnsResultDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill"></i> '
                    + escapeHtml((err && err.message) || '확인 중 오류가 발생했습니다.') + '</div>';
            }
        }).finally(() => {
            btnVerifyDns.disabled = false;
            btnVerifyDns.innerHTML = '<i class="bi bi-hdd-network"></i> DNS 확인';
        });
    });

    btnChangeDomain.addEventListener('click', function() {
        const candidate = newDomainInput.value.trim();

        if (!verifiedDomain || verifiedDomain !== candidate.toLowerCase()) {
            MubloRequest.showAlert('DNS 확인을 먼저 통과해야 합니다.', 'warning');
            return;
        }

        if (confirmInput.value.trim().toLowerCase() !== candidate.toLowerCase()) {
            MubloRequest.showAlert('확인 입력이 새 도메인명과 일치하지 않습니다.', 'warning');
            confirmInput.focus();
            return;
        }

        const warning = isSelfDomain
            ? '이 사이트의 주소를 "' + candidate + '"(으)로 변경합니다.\n\n변경 후 현재 주소로는 접속할 수 없고, 새 주소로 자동 이동합니다.'
            : '이 사이트의 주소를 "' + candidate + '"(으)로 변경합니다.\n\n변경 후 이전 주소로는 접속할 수 없습니다.';

        MubloRequest.showConfirm(warning, function() {
            MubloRequest.requestJson('/admin/domains/domain-edit/' + domainId, {
                domain: candidate,
                confirm: confirmInput.value.trim()
            }, { loading: true }).then(response => {
                const redirect = response.data && response.data.redirect;
                if (isSelfDomain && redirect) {
                    // 세션 쿠키는 호스트별이므로, 새 주소로 이동해 인계 토큰으로 다시 로그인한다.
                    // 토큰 수명이 30초라 안내창을 닫는 즉시 이동하고, 방치 시에도 5초 후 자동 이동한다.
                    MubloRequest.showAlert(
                        (response.message || '변경되었습니다.') + ' 새 주소로 이동합니다.',
                        'success',
                        { onClose: function() { location.href = redirect; } }
                    );
                    setTimeout(function() { location.href = redirect; }, 5000);
                    return;
                }

                MubloRequest.showToast(response.message || '변경되었습니다.', 'success');
                location.href = redirect || '/admin/domains?activeCode=002_003';
            }).catch(err => {
                // 실패 사유 알림은 MubloRequest가 이미 띄운다 (중복 알림 방지).
                // 검증 기록은 1회성이라 실패 후에는 DNS 확인부터 다시 해야 한다.
                verifiedDomain = '';
                confirmBlock.hidden = true;
                console.error(err);
            });
        }, { type: 'warning' });
    });
    <?php endif; ?>

    // =========================================================================
    // 저장 버튼
    // =========================================================================
    const btnSaveForm = document.querySelector('.btn-save-form');
    if (btnSaveForm) btnSaveForm.addEventListener('click', function() {
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
