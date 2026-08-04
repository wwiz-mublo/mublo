<?php
/**
 * 발신번호/템플릿 통합 관리 (2컬럼 마스터-디테일)
 *
 * 좌측: 발신번호 목록 (센드온 발신번호 가져오기)
 * 우측: 선택된 발신번호의 템플릿 목록 (AJAX 로드)
 *
 * @var string $pageTitle
 * @var array $channels
 * @var array $pagination
 */
$channels = $channels ?? [];
$pagination = $pagination ?? [];
$availableVariables = $availableVariables ?? [];
$config = $config ?? [];
$hasApiKey = !empty($config['api_id']) && !empty($config['api_password']);
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>발신번호를 선택하면 우측에 해당 템플릿 목록이 표시됩니다.</p>
        </div>
    </div>

    <div class="page-block">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            <strong>발신번호</strong>는 <a href="https://sendon.io" target="_blank" rel="noopener">센드온(sendon.io)</a>에서
            먼저 등록한 뒤, 아래에서 발신번호를 가져와 등록하세요.
        </div>
    </div>

    <div class="row">
        <!-- 좌측: 발신번호 목록 -->
        <div class="col-lg-4">
            <div class="page-block">
                <div class="card">
                    <div class="card-hero">
                        <i class="bi bi-telephone text-pastel-blue"></i>
                        <span>발신번호</span>
                        <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle rounded-pill"><?= count($channels) ?></span>
                        <button type="button" class="btn btn-primary btn-xs ms-auto" onclick="fetchSenders()" <?= !$hasApiKey ? 'disabled title="API 인증 정보를 먼저 설정하세요"' : '' ?>>
                            <i class="bi bi-cloud-download"></i> 센드온 발신번호 가져오기
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="list-group" id="channelList">
                <?php if (empty($channels)): ?>
                    <div class="list-group-item text-center text-muted py-4">
                        등록된 발신번호가 없습니다.<br>
                        <small>센드온 발신번호를 가져와 등록하세요.</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($channels as $ch):
                        $chId = (int) ($ch['channel_id'] ?? 0);
                        $chName = htmlspecialchars((string) ($ch['channel_name'] ?? ''));
                        $senderNumber = htmlspecialchars((string) ($ch['sender_number'] ?? ''));
                        $tplCount = (int) ($ch['template_count'] ?? 0);
                        $activeClass = '';
                        $defaultBadge = !empty($ch['is_default'])
                            ? ' <span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">기본</span>'
                            : '';
                        $chJson = htmlspecialchars(json_encode($ch, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?>
                    <a href="javascript:void(0)"
                       class="list-group-item list-group-item-action<?= $activeClass ?>"
                       data-channel-id="<?= $chId ?>"
                       onclick="selectChannel(<?= $chJson ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold"><?= $chName ?></div>
                                <small class="text-muted">
                                    <span class="badge text-info-emphasis bg-info-subtle border border-info-subtle">SMS</span><?= $defaultBadge ?>
                                    <?php if ($senderNumber): ?>
                                        <span class="ms-1"><?= $senderNumber ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle rounded-pill"><?= $tplCount ?></span>
                                <div class="mt-1">
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1"
                                            onclick="event.stopPropagation(); openVariableSettingModal(<?= $chJson ?>)" title="변수설정">
                                        <i class="bi bi-braces"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1"
                                            onclick="event.stopPropagation(); openChannelModal(<?= $chJson ?>)" title="수정">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                            onclick="event.stopPropagation(); deleteChannel(<?= $chId ?>)" title="삭제">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 우측: 선택된 발신번호의 템플릿 -->
        <div class="col-lg-8">
            <div class="page-block sticky-top" style="top: calc(var(--header-height) + 1.5rem)">
                <div class="card">
                    <div class="card-hero">
                        <i class="bi bi-list-task text-pastel-green"></i>
                        <span id="templatePanelTitle">템플릿 목록</span>
                        <div id="addTemplateBtn" class="ms-auto" style="display:none">
                            <button type="button" class="btn btn-outline-secondary btn-xs" onclick="openTemplateModal()">
                                <i class="bi bi-plus-lg"></i> 템플릿 추가
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                    <!-- 초기 상태 -->
                    <div id="emptyState" class="text-center text-muted py-5">
                        <i class="bi bi-folder2-open d-block mb-2" style="font-size: 2.5rem;"></i>
                        <p class="mb-0">좌측에서 발신번호를 선택하면<br>해당 템플릿 목록이 표시됩니다.</p>
                    </div>

                    <!-- 로딩 상태 -->
                    <div id="loadingState" class="text-center py-5" style="display:none">
                        <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                        템플릿을 불러오는 중...
                    </div>

                    <!-- 템플릿 테이블 -->
                    <div id="templateTableWrap" style="display:none">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:48px; white-space:nowrap">번호</th>
                                        <th style="width:90px; white-space:nowrap">코드</th>
                                        <th>이름</th>
                                        <th style="width:56px; text-align:center; white-space:nowrap">타입</th>
                                        <th>제목</th>
                                        <th style="width:64px; text-align:center; white-space:nowrap">사용</th>
                                        <th style="width:72px; text-align:center; white-space:nowrap">관리</th>
                                    </tr>
                                </thead>
                                <tbody id="templateTableBody">
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted small mt-2" id="templateCountInfo"></div>
                    </div>

                    <!-- 템플릿 없음 상태 -->
                    <div id="noTemplateState" class="text-center text-muted py-4" style="display:none">
                        <p class="mb-0">이 발신번호에 등록된 템플릿이 없습니다.</p>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 채널 변수 설정 전용 모달 -->
<div class="modal fade" id="variableSettingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="varSettingModalTitle">
                    <i class="bi bi-braces me-2"></i>발신번호 변수 설정
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="variableSettingForm">
            <div class="modal-body">
                <input type="hidden" name="formData[channel_id]" id="vs_channel_id" value="0">
                <input type="hidden" name="formData[channel_name]" id="vs_channel_name" value="">
                <input type="hidden" name="formData[channel_type]" id="vs_channel_type" value="sms">
                <input type="hidden" name="formData[sender_number]" id="vs_sender_number" value="">

                <!-- 변수 편집기 -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label fw-bold mb-0">치환 변수</label>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addVariableRow('ch')">
                        <i class="bi bi-plus-lg"></i> 변수 추가
                    </button>
                </div>
                <small class="text-muted d-block mb-2">
                    메시지 본문의 <code>#{치환키}</code> 토큰과 패키지 필드를 매핑합니다.
                    아래 참조 버튼에서 필드를 선택하거나 직접 입력하세요.
                </small>
                <div id="ch_variable_rows"></div>
                <input type="hidden" name="formData[variables]" id="ch_variables_json" value="">

                <?php if (!empty($availableVariables)): ?>
                <!-- 변수 참조 (인라인 패널) -->
                <hr class="my-3">
                <div class="mb-2">
                    <label class="form-label text-muted mb-2">패키지 변수 참조</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($availableVariables as $sourceKey => $source): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="toggleVariableRefPanel('<?= htmlspecialchars($sourceKey) ?>')">
                            <i class="bi bi-list-ul"></i> <?= htmlspecialchars($source['label']) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="variableRefPanel" style="display:none">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-bold small" id="variableRefPanelTitle">사용 가능 변수</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" onclick="closeVariableRefPanel()" title="닫기">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="border rounded" style="max-height:260px; overflow-y:auto">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light" style="position:sticky; top:0; z-index:1">
                                <tr>
                                    <th style="width:40px" class="text-center">번호</th>
                                    <th style="width:180px">필드명</th>
                                    <th>설명</th>
                                    <th style="width:60px" class="text-center">추가</th>
                                </tr>
                            </thead>
                            <tbody id="variableRefTableBody">
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">행을 클릭하면 위 변수 편집기에 추가됩니다. 이미 추가된 필드는 건너뜁니다.</small>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary mublo-submit"
                        data-target="/admin/sendon-sms/channels/save"
                        data-callback="onVariablesSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($availableVariables)): ?>
<!-- 변수 데이터를 JS로 전달 -->
<script>
var _availableVariables = <?= json_encode($availableVariables, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>

<!-- 센드온 발신번호 목록 모달 -->
<div class="modal fade" id="senderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-download me-2"></i>센드온 발신번호</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- 로딩 -->
                <div id="senderLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                    발신번호 목록을 가져오는 중...
                </div>
                <!-- 에러 -->
                <div id="senderError" class="alert alert-danger" style="display:none"></div>
                <!-- 발신번호 테이블 -->
                <div id="senderTableWrap" style="display:none">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>발신번호</th>
                                    <th>이름/메모</th>
                                    <th style="width:120px; text-align:center">상태</th>
                                    <th style="width:100px; text-align:center">등록</th>
                                </tr>
                            </thead>
                            <tbody id="senderTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- 발신번호 없음 -->
                <div id="noSenderState" class="text-center text-muted py-4" style="display:none">
                    <p class="mb-0">등록된 발신번호가 없습니다.<br>
                    <a href="https://sendon.io" target="_blank" rel="noopener">센드온(sendon.io)</a>에서 발신번호를 먼저 등록해주세요.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 채널(발신번호) 등록/수정 모달 -->
<div class="modal fade" id="channelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="channelModalTitle">발신번호 등록</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="channelForm">
            <div class="modal-body">
                    <input type="hidden" name="formData[channel_id]" id="ch_channel_id" value="0">
                    <input type="hidden" name="formData[channel_type]" id="ch_channel_type" value="sms">
                    <div class="row g-3 mb-3">
                        <div class="col-8">
                            <label class="form-label">이름(메모) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="formData[channel_name]" id="ch_channel_name" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">발신번호 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="formData[sender_number]" id="ch_sender_number" required
                                   placeholder="02-1234-5678">
                        </div>
                    </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary mublo-submit"
                        data-target="/admin/sendon-sms/channels/save"
                        data-callback="onChannelSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
            </form>
        </div>
    </div>
</div>

<!-- 템플릿 모달 -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle">템플릿 등록</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="templateForm">
            <div class="modal-body">
                    <input type="hidden" name="formData[template_id]" id="tpl_template_id" value="0">
                    <input type="hidden" name="formData[channel_id]" id="tpl_channel_id" value="0">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">템플릿 코드 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="formData[template_code]" id="tpl_template_code" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">템플릿명 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="formData[template_name]" id="tpl_template_name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">메시지 타입 <span class="text-danger">*</span></label>
                            <select class="form-select" name="formData[message_type]" id="tpl_message_type">
                                <option value="SMS">SMS</option>
                                <option value="LMS">LMS</option>
                                <option value="MMS">MMS</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">제목</label>
                            <input type="text" class="form-control" name="formData[subject]" id="tpl_subject">
                            <div class="form-text">LMS/MMS일 때 사용</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">본문</label>
                        <textarea class="form-control" name="formData[message_body]" id="tpl_message_body" rows="4"></textarea>
                    </div>

                    <!-- 관리자 수신번호 -->
                    <hr class="my-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold">관리자 수신번호</label>
                        <input type="text" class="form-control" name="formData[admin_recipients]" id="tpl_admin_recipients"
                               placeholder="010-1234-5678, 010-9876-5432">
                        <div class="form-text">쉼표(,)로 구분하여 여러 번호를 입력하세요. 발송 시 이 번호들에도 함께 발송됩니다.</div>
                    </div>

                    <!-- 변수 오버라이드 -->
                    <hr class="my-3">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold mb-0">치환 변수 (오버라이드)</label>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addVariableRow('tpl')">
                                <i class="bi bi-plus-lg"></i> 변수 추가
                            </button>
                        </div>
                        <small class="text-muted d-block mb-2">
                            미설정 시 발신번호의 변수 설정을 사용합니다. 이 템플릿만의 변수를 지정하려면 추가하세요.
                        </small>
                        <div id="tpl_variable_rows"></div>
                        <input type="hidden" name="formData[variable_schema]" id="tpl_variables_json" value="">
                    </div>

                    <div class="mb-0">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="tpl_is_active" name="formData[is_active]" value="1" checked>
                            <label class="form-check-label" for="tpl_is_active">사용</label>
                        </div>
                    </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary mublo-submit"
                        data-target="/admin/sendon-sms/templates/save"
                        data-callback="onTemplateSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
            </form>
        </div>
    </div>
</div>

<script>
// 어드민 일관성: 머블로 알럿 모달 대신 네이티브 alert 폴백.
// 화면에 인라인 텍스트로 에러를 보여주는 조회 요청(발신번호/템플릿 목록 등)은 alert 생략.
MubloRequest.configure({ errorHandler: function (e) {
    var url = (e && e.url) || '';
    if (url.indexOf('sendon-balance') !== -1 || url.indexOf('/channels/senders') !== -1 || /\/channels\/\d+\/templates$/.test(url)) return;
    alert((e && e.message) || '오류가 발생했습니다.');
} });

document.addEventListener('DOMContentLoaded', function() {
    // === 상태 관리 ===
    var selectedChannelId = 0;
    var selectedChannelName = '';

    // === HTML 이스케이프 ===
    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ========================================
    // 센드온 발신번호 가져오기
    // ========================================
    window.fetchSenders = function() {
        var modal = new bootstrap.Modal(document.getElementById('senderModal'));
        modal.show();

        document.getElementById('senderLoading').style.display = '';
        document.getElementById('senderError').style.display = 'none';
        document.getElementById('senderTableWrap').style.display = 'none';
        document.getElementById('noSenderState').style.display = 'none';

        MubloRequest.requestJson('/admin/sendon-sms/channels/senders', {}, { method: 'GET' })
            .then(function(response) {
                document.getElementById('senderLoading').style.display = 'none';

                var senders = response.data.senders || [];
                var registeredNumbers = response.data.registeredNumbers || [];

                if (senders.length === 0) {
                    document.getElementById('noSenderState').style.display = '';
                    return;
                }

                renderSenderTable(senders, registeredNumbers);
                document.getElementById('senderTableWrap').style.display = '';
            })
            .catch(function(err) {
                document.getElementById('senderLoading').style.display = 'none';
                var errorEl = document.getElementById('senderError');
                errorEl.textContent = err.message || '발신번호 목록을 가져올 수 없습니다.';
                errorEl.style.display = '';
            });
    };

    function renderSenderTable(senders, registeredNumbers) {
        var tbody = document.getElementById('senderTableBody');
        var html = '';

        senders.forEach(function(s) {
            var senderNumber = s.sender_number || s.number || '';
            var senderName = s.name || s.memo || senderNumber;
            var isRegistered = registeredNumbers.indexOf(senderNumber) !== -1;
            var senderJson = esc(JSON.stringify({
                sender_number: senderNumber,
                channel_name: senderName
            }));

            html += '<tr>';
            html += '<td><code>' + esc(senderNumber) + '</code></td>';
            html += '<td>' + esc(senderName) + '</td>';
            html += '<td class="text-center"><span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">사용 가능</span></td>';
            html += '<td class="text-center">';
            if (isRegistered) {
                html += '<span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">등록됨</span>';
            } else {
                html += '<button type="button" class="btn btn-primary btn-sm" onclick=\'registerSender(' + senderJson + ')\'>';
                html += '<i class="bi bi-plus-lg"></i> 등록</button>';
            }
            html += '</td>';
            html += '</tr>';
        });

        tbody.innerHTML = html;
    }

    window.registerSender = function(senderData) {
        // 발신번호 모달 닫기
        bootstrap.Modal.getInstance(document.getElementById('senderModal'))?.hide();

        // 채널 모달 열기 (발신번호, 이름 자동 채움)
        document.getElementById('channelModalTitle').textContent = '발신번호 등록';
        document.getElementById('ch_channel_id').value = 0;
        document.getElementById('ch_channel_name').value = senderData.channel_name || '';
        document.getElementById('ch_channel_type').value = 'sms';
        document.getElementById('ch_sender_number').value = senderData.sender_number || '';

        // 센드온에서 가져온 발신번호는 readonly
        document.getElementById('ch_sender_number').readOnly = true;

        setTimeout(function() {
            new bootstrap.Modal(document.getElementById('channelModal')).show();
        }, 300);
    };

    // ========================================
    // 채널 수정
    // ========================================
    window.openChannelModal = function(data) {
        var isEdit = data && data.channel_id;
        document.getElementById('channelModalTitle').textContent = isEdit ? '발신번호 수정' : '발신번호 등록';
        document.getElementById('ch_channel_id').value = isEdit ? data.channel_id : 0;
        document.getElementById('ch_channel_name').value = isEdit ? (data.channel_name || '') : '';
        document.getElementById('ch_channel_type').value = 'sms';
        document.getElementById('ch_sender_number').value = isEdit ? (data.sender_number || '') : '';

        // 수정 모드에서는 모든 필드 편집 가능
        document.getElementById('ch_sender_number').readOnly = false;

        new bootstrap.Modal(document.getElementById('channelModal')).show();
    };

    // ========================================
    // 채널 선택 -> 템플릿 로드
    // ========================================
    window.selectChannel = function(channelData) {
        selectedChannelId = channelData.channel_id;
        selectedChannelName = channelData.channel_name;

        document.querySelectorAll('#channelList .list-group-item').forEach(function(el) {
            el.classList.remove('active');
        });
        var activeItem = document.querySelector('#channelList [data-channel-id="' + selectedChannelId + '"]');
        if (activeItem) {
            activeItem.classList.add('active');
        }

        document.getElementById('templatePanelTitle').textContent = selectedChannelName + ' 템플릿';
        document.getElementById('addTemplateBtn').style.display = '';

        loadTemplates(selectedChannelId);
    };

    // ========================================
    // 템플릿 목록 AJAX 로드
    // ========================================
    function loadTemplates(channelId) {
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('noTemplateState').style.display = 'none';
        document.getElementById('templateTableWrap').style.display = 'none';
        document.getElementById('loadingState').style.display = '';

        MubloRequest.requestJson(
            '/admin/sendon-sms/channels/' + channelId + '/templates',
            {},
            { method: 'GET' }
        ).then(function(response) {
            document.getElementById('loadingState').style.display = 'none';
            var items = response.data.items || [];

            if (items.length === 0) {
                document.getElementById('noTemplateState').style.display = '';
                document.getElementById('templateCountInfo').textContent = '';
                return;
            }

            renderTemplateTable(items);
            var total = response.data.pagination ? response.data.pagination.totalItems : items.length;
            document.getElementById('templateCountInfo').textContent = '총 ' + total + '개 템플릿';
            document.getElementById('templateTableWrap').style.display = '';
        }).catch(function() {
            document.getElementById('loadingState').style.display = 'none';
            document.getElementById('noTemplateState').style.display = '';
            document.getElementById('noTemplateState').querySelector('p').textContent = '템플릿을 불러오지 못했습니다.';
        });
    }

    // ========================================
    // 템플릿 테이블 렌더링
    // ========================================
    function renderTemplateTable(items) {
        var tbody = document.getElementById('templateTableBody');
        var html = '';

        items.forEach(function(tpl) {
            var isActive = Number(tpl.is_active || 0) === 1;
            var activeBadge = isActive
                ? '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">사용</span>'
                : '<span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">미사용</span>';

            var msgType = (tpl.message_type || 'SMS').toUpperCase();
            var msgTypeBadgeClass = msgType === 'SMS' ? 'text-info-emphasis bg-info-subtle border border-info-subtle'
                : (msgType === 'LMS' ? 'text-primary-emphasis bg-primary-subtle border border-primary-subtle' : 'text-warning-emphasis bg-warning-subtle border border-warning-subtle');
            var msgTypeBadge = '<span class="badge ' + msgTypeBadgeClass + '">' + esc(msgType) + '</span>';

            var tplJson = esc(JSON.stringify(tpl));

            html += '<tr>';
            html += '<td>' + esc(String(tpl.template_id)) + '</td>';
            html += '<td><code>' + esc(tpl.template_code || '') + '</code></td>';
            html += '<td>' + esc(tpl.template_name || '') + '</td>';
            html += '<td class="text-center">' + msgTypeBadge + '</td>';
            html += '<td>' + esc(tpl.subject || '-') + '</td>';
            html += '<td class="text-center">' + activeBadge + '</td>';
            html += '<td class="text-center">';
            html += '<button type="button" class="btn btn-outline-primary btn-sm py-0 px-1 me-1" onclick=\'openTemplateModal(' + tplJson + ')\' title="수정"><i class="bi bi-pencil"></i></button>';
            html += '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="deleteTemplate(' + tpl.template_id + ')" title="삭제"><i class="bi bi-trash"></i></button>';
            html += '</td>';
            html += '</tr>';
        });

        tbody.innerHTML = html;
    }

    // ========================================
    // 채널 삭제
    // ========================================
    window.deleteChannel = function(channelId) {
        if (!confirm('발신번호를 삭제하시겠습니까?\n(하위 템플릿이 있으면 삭제할 수 없습니다)')) {
            return;
        }

        MubloRequest.requestJson('/admin/sendon-sms/channels/delete', { channel_id: channelId })
            .then(function(response) {
                alert(response.message || '삭제되었습니다.');
                location.reload();
            });
    };

    MubloRequest.registerCallback('onChannelSaved', function(response) {
        alert(response.message || '저장되었습니다.');
        location.reload();
    });

    // ========================================
    // 템플릿 모달
    // ========================================
    window.openTemplateModal = function(data) {
        if (!selectedChannelId && !(data && data.channel_id)) {
            alert('먼저 발신번호를 선택해주세요.');
            return;
        }

        var isEdit = data && data.template_id;
        document.getElementById('templateModalTitle').textContent = isEdit ? '템플릿 수정' : '템플릿 등록';
        document.getElementById('tpl_template_id').value = isEdit ? data.template_id : 0;
        document.getElementById('tpl_channel_id').value = isEdit ? (data.channel_id || selectedChannelId) : selectedChannelId;
        document.getElementById('tpl_template_code').value = isEdit ? (data.template_code || '') : '';
        document.getElementById('tpl_template_code').readOnly = false;
        document.getElementById('tpl_message_body').readOnly = false;
        document.getElementById('tpl_template_name').value = isEdit ? (data.template_name || '') : '';
        document.getElementById('tpl_message_type').value = isEdit ? (data.message_type || 'SMS') : 'SMS';
        document.getElementById('tpl_subject').value = isEdit ? (data.subject || '') : '';
        document.getElementById('tpl_message_body').value = isEdit ? (data.message_body || '') : '';
        document.getElementById('tpl_is_active').checked = isEdit ? Number(data.is_active || 0) === 1 : true;
        document.getElementById('tpl_admin_recipients').value = isEdit ? (data.admin_recipients || '') : '';

        new bootstrap.Modal(document.getElementById('templateModal')).show();
    };

    // ========================================
    // 템플릿 삭제
    // ========================================
    window.deleteTemplate = function(templateId) {
        if (!confirm('템플릿을 삭제하시겠습니까?')) {
            return;
        }

        MubloRequest.requestJson('/admin/sendon-sms/templates/delete', { template_id: templateId })
            .then(function(response) {
                alert(response.message || '삭제되었습니다.');
                if (selectedChannelId) {
                    loadTemplates(selectedChannelId);
                }
            });
    };

    MubloRequest.registerCallback('onTemplateSaved', function(response) {
        alert(response.message || '저장되었습니다.');
        bootstrap.Modal.getInstance(document.getElementById('templateModal'))?.hide();
        if (selectedChannelId) {
            loadTemplates(selectedChannelId);
        }
    });

    // ========================================
    // 변수 편집기
    // ========================================

    // 현재 활성 편집기 (참조 패널 클릭 시 대상)
    var activeVariablePrefix = 'ch';

    /**
     * 변수 행 추가
     * @param {string} prefix 'ch' 또는 'tpl'
     * @param {string} key 치환키 (예: '주문자명')
     * @param {string} field 필드명 (예: 'orderer_name')
     */
    window.addVariableRow = function(prefix, key, field) {
        var container = document.getElementById(prefix + '_variable_rows');
        var row = document.createElement('div');
        row.className = 'input-group input-group-sm mb-1 variable-row';
        row.innerHTML =
            '<span class="input-group-text">#{</span>' +
            '<input type="text" class="form-control var-key" placeholder="치환키 (예: 주문자명)" value="' + esc(key || '') + '">' +
            '<span class="input-group-text">}</span>' +
            '<span class="input-group-text">→</span>' +
            '<input type="text" class="form-control var-field" placeholder="필드명 (예: orderer_name)" value="' + esc(field || '') + '">' +
            '<button type="button" class="btn btn-outline-danger" onclick="removeVariableRow(this)" title="삭제">' +
            '<i class="bi bi-x-lg"></i></button>';
        container.appendChild(row);
    };

    /**
     * 변수 행 삭제
     */
    window.removeVariableRow = function(btn) {
        btn.closest('.variable-row').remove();
    };

    /**
     * 변수 행들을 JSON 문자열로 직렬화
     * @param {string} prefix 'ch' 또는 'tpl'
     * @returns {string} JSON
     */
    function serializeVariables(prefix) {
        var rows = document.querySelectorAll('#' + prefix + '_variable_rows .variable-row');
        var result = [];
        rows.forEach(function(row) {
            var key = row.querySelector('.var-key').value.trim();
            var field = row.querySelector('.var-field').value.trim();
            if (key && field) {
                result.push({ key: key, field: field });
            }
        });
        return result.length > 0 ? JSON.stringify(result) : '';
    }

    /**
     * 기존 변수 JSON을 행으로 로드
     * @param {string} prefix 'ch' 또는 'tpl'
     * @param {string|Array} data JSON 문자열 또는 배열
     */
    function loadVariableRows(prefix, data) {
        var container = document.getElementById(prefix + '_variable_rows');
        container.innerHTML = '';

        if (!data) return;

        var items = data;
        if (typeof data === 'string') {
            try { items = JSON.parse(data); } catch(e) { return; }
        }

        if (!Array.isArray(items)) return;

        items.forEach(function(item) {
            addVariableRow(prefix, item.key || '', item.field || '');
        });
    }

    /**
     * 참조 패널에서 변수 클릭 시 활성 편집기에 행 추가 (중복 체크)
     */
    window.insertVariable = function(field, label) {
        var container = document.getElementById(activeVariablePrefix + '_variable_rows');
        var existingRows = container.querySelectorAll('.variable-row');

        // 중복 체크: 같은 field가 이미 있으면 하이라이트 후 리턴
        for (var i = 0; i < existingRows.length; i++) {
            var existingField = existingRows[i].querySelector('.var-field').value.trim();
            if (existingField === field) {
                var row = existingRows[i];
                row.style.transition = 'background-color 0.3s';
                row.style.backgroundColor = '#fff3cd';
                setTimeout(function() { row.style.backgroundColor = ''; }, 800);
                return;
            }
        }

        addVariableRow(activeVariablePrefix, label, field);

        // 추가된 행 하이라이트
        var newRow = container.querySelector('.variable-row:last-child');
        if (newRow) {
            newRow.style.transition = 'background-color 0.3s';
            newRow.style.backgroundColor = '#d1e7dd';
            setTimeout(function() { newRow.style.backgroundColor = ''; }, 800);
        }
    };

    /**
     * 변수 참조 인라인 패널 토글
     */
    var currentRefSourceKey = '';

    window.toggleVariableRefPanel = function(sourceKey) {
        if (typeof _availableVariables === 'undefined') return;

        var panel = document.getElementById('variableRefPanel');

        // 같은 소스 클릭 시 토글
        if (currentRefSourceKey === sourceKey && panel.style.display !== 'none') {
            panel.style.display = 'none';
            currentRefSourceKey = '';
            return;
        }

        var source = _availableVariables[sourceKey];
        if (!source) return;

        currentRefSourceKey = sourceKey;
        document.getElementById('variableRefPanelTitle').textContent = source.label + ' — 사용 가능 변수';

        var tbody = document.getElementById('variableRefTableBody');
        var html = '';
        var idx = 1;

        for (var f in source.variables) {
            if (!source.variables.hasOwnProperty(f)) continue;
            var lbl = source.variables[f];
            html += '<tr class="var-ref-row" role="button" style="cursor:pointer" '
                + 'onclick="insertVariable(\'' + esc(f) + '\', \'' + esc(lbl) + '\')">'
                + '<td class="text-center text-muted">' + idx + '</td>'
                + '<td><code>' + esc(f) + '</code></td>'
                + '<td>' + esc(lbl) + '</td>'
                + '<td class="text-center">'
                + '<button type="button" class="btn btn-outline-primary btn-sm py-0 px-1" '
                + 'onclick="event.stopPropagation(); insertVariable(\'' + esc(f) + '\', \'' + esc(lbl) + '\')" title="추가">'
                + '<i class="bi bi-plus-lg"></i></button></td>'
                + '</tr>';
            idx++;
        }

        tbody.innerHTML = html;
        panel.style.display = '';
    };

    window.closeVariableRefPanel = function() {
        document.getElementById('variableRefPanel').style.display = 'none';
        currentRefSourceKey = '';
    };

    // ========================================
    // 발신번호 변수 설정 전용 모달
    // ========================================
    var variableSettingChannelData = null;

    window.openVariableSettingModal = function(data) {
        variableSettingChannelData = data;
        activeVariablePrefix = 'ch';

        var chName = data ? (data.channel_name || '') : '';
        document.getElementById('varSettingModalTitle').innerHTML =
            '<i class="bi bi-braces me-2"></i>' + esc(chName) + ' — 변수 설정';

        // hidden 필드에 채널 기존 데이터 세팅
        document.getElementById('vs_channel_id').value = data ? (data.channel_id || 0) : 0;
        document.getElementById('vs_channel_name').value = data ? (data.channel_name || '') : '';
        document.getElementById('vs_channel_type').value = 'sms';
        document.getElementById('vs_sender_number').value = data ? (data.sender_number || '') : '';

        loadVariableRows('ch', data ? (data.variables || '') : '');

        // 참조 패널 초기화
        var refPanel = document.getElementById('variableRefPanel');
        if (refPanel) {
            refPanel.style.display = 'none';
            currentRefSourceKey = '';
        }

        new bootstrap.Modal(document.getElementById('variableSettingModal')).show();
    };

    MubloRequest.registerCallback('onVariablesSaved', function(response) {
        alert(response.message || '저장되었습니다.');
        location.reload();
    });

    // --- 템플릿 모달 열기 시 변수 로드 ---
    var origOpenTemplateModal = window.openTemplateModal;
    window.openTemplateModal = function(data) {
        origOpenTemplateModal(data);
        activeVariablePrefix = 'tpl';
        loadVariableRows('tpl', data ? (data.variable_schema || '') : '');
    };

    // --- 저장 전 변수 직렬화 (capture phase) ---
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.mublo-submit');
        if (!btn) return;

        var target = btn.getAttribute('data-target') || '';

        if (target.indexOf('/channels/save') !== -1) {
            document.getElementById('ch_variables_json').value = serializeVariables('ch');
        }
        if (target.indexOf('/templates/save') !== -1) {
            document.getElementById('tpl_variables_json').value = serializeVariables('tpl');
        }
    }, true); // capture phase
});
</script>

