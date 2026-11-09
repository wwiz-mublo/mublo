<?php
/**
 * 페이앱 관리자 설정
 *
 * @var string $title
 * @var array  $config       ['userid', 'mode', 'feedback_ip_allowlist'] — 비밀값은 내려오지 않는다
 * @var array  $secretSaved  ['linkkey' => bool, 'linkval' => bool, 'requestkey' => bool] 저장 여부
 * @var bool   $isConfigured
 */
$mode = $config['mode'] ?? 'live';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$feedbackUrl = "{$scheme}://{$host}/pay-app/callback/feedback";
?>
<form name="frm" id="frm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($title) ?></h3>
                <p>페이앱(PayApp) 결제 연동 정보를 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/pay-app/settings"
                        data-callback="payAppSettingsSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <div class="page-block">
            <!-- 운영 모드 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-toggles text-pastel-blue"></i>
                    <span>운영 모드</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label">결제 모드</label>
                            <select name="formData[mode]" class="form-select">
                                <option value="live" selected>운영 (Live)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 연동 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-key text-pastel-green"></i>
                    <span>연동 정보</span>
                    <?php if ($isConfigured): ?>
                    <span class="badge bg-success ms-auto"><i class="bi bi-check-circle"></i> 설정 완료</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark ms-auto"><i class="bi bi-exclamation-triangle"></i> 미설정</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        연동 정보는 <strong>페이앱 판매자 관리 사이트 → 설정 → 연동정보</strong>에서 확인할 수 있습니다.
                    </div>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label">판매자 아이디 <span class="text-danger">*</span></label>
                            <input type="text" name="formData[userid]" class="form-control"
                                   value="<?= htmlspecialchars($config['userid'] ?? '') ?>" placeholder="예) myshop">
                            <div class="form-text">페이앱에 가입한 판매자 아이디.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">연동 KEY <span class="text-danger">*</span></label>
                            <input type="password" name="formData[linkkey]" class="form-control" value=""
                                   autocomplete="new-password"
                                   placeholder="<?= !empty($secretSaved['linkkey']) ? '저장된 값 유지 (변경 시에만 입력)' : '예) abcd1234efgh5678ijkl9012mnop3456' ?>">
                            <div class="form-text">API 호출 인증 키. AES-256-GCM으로 암호화 저장됩니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">연동 VALUE <span class="text-danger">*</span></label>
                            <input type="password" name="formData[linkval]" class="form-control" value=""
                                   autocomplete="new-password"
                                   placeholder="<?= !empty($secretSaved['linkval']) ? '저장된 값 유지 (변경 시에만 입력)' : '예) abcd1234' ?>">
                            <div class="form-text">결제 통보(Feedback) 검증값. 암호화 저장됩니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">결제 KEY <span class="text-muted small">(권장)</span></label>
                            <input type="password" name="formData[requestkey]" class="form-control" value=""
                                   autocomplete="new-password"
                                   placeholder="<?= !empty($secretSaved['requestkey']) ? '저장된 값 유지 (변경 시에만 입력)' : '판매자 사이트 → 설정에서 확인' ?>">
                            <div class="form-text">입력하면 결제 통보의 위변조 방지 해시(hash)를 검증하고, 취소 요청에 해시를 싣습니다. 암호화 저장됩니다.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Feedback URL -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-link-45deg text-pastel-purple"></i>
                    <span>Feedback URL</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        결제 완료 시 아래 URL로 결과가 자동 전송됩니다. (결제 요청 시 자동 포함)
                    </div>
                    <div class="row g-2 align-items-start mb-3">
                        <label for="feedbackUrl" class="col-sm-2 col-form-label">Feedback URL</label>
                        <div class="col-sm-10">
                            <div class="input-group">
                                <input type="text" class="form-control" id="feedbackUrl"
                                       value="<?= htmlspecialchars($feedbackUrl) ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="copyFeedbackUrl()">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="form-text">외부에서 접근 가능해야 합니다. 방화벽이나 접근 제한이 있으면 결제 완료 통보를 받을 수 없습니다.</div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-start">
                        <label for="feedbackIpAllowlist" class="col-sm-2 col-form-label">송신 IP 허용목록</label>
                        <div class="col-sm-10">
                            <input type="text" class="form-control" id="feedbackIpAllowlist"
                                   name="formData[feedback_ip_allowlist]"
                                   value="<?= htmlspecialchars((string) ($config['feedback_ip_allowlist'] ?? '')) ?>"
                                   placeholder="예) 1.2.3.4, 5.6.7.0/24 (쉼표·공백으로 구분, 비우면 검증 안 함)">
                            <div class="form-text">페이앱 발신 IP만 허용하려면 입력하세요(권장). 비우면 모든 IP에서 수신합니다.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 지원 결제수단 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-credit-card text-pastel-orange"></i>
                    <span>지원 결제수단</span>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge border text-secondary py-2 px-3">신용카드</span>
                        <span class="badge border text-secondary py-2 px-3">휴대폰</span>
                        <span class="badge border text-secondary py-2 px-3">카카오페이</span>
                        <span class="badge border text-secondary py-2 px-3">네이버페이</span>
                        <span class="badge border text-secondary py-2 px-3">토스페이</span>
                        <span class="badge border text-secondary py-2 px-3">실시간 계좌이체</span>
                        <span class="badge border text-secondary py-2 px-3">애플페이</span>
                    </div>
                    <div class="form-text mt-2">가상계좌는 지원하지 않습니다. 결제창에서 제외되며, 발급되더라도 자동으로 취소됩니다.</div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function copyFeedbackUrl() {
    var input = document.getElementById('feedbackUrl');
    navigator.clipboard.writeText(input.value).then(function() {
        MubloRequest.showToast('복사되었습니다.', 'success');
    });
}

function payAppSettingsSaved(res) {
    if (res.result === 'success') {
        MubloRequest.showToast(res.message, 'success');
    }
}
</script>
