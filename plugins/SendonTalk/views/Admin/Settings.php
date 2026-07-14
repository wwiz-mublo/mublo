<?php
/**
 * 센드온 알림톡 관리자 설정
 *
 * @var string $pageTitle
 * @var array  $config
 */
?>
<form id="talkSettingsForm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>센드온 알림톡 API 인증 정보를 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/sendon-talk/settings/save"
                        data-callback="talkSettingsSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <div class="page-block">
            <div class="alert alert-info">
                <h6 class="alert-heading mb-2"><i class="bi bi-signpost-split"></i> 알림톡 연동 순서</h6>
                <ol class="mb-0">
                    <li><a href="https://sendon.io" target="_blank" rel="noopener">센드온(sendon.io)</a>에 회원가입 후 <strong>API Key</strong>를 발급받으세요.</li>
                    <li>카카오 비즈니스 채널을 개설하고 센드온에 연동하세요.</li>
                    <li>아래 폼에 인증 정보를 입력 후 저장하세요.</li>
                    <li><a href="/admin/sendon-talk/channels">채널/템플릿 관리</a>에서 채널을 등록하고 템플릿을 관리하세요.</li>
                </ol>
            </div>

            <!-- API 인증 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-key text-pastel-blue"></i>
                    <span>API 인증 정보</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Sendon ID</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="formData[api_id]"
                                       value="<?= htmlspecialchars($config['api_id'] ?? '') ?>">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0 me-1" type="checkbox"
                                           name="formData[_clear_api_id]" value="1" title="초기화">
                                    <small class="text-muted">초기화</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">API Key</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="formData[api_password]"
                                       value="<?= htmlspecialchars($config['api_password'] ?? '') ?>">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0 me-1" type="checkbox"
                                           name="formData[_clear_api_password]" value="1" title="초기화">
                                    <small class="text-muted">초기화</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">사용 설정</label>
                            <div class="d-flex align-items-center gap-4" style="min-height: calc(1.5em + 0.75rem + (var(--bs-border-width, 1px) * 2))">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="talk_test_mode"
                                           name="formData[test_mode]" value="1"
                                           <?= !empty($config['test_mode']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="talk_test_mode">테스트 모드</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="talk_is_active"
                                           name="formData[is_active]" value="1"
                                           <?= !empty($config['is_active']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="talk_is_active">사용 활성화</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// 어드민 일관성: 머블로 알럿 모달 대신 네이티브 alert 폴백.
MubloRequest.configure({ errorHandler: function (e) {
    alert((e && e.message) || '오류가 발생했습니다.');
} });

MubloRequest.registerCallback('talkSettingsSaved', function(res) {
    MubloRequest.showToast(res.message || '저장되었습니다.', res.result === 'success' ? 'success' : 'error');
    if (res.result === 'success') setTimeout(function() { location.reload(); }, 800);
});
</script>
