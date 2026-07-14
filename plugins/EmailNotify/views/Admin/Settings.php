<?php
/**
 * 이메일 알림 - 발신 설정
 *
 * @var string $pageTitle
 * @var array  $config
 */
$config = $config ?? [];
?>
<form id="emailNotifySettingsForm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>이메일 발송에 사용할 발신자 정보를 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/email-notify/settings/save"
                        data-callback="emailNotifySettingsSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <div class="page-block">
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-envelope-at text-pastel-blue"></i>
                    <span>발신자 정보</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        실제 발송은 서버 메일(코어 Mailer / SMTP·sendmail)을 사용합니다.
                        발신자 정보를 비워두면 코어 기본 발신값(<code>config/mail.php</code>)을 사용합니다.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">발신자명</label>
                            <input type="text" class="form-control" name="formData[from_name]"
                                   value="<?= htmlspecialchars((string) ($config['from_name'] ?? '')) ?>"
                                   placeholder="예: 우리쇼핑몰">
                            <div class="form-text">수신자에게 표시되는 이름 (선택)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">발신 이메일</label>
                            <input type="email" class="form-control" name="formData[from_email]"
                                   value="<?= htmlspecialchars((string) ($config['from_email'] ?? '')) ?>"
                                   placeholder="예: noreply@example.com">
                            <div class="form-text">발신 주소 (선택). 도메인 SPF/DKIM 설정 권장</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="formData[is_active]" value="1" <?= !empty($config['is_active']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">템플릿 발송 활성화</label>
                            </div>
                            <div class="form-text">비활성화 시 EmailNotify 템플릿을 사용한 발송이 중단되고 알림 채널 목록에서도 제외됩니다. 알림 이메일 전체 중단은 기본설정 &gt; 알림 이메일 발송 활성화에서 설정합니다.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
MubloRequest.registerCallback('emailNotifySettingsSaved', function(response) {
    alert(response.message || '저장되었습니다.');
    if (response.result === 'success') {
        location.reload();
    }
});
</script>
