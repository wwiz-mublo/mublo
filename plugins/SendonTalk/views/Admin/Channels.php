<?php
/**
 * 알림톡 채널/템플릿 통합 관리 (2컬럼 마스터-디테일)
 *
 * @var string $pageTitle
 * @var array $channels
 * @var array $config
 * @var array $variableGroups  그룹라벨 => ['#{키}' => 설명] — NotificationTemplateUiHelper
 * @var array $companySamples  변수키(중괄호 없음) => 실제값 — 미리보기 치환용
 * @var array $shopSample
 */
$channels = $channels ?? [];
$hasApiKey = !empty($config['api_id']) && !empty($config['api_password']);
$shopSample = $shopSample ?? [];
$variableGroups = $variableGroups ?? [];
$companySamples = $companySamples ?? [];
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>채널을 선택하면 우측에 해당 템플릿 목록이 표시됩니다.</p>
        </div>
    </div>

    <div class="page-block">
        <div class="alert alert-info">
        <div class="row">
            <div class="col-md-6 mb-2 mb-md-0">
                <strong><i class="bi bi-broadcast me-1"></i>채널이란?</strong>
                <div class="small mt-1">
                    카카오 알림톡을 발송할 카카오 비즈니스 채널입니다.<br>
                    <a href="https://sendon.io" target="_blank" rel="noopener">센드온(sendon.io)</a>에서 먼저 채널을 등록한 뒤,
                    <strong>[알림톡 프로필 가져오기]</strong> 버튼으로 불러오세요.
                </div>
            </div>
            <div class="col-md-6">
                <strong><i class="bi bi-card-text me-1"></i>템플릿이란?</strong>
                <div class="small mt-1">
                    주문 접수, 상태 변경 등 상황별 알림톡 양식입니다.<br>
                    채널을 선택하면 우측에서 <strong>템플릿을 추가/수정</strong>할 수 있습니다.<br>
                    본문에 <code>#{orderer_name}</code> 같은 변수를 넣으면 실제 데이터로 자동 치환됩니다.
                </div>
            </div>
        </div>
        </div>
    </div>

    <div class="row">
        <!-- 좌측: 채널 목록 -->
        <div class="col-lg-4">
            <div class="page-block">
                <div class="card">
                    <div class="card-hero">
                        <i class="bi bi-broadcast text-pastel-blue"></i>
                        <span>채널</span>
                        <span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle rounded-pill"><?= count($channels) ?></span>
                        <button type="button" class="btn btn-primary btn-xs ms-auto" onclick="fetchProfiles()" <?= !$hasApiKey ? 'disabled title="API 인증 정보를 먼저 설정하세요"' : '' ?>>
                            <i class="bi bi-cloud-download"></i> 알림톡 프로필 가져오기
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="list-group" id="channelList">
                <?php if (empty($channels)): ?>
                    <div class="list-group-item text-center text-muted py-4">
                        등록된 채널이 없습니다.<br>
                        <small>알림톡 프로필을 가져와 등록하세요.</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($channels as $ch):
                        $chId = (int) ($ch['channel_id'] ?? 0);
                        $chName = htmlspecialchars((string) ($ch['channel_name'] ?? ''));
                        $profileId = htmlspecialchars((string) ($ch['send_profile_id'] ?? ''));
                        $isActive = !empty($ch['is_active']);
                        $activeBadge = $isActive
                            ? '<span class="badge text-success-emphasis bg-success-subtle border border-success-subtle">활성</span>'
                            : '<span class="badge text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle">비활성</span>';
                        $chJson = htmlspecialchars(json_encode($ch, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?>
                    <a href="javascript:void(0)"
                       class="list-group-item list-group-item-action"
                       data-channel-id="<?= $chId ?>"
                       onclick="selectChannel(<?= $chJson ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold"><?= $chName ?></div>
                                <small class="text-muted">
                                    <?= $activeBadge ?>
                                    <span class="ms-1"><?= $profileId ?></span>
                                </small>
                            </div>
                            <div class="text-end">
                                <div class="mt-1 d-flex gap-1">
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                            onclick="event.stopPropagation(); openChannelModal(<?= $chJson ?>)" title="채널 수정">
                                        <i class="bi bi-pencil"></i> 수정
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                            onclick="event.stopPropagation(); deleteChannel(<?= $chId ?>)" title="채널 삭제">
                                        <i class="bi bi-trash"></i> 삭제
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

        <!-- 우측: 선택된 채널의 템플릿 -->
        <div class="col-lg-8">
            <div class="page-block sticky-top" style="top: calc(var(--header-height) + 1.5rem)">
                <div class="card">
                    <div class="card-hero">
                        <i class="bi bi-list-task text-pastel-green"></i>
                        <span id="templatePanelTitle">템플릿 목록</span>
                        <div class="d-flex gap-1 ms-auto">
                            <div id="syncBtn" style="display:none">
                                <button type="button" class="btn btn-outline-info btn-xs" onclick="syncTemplates()">
                                    <i class="bi bi-arrow-repeat"></i> 상태 동기화
                                </button>
                            </div>
                            <div id="addTemplateBtn" style="display:none">
                                <button type="button" class="btn btn-outline-secondary btn-xs" onclick="openTemplateModal()">
                                    <i class="bi bi-plus-lg"></i> 템플릿 추가
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                    <!-- 초기 상태 -->
                    <div id="emptyState" class="text-center text-muted py-5">
                        <i class="bi bi-folder2-open d-block mb-2" style="font-size: 2.5rem;"></i>
                        <p class="mb-0">좌측에서 채널을 선택하면<br>해당 템플릿 목록이 표시됩니다.</p>
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
                                        <th style="width:50px">번호</th>
                                        <th style="width:110px">코드</th>
                                        <th>이름</th>
                                        <th style="width:90px; text-align:center">카카오 상태</th>
                                        <th style="width:60px; text-align:center">LMS</th>
                                        <th style="width:60px; text-align:center">사용</th>
                                        <th style="width:130px; text-align:center">관리</th>
                                    </tr>
                                </thead>
                                <tbody id="templateTableBody"></tbody>
                            </table>
                        </div>
                        <div class="text-muted small mt-2" id="templateCountInfo"></div>
                    </div>
                    <!-- 템플릿 없음 -->
                    <div id="noTemplateState" class="text-center text-muted py-4" style="display:none">
                        <p class="mb-0">이 채널에 등록된 템플릿이 없습니다.</p>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ 프로필 가져오기 모달 ═══ -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-download me-2"></i>센드온 알림톡 프로필</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="profileLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                    프로필 목록을 가져오는 중...
                </div>
                <div id="profileError" class="alert alert-danger" style="display:none"></div>
                <div id="profileTableWrap" style="display:none">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>채널명</th>
                                    <th>프로필 ID</th>
                                    <th style="width:100px; text-align:center">상태</th>
                                    <th style="width:100px; text-align:center">등록</th>
                                </tr>
                            </thead>
                            <tbody id="profileTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div id="noProfileState" class="text-center text-muted py-4" style="display:none">
                    <p class="mb-0">등록된 프로필이 없습니다.<br>
                    <a href="https://sendon.io" target="_blank" rel="noopener">센드온(sendon.io)</a>에서 채널을 먼저 등록해주세요.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ 채널 등록/수정 모달 ═══ -->
<div class="modal fade" id="channelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="channelModalTitle">채널 등록</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="channelForm">
            <div class="modal-body">
                <input type="hidden" name="formData[channel_id]" id="ch_channel_id" value="0">
                <div class="row g-3 mb-3">
                    <div class="col-7">
                        <label class="form-label">채널명 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="formData[channel_name]" id="ch_channel_name" required
                               placeholder="예: 위즈렌탈">
                    </div>
                    <div class="col-5">
                        <label class="form-label">프로필 ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="formData[send_profile_id]" id="ch_profile_id" required
                               placeholder="센드온 프로필 ID">
                    </div>
                </div>
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="formData[is_active]" value="0">
                        <input class="form-check-input" type="checkbox" name="formData[is_active]" id="ch_is_active" value="1" checked>
                        <label class="form-check-label" for="ch_is_active">활성화</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary mublo-submit"
                        data-target="/admin/sendon-talk/channels/save"
                        data-callback="onChannelSaved">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ 템플릿 모달 ═══ -->
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
                <input type="hidden" name="formData[template_code]" id="tpl_template_code">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">템플릿명 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="formData[template_name]" id="tpl_template_name" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check form-switch mt-4">
                            <input type="checkbox" class="form-check-input" name="formData[fallback_sms]" id="tpl_fallback" value="1" checked>
                            <label class="form-check-label" for="tpl_fallback">LMS 대체 발송</label>
                        </div>
                    </div>
                </div>

                <!-- 좌우 2단: 참조변수 | 메시지 내용 -->
                <div class="row g-3 mb-3">
                    <!-- 좌측: 참조변수 -->
                    <div class="col-md-5">
                        <label class="form-label fw-bold"><i class="bi bi-braces me-1"></i>참조변수</label>
                        <div class="d-flex gap-1 flex-wrap mb-2">
                            <?php
                            $groupIdx = 0;
                            foreach ($variableGroups as $group => $vars):
                                $icons = ['핸드폰 쇼핑몰' => '📱', '쇼핑몰' => '🛒', '사업자/고객센터 정보' => '🏢', '자동폼 (공통)' => '📝'];
                                $icon = $icons[$group] ?? '📌';
                            ?>
                            <button type="button" class="btn btn-sm <?= $groupIdx === 0 ? 'btn-primary' : 'btn-outline-secondary' ?> tpl-var-tab"
                                    data-group="<?= $groupIdx ?>" onclick="switchVarTab(this, <?= $groupIdx ?>)">
                                <?= $icon ?> <?= htmlspecialchars($group) ?>
                            </button>
                            <?php $groupIdx++; endforeach; ?>
                        </div>
                        <div class="border rounded" style="height:320px;overflow-y:auto;">
                            <?php if (empty($variableGroups)): ?>
                            <div class="text-muted small p-3">사용 가능한 변수가 없습니다. 패키지/플러그인 활성 상태를 확인하세요.</div>
                            <?php endif; ?>
                            <?php $groupIdx = 0; foreach ($variableGroups as $group => $vars): ?>
                            <table class="table table-sm table-hover mb-0 tpl-var-table" id="tplVarTable<?= $groupIdx ?>"
                                   style="<?= $groupIdx > 0 ? 'display:none' : '' ?>">
                                <tbody>
                                    <?php foreach ($vars as $key => $desc):
                                        // 표시 키는 #{키} 형태 — insertVar 에는 중괄호 없는 원시 키 전달
                                        $rawKey = preg_replace('/^#\{(.+)\}$/u', '$1', (string) $key);
                                    ?>
                                    <tr style="cursor:pointer" onclick="insertVar('<?= htmlspecialchars($rawKey, ENT_QUOTES) ?>')" title="클릭하여 삽입">
                                        <td><code class="small"><?= htmlspecialchars($key) ?></code></td>
                                        <td class="text-muted small"><?= htmlspecialchars($desc) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php $groupIdx++; endforeach; ?>
                        </div>
                    </div>

                    <!-- 우측: 메시지 내용 -->
                    <div class="col-md-7">
                        <label class="form-label fw-bold"><i class="bi bi-chat-left-text me-1"></i>메시지 내용</label>

                        <!-- 예문 템플릿 버튼 -->
                        <div class="d-flex gap-2 mb-2">
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                    📋 주문 예문
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="applyPreset('order','received'); return false;">주문접수</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="applyPreset('order','status_changed'); return false;">상태변경</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="applyPreset('order','shipped'); return false;">배송안내</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="applyPreset('order','opened'); return false;">개통완료</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="applyPreset('order','cancelled'); return false;">주문취소</a></li>
                                </ul>
                            </div>
                        </div>

                        <small class="text-muted d-block mb-1">좌측 변수를 클릭하면 커서 위치에 삽입됩니다. (최대 1,000자)</small>
                        <textarea class="form-control" name="formData[message_body]" id="tpl_message_body" rows="6"
                                  style="resize:vertical" oninput="updatePreview()" maxlength="1000"
                                  placeholder="[#{사이트명}] #{orderer_name}님, 주문이 접수되었습니다.&#10;주문번호: #{order_code}&#10;기기: #{device_name}&#10;월 할부금: #{monthly_installment}원"></textarea>
                        <div class="d-flex justify-content-between mt-1 mb-3">
                            <div class="form-text" id="tpl_char_count">0 / 1,000자</div>
                        </div>

                        <label class="form-label fw-bold"><i class="bi bi-eye me-1"></i>미리보기 <small class="text-muted fw-normal">— 변수가 실제 데이터로 치환된 예시</small></label>
                        <div id="tplPreviewBox" class="border rounded p-3 bg-light small"
                             style="min-height:80px;max-height:160px;overflow-y:auto;white-space:pre-wrap;color:#334155;line-height:1.6">
                            <span class="text-muted">본문을 입력하면 미리보기가 표시됩니다.</span>
                        </div>
                        <div id="tplUnmappedWarn" class="text-warning small mt-1" style="display:none">
                            <i class="bi bi-exclamation-triangle me-1"></i><span></span>
                        </div>
                    </div>
                </div>

                <!-- 버튼 설정 -->
                <hr class="my-3">
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-bold mb-0"><i class="bi bi-hand-index me-1"></i>버튼 (최대 5개)</label>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addButton('AC')">
                                <i class="bi bi-plus-circle"></i> 채널 추가
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addButton('WL')">
                                <i class="bi bi-link-45deg"></i> 웹 링크
                            </button>
                        </div>
                    </div>
                    <div id="tpl_buttons_area"></div>
                    <input type="hidden" name="formData[buttons]" id="tpl_buttons_json" value="">
                </div>

                <hr class="my-3">
                <div class="mb-3">
                    <label class="form-label fw-bold">관리자 수신번호</label>
                    <input type="text" class="form-control" name="formData[admin_recipients]" id="tpl_admin_recipients"
                           placeholder="010-1234-5678, 010-9876-5432">
                    <div class="form-text">쉼표(,)로 구분. 발송 시 이 번호들에도 알림톡이 함께 발송됩니다.</div>
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
                        data-target="/admin/sendon-talk/templates/save"
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
MubloRequest.configure({ errorHandler: function (e) {
    alert((e && e.message) || '오류가 발생했습니다.');
} });

document.addEventListener('DOMContentLoaded', function() {
    var selectedChannelId = 0;
    var selectedChannelName = '';
    var _shopSample = <?= json_encode($shopSample, JSON_UNESCAPED_UNICODE) ?>;
    var _companySamples = <?= json_encode($companySamples, JSON_UNESCAPED_UNICODE) ?>;
    <?php
    // 원시 변수키 => 설명 (미리보기에서 샘플값 없는 변수의 라벨 폴백용)
    $flatLabels = [];
    foreach ($variableGroups as $vars) {
        foreach ($vars as $key => $desc) {
            $flatLabels[preg_replace('/^#\{(.+)\}$/u', '$1', (string) $key)] = (string) $desc;
        }
    }
    ?>
    var _variableLabels = <?= json_encode($flatLabels, JSON_UNESCAPED_UNICODE) ?>;

    function esc(text) { var d=document.createElement('div'); d.textContent=text; return d.innerHTML; }

    var statusLabels = {
        'draft':['📝 작성중','text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle'], 'pending':['⏳ 심사중','text-warning-emphasis bg-warning-subtle border border-warning-subtle'],
        'approved':['✅ 승인','text-success-emphasis bg-success-subtle border border-success-subtle'], 'rejected':['❌ 반려','text-danger-emphasis bg-danger-subtle border border-danger-subtle']
    };

    // ── 샘플값 (미리보기용) ──
    // 데모값 + 사업자정보 실제값(_companySamples) 병합. 실발송 치환 키(영문)와 동일한 키 사용.
    var sampleValues = Object.assign({
        'order_code':'ORD-260724-AB12CD34','order_type':'번호이동','channel_code':'online',
        'order_status':'received','prev_status':'주문접수','new_status':'개통완료',
        'device_name':'갤럭시 S26 울트라','model_code':'SM-S941N','device_color':'티타늄 블랙','device_giga':'256','telecom':'KT',
        'bill_name':'5G 슬림','bill_code':'5G_SLIM','bill_price':'55,000','sales_type':'공시','sales_period':'24','installment_months':'24',
        'device_price':'1,450,000','gongsi_price':'350,000','point_discount':'50,000','actual_price':'1,050,000',
        'installment_price':'1,050,000','monthly_installment':'45,600',
        'orderer_name':'홍길동','orderer_phone':'010-1234-5678','orderer_email':'hong@example.com',
        'recipient_name':'홍길동','recipient_phone':'010-1234-5678','shipping_address':'06134 서울 강남구 테헤란로 123',
        'delivery_company':'CJ대한통운','delivery_number':'1234567890','open_date':'2026-07-25',
        'staff_name':'김담당','created_at':'2026-07-24 10:30',
        '사이트명': _shopSample.shop_name || '쇼핑몰',
        '고객센터번호': _shopSample.customer_tel || '1588-0000',
        '도메인': _shopSample.domain || 'example.com'
    }, _companySamples);

    // ── 프리셋 (Mshop 실발송 치환 키 기준) ──
    var PRESETS = {
        order: {
            received: { name:'주문접수 알림', body:'[#{사이트명}] 주문접수 안내\n\n#{orderer_name}님, 주문이 접수되었습니다.\n\n■ 주문번호: #{order_code}\n■ 기기: #{device_name} #{device_giga}GB (#{device_color})\n■ 통신사: #{telecom}\n■ 월 할부금: #{monthly_installment}원\n\n처리 진행 상황은 순차 안내드리겠습니다.\n문의: #{고객센터번호}' },
            status_changed: { name:'상태변경 알림', body:'[#{사이트명}] 주문상태 변경 안내\n\n#{orderer_name}님, 주문 상태가 변경되었습니다.\n\n■ 주문번호: #{order_code}\n■ #{prev_status} → #{new_status}\n■ 기기: #{device_name}\n\n문의: #{고객센터번호}' },
            shipped: { name:'배송안내 알림', body:'[#{사이트명}] 배송 안내\n\n#{orderer_name}님, 상품이 발송되었습니다.\n\n■ 주문번호: #{order_code}\n■ 기기: #{device_name}\n■ 배송사: #{delivery_company}\n■ 송장번호: #{delivery_number}\n\n문의: #{고객센터번호}' },
            opened: { name:'개통완료 알림', body:'[#{사이트명}] 개통완료 안내\n\n#{orderer_name}님, 개통이 완료되었습니다.\n\n■ 주문번호: #{order_code}\n■ 기기: #{device_name}\n■ 개통일: #{open_date}\n■ 요금제: #{bill_name}\n\n이용해 주셔서 감사합니다.\n문의: #{고객센터번호}' },
            cancelled: { name:'주문취소 알림', body:'[#{사이트명}] 주문취소 안내\n\n#{orderer_name}님, 주문이 취소되었습니다.\n\n■ 주문번호: #{order_code}\n■ 기기: #{device_name}\n\n문의: #{고객센터번호}' }
        }
    };

    // ════════════ 프로필 가져오기 ════════════
    window.fetchProfiles = function() {
        var modal = new bootstrap.Modal(document.getElementById('profileModal'));
        modal.show();

        document.getElementById('profileLoading').style.display = '';
        document.getElementById('profileError').style.display = 'none';
        document.getElementById('profileTableWrap').style.display = 'none';
        document.getElementById('noProfileState').style.display = 'none';

        // 현재 등록된 프로필 ID 목록
        var registeredIds = [];
        document.querySelectorAll('#channelList [data-channel-id]').forEach(function(el) {
            var chData = el.getAttribute('onclick');
            // data-channel-id로부터 추출 대신, 서버 데이터 사용
        });
        <?php
        $registeredIds = array_map(fn($ch) => $ch['send_profile_id'] ?? '', $channels);
        ?>
        registeredIds = <?= json_encode(array_values(array_filter($registeredIds))) ?>;

        MubloRequest.requestQuery('/admin/sendon-talk/channels/fetch')
            .then(function(res) {
                document.getElementById('profileLoading').style.display = 'none';
                if (res.result !== 'success') {
                    var err = document.getElementById('profileError');
                    err.textContent = res.message || '프로필을 가져올 수 없습니다.';
                    err.style.display = '';
                    return;
                }
                var raw = res.data && res.data.profiles ? res.data.profiles : [];
                var profiles = Array.isArray(raw) ? raw : (raw.sendProfiles || []);
                if (!profiles.length) {
                    document.getElementById('noProfileState').style.display = '';
                    return;
                }
                renderProfileTable(profiles, registeredIds);
                document.getElementById('profileTableWrap').style.display = '';
            });
    };

    function renderProfileTable(profiles, registeredIds) {
        var tbody = document.getElementById('profileTableBody');
        var html = '';
        profiles.forEach(function(p) {
            var id = p.id || p.sendProfileId || '';
            var name = p.channelName || p.profileName || id;
            var status = p.status || '';
            var isRegistered = registeredIds.indexOf(id) !== -1;

            html += '<tr>';
            html += '<td><strong>' + esc(name) + '</strong></td>';
            html += '<td><code class="small">' + esc(id) + '</code></td>';
            html += '<td class="text-center"><span class="badge ' + (status === 'ACTIVE' ? 'text-success-emphasis bg-success-subtle border border-success-subtle' : 'text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle') + '">' + esc(status || '-') + '</span></td>';
            html += '<td class="text-center">';
            if (isRegistered) {
                html += '<span class="badge text-info-emphasis bg-info-subtle border border-info-subtle">등록됨</span>';
            } else {
                html += '<button type="button" class="btn btn-primary btn-sm" onclick="registerProfile(\'' + esc(id).replace(/'/g,'\\\'') + '\',\'' + esc(name).replace(/'/g,'\\\'') + '\')"><i class="bi bi-plus-lg"></i> 등록</button>';
            }
            html += '</td></tr>';
        });
        tbody.innerHTML = html;
    }

    window.registerProfile = function(profileId, name) {
        bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide();
        document.getElementById('ch_channel_id').value = 0;
        document.getElementById('ch_channel_name').value = name;
        document.getElementById('ch_profile_id').value = profileId;
        document.getElementById('ch_is_active').checked = true;
        document.getElementById('channelModalTitle').textContent = '채널 등록';
        new bootstrap.Modal(document.getElementById('channelModal')).show();
    };

    // ════════════ 채널 선택 ════════════
    window.selectChannel = function(ch) {
        selectedChannelId = parseInt(ch.channel_id);
        selectedChannelName = ch.channel_name || '';

        document.querySelectorAll('#channelList .list-group-item').forEach(function(el) { el.classList.remove('active'); });
        var target = document.querySelector('#channelList [data-channel-id="'+selectedChannelId+'"]');
        if (target) target.classList.add('active');

        document.getElementById('templatePanelTitle').textContent = '템플릿 — ' + selectedChannelName;
        document.getElementById('addTemplateBtn').style.display = '';
        document.getElementById('syncBtn').style.display = '';

        loadTemplates(selectedChannelId);
    };

    function loadTemplates(channelId) {
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('noTemplateState').style.display = 'none';
        document.getElementById('templateTableWrap').style.display = 'none';
        document.getElementById('loadingState').style.display = '';

        MubloRequest.requestQuery('/admin/sendon-talk/templates/list', { channel_id: channelId })
            .then(function(res) {
                document.getElementById('loadingState').style.display = 'none';
                if (res.result !== 'success') return;
                var tpls = (res.data && res.data.templates) || [];
                if (!tpls.length) {
                    document.getElementById('noTemplateState').style.display = '';
                    return;
                }
                renderTemplateTable(tpls);
                document.getElementById('templateTableWrap').style.display = '';
                document.getElementById('templateCountInfo').textContent = '총 ' + tpls.length + '개';
            });
    }

    function renderTemplateTable(tpls) {
        var tbody = document.getElementById('templateTableBody');
        var html = '';
        tpls.forEach(function(tpl) {
            var s = tpl.kakao_status || 'draft';
            var badge = statusLabels[s] || ['?','text-secondary-emphasis bg-secondary-subtle border border-secondary-subtle'];
            var tplUri = encodeURIComponent(JSON.stringify(tpl));
            html += '<tr>';
            html += '<td>'+tpl.template_id+'</td>';
            html += '<td><code class="small">'+(tpl.template_code||'')+'</code></td>';
            html += '<td>'+esc(tpl.template_name||'');
            if (tpl.kakao_template_id) html += '<br><small class="text-muted">카카오: '+esc(tpl.kakao_template_id)+'</small>';
            if (s==='rejected' && tpl.kakao_rejected_reason) html += '<br><small class="text-danger">'+esc(tpl.kakao_rejected_reason)+'</small>';
            html += '</td>';
            html += '<td class="text-center"><span class="badge '+badge[1]+'">'+badge[0]+'</span></td>';
            html += '<td class="text-center">'+(parseInt(tpl.fallback_sms)?'<span class="badge text-info-emphasis bg-info-subtle border border-info-subtle">ON</span>':'-')+'</td>';
            html += '<td class="text-center">'+(parseInt(tpl.is_active)?'<i class="bi bi-check-circle-fill text-success"></i>':'<i class="bi bi-dash-circle text-secondary"></i>')+'</td>';
            html += '<td class="text-center"><div class="btn-group btn-group-sm">';
            html += '<button class="btn btn-outline-primary" onclick="openTemplateModal(JSON.parse(decodeURIComponent(\''+tplUri+'\')))" title="수정"><i class="bi bi-pencil"></i></button>';
            if (s==='draft'||s==='rejected') html += '<button class="btn btn-outline-warning" onclick="registerKakao('+tpl.template_id+')" title="카카오 심사"><i class="bi bi-send"></i></button>';
            else if (s==='pending') html += '<button class="btn btn-outline-info" onclick="checkKakaoStatus('+tpl.template_id+')" title="상태 확인"><i class="bi bi-arrow-repeat"></i></button>';
            html += '<button class="btn btn-outline-danger" onclick="deleteTpl('+tpl.template_id+')" title="삭제"><i class="bi bi-trash"></i></button>';
            html += '</div></td></tr>';
        });
        tbody.innerHTML = html;
    }

    // ════════════ 채널 모달 ════════════
    window.openChannelModal = function(ch) {
        var isEdit = ch && ch.channel_id;
        document.getElementById('channelModalTitle').textContent = isEdit ? '채널 수정' : '채널 등록';
        document.getElementById('ch_channel_id').value = isEdit ? ch.channel_id : 0;
        document.getElementById('ch_channel_name').value = isEdit ? (ch.channel_name||'') : '';
        document.getElementById('ch_profile_id').value = isEdit ? (ch.send_profile_id||'') : '';
        document.getElementById('ch_is_active').checked = isEdit ? !!parseInt(ch.is_active) : true;
        new bootstrap.Modal(document.getElementById('channelModal')).show();
    };

    MubloRequest.registerCallback('onChannelSaved', function(res) {
        MubloRequest.showToast(res.message || '저장되었습니다.', res.result === 'success' ? 'success' : 'error');
        if (res.result === 'success') setTimeout(function() { location.reload(); }, 800);
    });

    window.deleteChannel = function(channelId) {
        MubloRequest.showConfirm('이 채널을 삭제하시겠습니까?\n채널에 템플릿이 있으면 삭제할 수 없습니다.', function() {
            MubloRequest.requestJson('/admin/sendon-talk/channels/delete', { channel_id: channelId })
                .then(function(res) {
                    MubloRequest.showToast(res.message||'삭제', res.result==='success' ? 'success' : 'error');
                    if (res.result==='success') setTimeout(function() { location.reload(); }, 800);
                });
        }, { type: 'warning' });
    };

    // ════════════ 템플릿 모달 ════════════
    window.openTemplateModal = function(data) {
        var isEdit = data && data.template_id;
        document.getElementById('templateModalTitle').textContent = isEdit ? '템플릿 수정' : '템플릿 등록';
        document.getElementById('tpl_template_id').value = isEdit ? data.template_id : 0;
        document.getElementById('tpl_channel_id').value = isEdit ? (data.channel_id||selectedChannelId) : selectedChannelId;
        document.getElementById('tpl_template_code').value = isEdit ? (data.template_code||'') : '';
        document.getElementById('tpl_template_name').value = isEdit ? (data.template_name||'') : '';
        document.getElementById('tpl_message_body').value = isEdit ? (data.message_body||'') : '';
        document.getElementById('tpl_admin_recipients').value = isEdit ? (data.admin_recipients||'') : '';
        document.getElementById('tpl_fallback').checked = isEdit ? !!parseInt(data.fallback_sms) : true;
        document.getElementById('tpl_is_active').checked = isEdit ? !!parseInt(data.is_active) : true;
        loadButtons(isEdit ? (data.buttons || null) : null);
        updatePreview();
        new bootstrap.Modal(document.getElementById('templateModal')).show();
    };

    MubloRequest.registerCallback('onTemplateSaved', function(res) {
        MubloRequest.showToast(res.message || '저장되었습니다.', res.result === 'success' ? 'success' : 'error');
        bootstrap.Modal.getInstance(document.getElementById('templateModal'))?.hide();
        if (selectedChannelId) loadTemplates(selectedChannelId);
    });

    // ── 변수 탭 전환 ──
    window.switchVarTab = function(btn, idx) {
        document.querySelectorAll('.tpl-var-tab').forEach(function(b) { b.className = b.className.replace('btn-primary','btn-outline-secondary'); });
        btn.className = btn.className.replace('btn-outline-secondary','btn-primary');
        document.querySelectorAll('.tpl-var-table').forEach(function(t) { t.style.display = 'none'; });
        var table = document.getElementById('tplVarTable'+idx);
        if (table) table.style.display = '';
    };

    // ── 변수 삽입 ──
    window.insertVar = function(key) {
        var ta = document.getElementById('tpl_message_body');
        var tag = '#{' + key + '}';
        var start = ta.selectionStart, end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + tag + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = start + tag.length;
        ta.focus();
        updatePreview();
    };

    // ── 프리셋 적용 ──
    window.applyPreset = function(type, key) {
        var tpl = PRESETS[type] && PRESETS[type][key];
        if (!tpl) return;
        document.getElementById('tpl_template_name').value = tpl.name;
        document.getElementById('tpl_message_body').value = tpl.body;
        updatePreview();
    };

    // ── 미리보기 ──
    window.updatePreview = function() {
        var body = document.getElementById('tpl_message_body').value;
        document.getElementById('tpl_char_count').textContent = body.length + ' / 1,000자';

        var unmapped = [];
        var preview = esc(body).replace(/#\{([^}]+)\}/g, function(match, key) {
            if (sampleValues[key] !== undefined) {
                return '<strong class="text-primary">' + esc(sampleValues[key]) + '</strong>';
            }
            if (_variableLabels[key] !== undefined) {
                // 샘플값은 없지만 등록된 변수 — 라벨로 표시 (발송 시 실데이터 치환)
                return '<strong class="text-info">[' + esc(_variableLabels[key]) + ']</strong>';
            }
            unmapped.push(match);
            return '<span class="text-danger fw-bold">' + esc(match) + '</span>';
        });

        document.getElementById('tplPreviewBox').innerHTML = preview || '<span class="text-muted">본문을 입력하면 미리보기가 표시됩니다.</span>';

        var warn = document.getElementById('tplUnmappedWarn');
        if (unmapped.length) {
            warn.querySelector('span').textContent = '미매핑 변수: ' + unmapped.join(', ');
            warn.style.display = '';
        } else {
            warn.style.display = 'none';
        }
    };

    // ── 카카오 심사 ──
    window.registerKakao = function(tid) {
        MubloRequest.showConfirm('이 템플릿을 카카오에 심사 요청하시겠습니까?\n심사는 1~3 영업일 소요됩니다.', function() {
            MubloRequest.requestJson('/admin/sendon-talk/templates/register-kakao', { template_id: tid })
                .then(function(res) {
                    MubloRequest.showToast(res.message||'완료', res.result==='success' ? 'success' : 'error');
                    if (selectedChannelId) loadTemplates(selectedChannelId);
                });
        }, { type: 'info' });
    };

    window.checkKakaoStatus = function(tid) {
        MubloRequest.requestQuery('/admin/sendon-talk/templates/check-kakao-status', { template_id: tid })
            .then(function(res) {
                var s = res.data && res.data.status ? res.data.status : '?';
                var l = {pending:'심사중', approved:'승인', rejected:'반려', draft:'작성중'};
                MubloRequest.showToast('카카오 상태: ' + (l[s]||s), s==='approved' ? 'success' : 'info');
                if (selectedChannelId) loadTemplates(selectedChannelId);
            });
    };

    window.syncTemplates = function() {
        MubloRequest.requestJson('/admin/sendon-talk/templates/sync', {}).then(function(res) {
            MubloRequest.showToast(res.message||'완료', res.result==='success' ? 'success' : 'warning');
            if (selectedChannelId) loadTemplates(selectedChannelId);
        });
    };

    window.deleteTpl = function(tid) {
        MubloRequest.showConfirm('이 템플릿을 삭제하시겠습니까?', function() {
            MubloRequest.requestJson('/admin/sendon-talk/templates/delete', { template_id: tid })
                .then(function(res) {
                    MubloRequest.showToast(res.message||'삭제', res.result==='success' ? 'success' : 'error');
                    if (selectedChannelId) loadTemplates(selectedChannelId);
                });
        }, { type: 'warning' });
    };

    // ── 버튼 편집 ──
    var BTN_TYPES = {
        'AC': { label: '채널 추가', needUrl: false },
        'WL': { label: '웹 링크', needUrl: true },
        'AL': { label: '앱 링크', needUrl: true },
        'BK': { label: '봇 키워드', needUrl: false },
        'MD': { label: '메시지 전달', needUrl: false },
        'DS': { label: '배송 조회', needUrl: false }
    };

    window.addButton = function(type) {
        var area = document.getElementById('tpl_buttons_area');
        if (area.querySelectorAll('.btn-row').length >= 5) {
            MubloRequest.showToast('버튼은 최대 5개까지 추가할 수 있습니다.', 'warning');
            return;
        }

        var info = BTN_TYPES[type] || BTN_TYPES['WL'];
        var div = document.createElement('div');
        div.className = 'input-group input-group-sm mb-2 btn-row';
        div.setAttribute('data-type', type);

        var html = '<span class="input-group-text" style="min-width:75px">' + esc(info.label) + '</span>'
            + '<input type="text" class="form-control btn-name" placeholder="버튼 이름" value="' + (type === 'AC' ? '채널 추가' : '') + '">';

        if (info.needUrl) {
            html += '<input type="text" class="form-control btn-url-mobile" placeholder="모바일 URL">'
                + '<input type="text" class="form-control btn-url-pc" placeholder="PC URL (선택)">';
        }

        html += '<button type="button" class="btn btn-outline-danger" onclick="this.closest(\'.btn-row\').remove(); collectButtons();">'
            + '<i class="bi bi-x-lg"></i></button>';

        div.innerHTML = html;
        area.appendChild(div);

        // 입력 변경 시 자동 수집
        div.querySelectorAll('input').forEach(function(inp) {
            inp.addEventListener('input', collectButtons);
        });
        collectButtons();
    };

    window.collectButtons = function() {
        var rows = document.querySelectorAll('#tpl_buttons_area .btn-row');
        var buttons = [];
        rows.forEach(function(row) {
            var type = row.getAttribute('data-type');
            var name = (row.querySelector('.btn-name') || {}).value || '';
            if (!name) return;
            var btn = { type: type, name: name };
            var urlMobile = row.querySelector('.btn-url-mobile');
            var urlPc = row.querySelector('.btn-url-pc');
            if (urlMobile) btn.url_mobile = urlMobile.value || '';
            if (urlPc) btn.url_pc = urlPc.value || '';
            buttons.push(btn);
        });
        document.getElementById('tpl_buttons_json').value = buttons.length ? JSON.stringify(buttons) : '';
    }

    window.loadButtons = function(buttonsData) {
        var area = document.getElementById('tpl_buttons_area');
        area.innerHTML = '';
        if (!buttonsData) return;
        var buttons = typeof buttonsData === 'string' ? JSON.parse(buttonsData) : buttonsData;
        if (!Array.isArray(buttons)) return;
        buttons.forEach(function(btn) {
            addButton(btn.type || 'WL');
            var rows = area.querySelectorAll('.btn-row');
            var lastRow = rows[rows.length - 1];
            if (lastRow.querySelector('.btn-name')) lastRow.querySelector('.btn-name').value = btn.name || '';
            if (lastRow.querySelector('.btn-url-mobile')) lastRow.querySelector('.btn-url-mobile').value = btn.url_mobile || '';
            if (lastRow.querySelector('.btn-url-pc')) lastRow.querySelector('.btn-url-pc').value = btn.url_pc || '';
        });
        collectButtons();
    }

    // ── 첫 채널 자동 선택 ──
    var firstCh = document.querySelector('#channelList [data-channel-id]');
    if (firstCh) firstCh.click();
});
</script>

