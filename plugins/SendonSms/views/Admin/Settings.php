<?php
/**
 * 센드온 SMS 관리자 설정
 *
 * @var string $pageTitle
 * @var array  $config
 */
?>
<form id="sendonSettingsForm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>센드온 SMS API 인증 정보를 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/sendon-sms/settings/save"
                        data-callback="sendonSettingsSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <div class="page-block">
            <!-- API 인증 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-key text-pastel-blue"></i>
                    <span>API 인증 정보</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        센드온(sendon.io)에서 발급받은 API 인증 정보를 입력하세요.
                        <a href="https://sendon.io" target="_blank" rel="noopener">센드온 회원가입 및 API 키 발급 <i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">API ID</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="formData[api_id]" value="<?= htmlspecialchars((string) ($config['api_id'] ?? '')) ?>">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0 me-1" type="checkbox" name="formData[_clear_api_id]" value="1" title="체크 후 저장 시 값 초기화">
                                    <small class="text-muted">초기화</small>
                                </div>
                            </div>
                            <div class="form-text">센드온에서 발급받은 API ID</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">API Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="formData[api_password]" value="<?= htmlspecialchars((string) ($config['api_password'] ?? '')) ?>">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0 me-1" type="checkbox" name="formData[_clear_api_password]" value="1" title="체크 후 저장 시 값 초기화">
                                    <small class="text-muted">초기화</small>
                                </div>
                            </div>
                            <div class="form-text">센드온에서 발급받은 API Password</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">기본 발신번호</label>
                            <input type="text" class="form-control" name="formData[default_sender]" value="<?= htmlspecialchars((string) ($config['default_sender'] ?? '')) ?>">
                            <div class="form-text">센드온에 등록된 발신번호 (예: 02-1234-5678)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">사용 설정</label>
                            <div class="d-flex align-items-center gap-4" style="min-height: calc(1.5em + 0.75rem + (var(--bs-border-width, 1px) * 2))">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="test_mode" name="formData[test_mode]" value="1" <?= !empty($config['test_mode']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="test_mode">테스트 모드</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="formData[is_active]" value="1" <?= !empty($config['is_active']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_active">사용 활성화</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 센드온 잔액 조회 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-wallet2 text-pastel-green"></i>
                    <span>센드온 잔액 조회</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">센드온 계정의 현재 잔액(보유 포인트)을 조회합니다.</p>
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="btn btn-outline-primary" id="btnCheckBalance" onclick="checkSendonBalance()">
                            <i class="bi bi-arrow-repeat"></i> 잔액 조회
                        </button>
                        <div id="balanceResult" style="display:none">
                            <span id="balanceLoading" style="display:none">
                                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                                조회 중...
                            </span>
                            <span id="balanceInfo" style="display:none"></span>
                            <span id="balanceError" class="text-danger" style="display:none"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// 어드민 일관성: 머블로 알럿 모달 대신 네이티브 alert 폴백.
// 화면에 인라인 텍스트로 에러를 보여주는 조회 요청(잔액조회 등)은 alert 생략.
MubloRequest.configure({ errorHandler: function (e) {
    var url = (e && e.url) || '';
    if (url.indexOf('sendon-balance') !== -1 || url.indexOf('/channels/senders') !== -1 || /\/channels\/\d+\/templates$/.test(url)) return;
    alert((e && e.message) || '오류가 발생했습니다.');
} });

MubloRequest.registerCallback('sendonSettingsSaved', function(response) {
    alert(response.message || '저장되었습니다.');
    if (response.result === 'success') {
        location.reload();
    }
});

function checkSendonBalance() {
    var resultEl = document.getElementById('balanceResult');
    var loadingEl = document.getElementById('balanceLoading');
    var infoEl = document.getElementById('balanceInfo');
    var errorEl = document.getElementById('balanceError');

    resultEl.style.display = '';
    loadingEl.style.display = '';
    infoEl.style.display = 'none';
    errorEl.style.display = 'none';

    MubloRequest.requestJson('/admin/sendon-sms/settings/sendon-balance', {}, { method: 'GET' })
        .then(function(response) {
            loadingEl.style.display = 'none';
            var data = response.data || {};
            var html = '<span class="badge bg-success fs-6 me-2">'
                + '<i class="bi bi-coin me-1"></i>'
                + (data.balance !== undefined ? Number(data.balance).toLocaleString() + ' 포인트' : response.message || '조회 완료')
                + '</span>';
            if (data.balance_date) {
                html += '<small class="text-muted">(' + data.balance_date + ' 기준)</small>';
            }
            infoEl.innerHTML = html;
            infoEl.style.display = '';
        })
        .catch(function(err) {
            loadingEl.style.display = 'none';
            errorEl.textContent = err.message || '잔액 조회에 실패했습니다. API 설정을 확인하세요.';
            errorEl.style.display = '';
        });
}
</script>
