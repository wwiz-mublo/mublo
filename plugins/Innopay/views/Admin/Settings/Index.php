<?php
/**
 * 이노페이 관리자 설정
 *
 * @var string $title
 * @var array  $config
 * @var bool   $hasMerchantKey
 * @var bool   $hasCancelPwd
 * @var bool   $isConfigured
 * @var array  $payMethods
 * @var string $notiUrl
 */
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<form name="frm" id="frm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= $e($title) ?></h3>
                <p>이노페이(INNOPAY) 결제 연동 정보를 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <a href="/admin/innopay/logs" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-journal-text"></i> 결제 로그
                </a>
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/innopay/settings"
                        data-callback="innopaySettingsSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <div class="page-block">
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
                        MID 와 Merchant-Key(라이센스키)는 이노페이 계약 시 발급됩니다.
                        테스트는 MID <code>testpay01m</code> 으로 할 수 있으며, 테스트 결제는 매일 오후 11시에 자동 취소됩니다.
                    </div>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label">상점 아이디 (MID) <span class="text-danger">*</span></label>
                            <input type="text" name="formData[mid]" class="form-control" maxlength="10"
                                   value="<?= $e($config['mid'] ?? '') ?>" placeholder="예) testpay01m">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Merchant-Key (라이센스키) <span class="text-danger">*</span></label>
                            <input type="password" name="formData[merchant_key]" class="form-control" autocomplete="new-password"
                                   placeholder="<?= $hasMerchantKey ? '등록됨 — 바꿀 때만 입력' : '발급받은 라이센스키' ?>">
                            <div class="form-text">승인·조회 API 인증 키. 암호화 저장되며, 비워 두면 기존 값을 유지합니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">취소 비밀번호</label>
                            <input type="password" name="formData[cancel_pwd]" class="form-control" autocomplete="new-password"
                                   placeholder="<?= $hasCancelPwd ? '등록됨 — 바꿀 때만 입력' : '미등록' ?>">
                            <div class="form-text">
                                관리자 환불·자동 취소에 필요합니다. 이노페이 상점관리 → 가맹점정보 → 가맹점정보관리에서 설정합니다.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 결제창 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-window text-pastel-blue"></i>
                    <span>결제창</span>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label">결제수단</label>
                            <select name="formData[pay_method]" class="form-select">
                                <?php foreach ($payMethods as $value => $label): ?>
                                <option value="<?= $e($value) ?>" <?= (string) ($config['pay_method'] ?? '') === (string) $value ? 'selected' : '' ?>>
                                    <?= $e($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">가상계좌는 입금통보 연동 후 지원 예정입니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">기본 구매자 이메일</label>
                            <input type="email" name="formData[default_buyer_email]" class="form-control"
                                   value="<?= $e($config['default_buyer_email'] ?? '') ?>" placeholder="예) order@example.com">
                            <div class="form-text">이노페이 필수값입니다. 주문에 이메일이 없을 때 대신 보냅니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">결제창 로고 URL</label>
                            <input type="text" name="formData[logo_url]" class="form-control" maxlength="80"
                                   value="<?= $e($config['logo_url'] ?? '') ?>" placeholder="https://… (선택, 80자 이내)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 안전장치 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-shield-check text-pastel-orange"></i>
                    <span>안전장치</span>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="autoCancel"
                               name="formData[auto_cancel]" value="1" <?= !empty($config['auto_cancel']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="autoCancel">승인 후 주문 반영에 실패하면 결제를 자동 취소</label>
                    </div>
                    <div class="form-text">
                        취소 비밀번호가 있어야 동작합니다. 자동 취소도 실패하면 결제 로그 화면에 "확인 필요"로 표시됩니다.
                    </div>
                </div>
            </div>

            <!-- 결제결과 통보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-broadcast text-pastel-purple"></i>
                    <span>결제결과 통보 (Noti)</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        아래 주소를 이노페이 상점관리 → <strong>가맹점정보 → 가맹점정보관리 → 기본정보 → 결제 데이터 통보</strong>에
                        결제수단별(신용카드·간편결제·계좌이체)로 등록하세요. 구매자가 결제 직후 창을 닫아도 결제가 주문에 반영되고,
                        이노페이 상점관리에서 직접 취소한 건도 주문에 환불로 기록됩니다.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="notiUrl">통보 URL</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="notiUrl" value="<?= $e($notiUrl) ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="innopayCopyNotiUrl()">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="form-text">개인정보가 오가므로 https 주소로 등록해야 합니다.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="notiIpAllowlist">발신 IP 허용목록</label>
                            <input type="text" class="form-control" id="notiIpAllowlist" name="formData[noti_ip_allowlist]"
                                   value="<?= $e($config['noti_ip_allowlist'] ?? '') ?>" placeholder="비우면 모두 허용">
                            <div class="form-text">쉼표로 구분, CIDR 가능. 통보 내용은 항상 거래조회로 확인한 뒤 반영합니다.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function innopayCopyNotiUrl() {
    var input = document.getElementById('notiUrl');
    navigator.clipboard.writeText(input.value).then(function () {
        MubloRequest.showToast('복사되었습니다.', 'success');
    });
}

function innopaySettingsSaved(res) {
    if (res.result === 'success') {
        MubloRequest.showToast(res.message, 'success');
        setTimeout(function () { location.reload(); }, 600);
    }
}
</script>
