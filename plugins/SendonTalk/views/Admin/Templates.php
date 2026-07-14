<?php
/**
 * @var string $pageTitle
 * @var array $templates
 * @var array $pagination
 * @var array $config
 */
$templates = $templates ?? [];
$hasApiKey = !empty($config['api_id']) && !empty($config['api_password']);
$statusLabels = [
    'draft'    => ['📝 작성중', 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle'],
    'pending'  => ['⏳ 심사중', 'text-warning-emphasis bg-warning-subtle border border-warning-subtle'],
    'approved' => ['✅ 승인', 'text-success-emphasis bg-success-subtle border border-success-subtle'],
    'rejected' => ['❌ 반려', 'text-danger-emphasis bg-danger-subtle border border-danger-subtle'],
];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>카카오 알림톡 템플릿을 관리합니다. 템플릿마다 다른 채널을 지정할 수 있습니다.</p>
        </div>
        <div class="page-title-actions">
            <?php if ($hasApiKey): ?>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="syncTemplates()" title="카카오 심사 상태 일괄 동기화">
                <i class="bi bi-arrow-repeat"></i> 상태 동기화
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openTplModal()">
                <i class="bi bi-plus-lg"></i> 템플릿 추가
            </button>
        </div>
    </div>

    <div class="page-block">
        <?php if (!$hasApiKey): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            API 인증 정보가 설정되지 않았습니다. <a href="/admin/sendon-talk/settings">연동 설정</a>에서 API 정보를 먼저 입력하세요.
        </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <strong>알림톡 사용 절차:</strong>
            ① 템플릿 작성 (채널 선택) → ② [카카오 심사 요청] → ③ 심사 승인 → ④ 발송 가능
            <br><small class="text-muted">카카오 심사는 보통 1~3 영업일 소요됩니다. [상태 동기화] 버튼으로 최신 상태를 확인할 수 있습니다.</small>
        </div>
    </div>

    <div class="page-block">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th style="width:50px">번호</th>
                    <th style="width:120px">코드</th>
                    <th>템플릿명</th>
                    <th style="width:120px">채널</th>
                    <th class="text-center" style="width:100px">카카오 상태</th>
                    <th class="text-center" style="width:80px">LMS 대체</th>
                    <th class="text-center" style="width:70px">사용</th>
                    <th class="text-center" style="width:160px">관리</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">등록된 템플릿이 없습니다.</td></tr>
                <?php else: ?>
                <?php foreach ($templates as $tpl):
                    $status = $tpl['kakao_status'] ?? 'draft';
                    $badge = $statusLabels[$status] ?? ['알 수 없음', 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle'];
                    $tplJson = htmlspecialchars(json_encode($tpl, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                    <td><?= (int) $tpl['template_id'] ?></td>
                    <td><code class="small"><?= htmlspecialchars($tpl['template_code']) ?></code></td>
                    <td>
                        <?= htmlspecialchars($tpl['template_name']) ?>
                        <?php if (!empty($tpl['kakao_template_id'])): ?>
                        <br><small class="text-muted">카카오 ID: <?= htmlspecialchars($tpl['kakao_template_id']) ?></small>
                        <?php endif; ?>
                        <?php if ($status === 'rejected' && !empty($tpl['kakao_rejected_reason'])): ?>
                        <br><small class="text-danger"><?= htmlspecialchars($tpl['kakao_rejected_reason']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($tpl['send_profile_name'])): ?>
                        <small><?= htmlspecialchars($tpl['send_profile_name']) ?></small>
                        <?php elseif (!empty($tpl['send_profile_id'])): ?>
                        <code class="small"><?= htmlspecialchars($tpl['send_profile_id']) ?></code>
                        <?php else: ?>
                        <span class="text-muted small">미지정</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge <?= $badge[1] ?>"><?= $badge[0] ?></span></td>
                    <td class="text-center"><?= !empty($tpl['fallback_sms']) ? '<span class="badge text-info-emphasis bg-info-subtle border border-info-subtle">ON</span>' : '<span class="text-muted">-</span>' ?></td>
                    <td class="text-center"><?= !empty($tpl['is_active']) ? '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">사용</span>' : '<span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">미사용</span>' ?></td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary" onclick='openTplModal(<?= $tplJson ?>)' title="수정">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($hasApiKey && !empty($tpl['send_profile_id']) && ($status === 'draft' || $status === 'rejected')): ?>
                            <button type="button" class="btn btn-outline-warning" onclick="registerKakao(<?= (int) $tpl['template_id'] ?>)" title="카카오 심사 요청">
                                <i class="bi bi-send"></i>
                            </button>
                            <?php elseif ($hasApiKey && $status === 'pending'): ?>
                            <button type="button" class="btn btn-outline-info" onclick="checkStatus(<?= (int) $tpl['template_id'] ?>)" title="심사 상태 확인">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-danger" onclick="deleteTpl(<?= (int) $tpl['template_id'] ?>)" title="삭제">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    $tplPerPage = $pagination['perPage'] ?? 50;
    $tplTotalPages = max(1, (int) ceil(($pagination['totalItems'] ?? 0) / $tplPerPage));
    if ($tplTotalPages > 1):
    ?>
    <div class="row gx-2 justify-content-between align-items-center my-2">
        <div class="col-auto"></div>
        <div class="col-auto">
            <?= $this->pagination([
                'currentPage' => $pagination['currentPage'] ?? 1,
                'totalPages' => $tplTotalPages,
            ]) ?>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- 템플릿 모달 -->
<div class="modal fade" id="tplModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tplModalTitle">템플릿 등록</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="tplForm">
            <div class="modal-body">
                <input type="hidden" name="formData[template_id]" id="tpl_id" value="0">
                <input type="hidden" name="formData[template_code]" id="tpl_code" value="">
                <input type="hidden" name="formData[send_profile_name]" id="tpl_profile_name" value="">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">템플릿명 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="formData[template_name]" id="tpl_name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">채널 (발신프로필) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select class="form-select" name="formData[send_profile_id]" id="tpl_profile" required>
                                <option value="">-- 채널을 불러와 주세요 --</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="loadChannelsForModal()" title="채널 불러오기">
                                <i class="bi bi-arrow-repeat"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-1">
                            <input type="checkbox" class="form-check-input" name="formData[fallback_sms]" id="tpl_fallback" value="1" checked>
                            <label class="form-check-label" for="tpl_fallback">실패 시 LMS 대체 발송</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-1">
                            <input type="checkbox" class="form-check-input" name="formData[is_active]" id="tpl_active" value="1" checked>
                            <label class="form-check-label" for="tpl_active">사용</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">메시지 본문 <span class="text-danger">*</span></label>
                    <small class="text-muted d-block mb-1">#{변수명} 형식으로 변수를 사용할 수 있습니다. (최대 1,000자)</small>
                    <textarea class="form-control" name="formData[message_body]" id="tpl_body" rows="8"
                              maxlength="1000"
                              placeholder="#{쇼핑몰명} #{주문자명}님, 주문이 접수되었습니다.&#10;&#10;주문번호: #{주문번호}&#10;상품: #{상품명}&#10;월 렌탈료: #{월렌탈료}&#10;&#10;문의: #{고객센터번호}"></textarea>
                    <div class="form-text"><span id="tpl_body_count">0</span>/1,000자</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">관리자 수신번호</label>
                    <input type="text" class="form-control" name="formData[admin_recipients]" id="tpl_admin"
                           placeholder="010-1234-5678, 010-9876-5432">
                    <div class="form-text">쉼표(,)로 구분. 발송 시 이 번호들에도 LMS로 함께 발송됩니다.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary mublo-submit"
                        data-target="/admin/sendon-talk/templates/save"
                        data-callback="onTplSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
            </form>
        </div>
    </div>
</div>

<script>
// 어드민 일관성: 머블로 알럿 모달 대신 네이티브 alert 폴백.
MubloRequest.configure({ errorHandler: function (e) {
    alert((e && e.message) || '오류가 발생했습니다.');
} });

var _channelsLoaded = false;
var _channelCache = [];

// 글자수 카운터
(function() {
    var body = document.getElementById('tpl_body');
    var counter = document.getElementById('tpl_body_count');
    if (body && counter) {
        body.addEventListener('input', function() { counter.textContent = this.value.length; });
    }
})();

// 채널 선택 시 이름도 저장
document.getElementById('tpl_profile').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    document.getElementById('tpl_profile_name').value = opt ? (opt.getAttribute('data-name') || '') : '';
});

function loadChannelsForModal(selectedId) {
    var select = document.getElementById('tpl_profile');

    if (_channelsLoaded && _channelCache.length) {
        renderChannelOptions(_channelCache, selectedId);
        return;
    }

    select.innerHTML = '<option value="">불러오는 중...</option>';
    select.disabled = true;

    MubloRequest.requestQuery('/admin/sendon-talk/channels/fetch')
        .then(function(res) {
            select.disabled = false;
            if (res.result !== 'success') {
                select.innerHTML = '<option value="">조회 실패: ' + (res.message || '') + '</option>';
                return;
            }
            var raw = res.data && res.data.profiles ? res.data.profiles : {};
            _channelCache = Array.isArray(raw) ? raw : (raw.sendProfiles || []);
            _channelsLoaded = true;
            renderChannelOptions(_channelCache, selectedId);
        });
}

function renderChannelOptions(profiles, selectedId) {
    var select = document.getElementById('tpl_profile');
    var html = '<option value="">-- 채널 선택 --</option>';
    profiles.forEach(function(p) {
        var id = p.id || p.sendProfileId || '';
        var name = p.channelName || p.profileName || p.name || id;
        var sel = (id === selectedId) ? ' selected' : '';
        html += '<option value="' + id + '" data-name="' + name.replace(/"/g, '&quot;') + '"' + sel + '>' + name + '</option>';
    });
    select.innerHTML = html;

    // 이름 동기화
    var opt = select.options[select.selectedIndex];
    document.getElementById('tpl_profile_name').value = opt ? (opt.getAttribute('data-name') || '') : '';
}

function openTplModal(data) {
    var isEdit = data && data.template_id;
    document.getElementById('tplModalTitle').textContent = isEdit ? '템플릿 수정' : '템플릿 등록';
    document.getElementById('tpl_id').value = isEdit ? data.template_id : 0;
    document.getElementById('tpl_code').value = isEdit ? (data.template_code || '') : '';
    document.getElementById('tpl_name').value = isEdit ? (data.template_name || '') : '';
    document.getElementById('tpl_body').value = isEdit ? (data.message_body || '') : '';
    document.getElementById('tpl_admin').value = isEdit ? (data.admin_recipients || '') : '';
    document.getElementById('tpl_fallback').checked = isEdit ? !!parseInt(data.fallback_sms) : true;
    document.getElementById('tpl_active').checked = isEdit ? !!parseInt(data.is_active) : true;
    document.getElementById('tpl_body_count').textContent = isEdit ? (data.message_body || '').length : 0;

    // 채널 로드
    var profileId = isEdit ? (data.send_profile_id || '') : '';
    loadChannelsForModal(profileId);

    new bootstrap.Modal(document.getElementById('tplModal')).show();
}

MubloRequest.registerCallback('onTplSaved', function(res) {
    alert(res.message || '저장되었습니다.');
    location.reload();
});

function registerKakao(templateId) {
    if (!confirm('이 템플릿을 카카오에 심사 요청하시겠습니까?\n심사는 1~3 영업일 소요됩니다.')) return;
    MubloRequest.requestJson('/admin/sendon-talk/templates/register-kakao', { template_id: templateId })
        .then(function(res) {
            alert(res.message || '심사 요청 완료');
            location.reload();
        });
}

function checkStatus(templateId) {
    MubloRequest.requestQuery('/admin/sendon-talk/templates/check-kakao-status', { template_id: templateId })
        .then(function(res) {
            var status = res.data && res.data.status ? res.data.status : '확인 실패';
            var labels = { pending: '심사중', approved: '승인', rejected: '반려', draft: '작성중' };
            alert('상태: ' + (labels[status] || status));
            location.reload();
        });
}

function syncTemplates() {
    MubloRequest.requestJson('/admin/sendon-talk/templates/sync', {})
        .then(function(res) {
            alert(res.message || '동기화 완료');
            location.reload();
        });
}

function deleteTpl(templateId) {
    if (!confirm('이 템플릿을 삭제하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/sendon-talk/templates/delete', { template_id: templateId })
        .then(function(res) {
            alert(res.message || '삭제되었습니다.');
            location.reload();
        });
}
</script>
