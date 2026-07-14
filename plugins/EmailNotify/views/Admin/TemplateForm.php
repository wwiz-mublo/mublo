<?php
/**
 * 이메일 알림 - 템플릿 등록/수정 폼
 *
 * @var string     $pageTitle
 * @var string     $mode               'create' | 'edit'
 * @var array|null $template           수정 시 템플릿 데이터
 * @var array      $availableVariables 소스별 그룹화된 치환 변수
 */
$mode = $mode ?? 'create';
$isEdit = ($mode === 'edit');
$template = $template ?? [];
$availableVariables = $availableVariables ?? [];

$templateId = (int) ($template['template_id'] ?? 0);
$code = (string) ($template['template_code'] ?? '');
$name = (string) ($template['template_name'] ?? '');
$subject = (string) ($template['subject'] ?? '');
$body = (string) ($template['body'] ?? '');
$isActive = $isEdit ? !empty($template['is_active']) : true;
?>
<form id="templateForm">
    <?php if ($isEdit): ?>
    <input type="hidden" name="formData[template_id]" value="<?= $templateId ?>">
    <?php endif; ?>

    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>제목·본문의 <code>#{변수}</code> 토큰은 발송 시 실제 값으로 치환됩니다.</p>
            </div>
            <div class="page-title-actions">
                <a href="/admin/email-notify/templates" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/email-notify/templates/save"
                        data-callback="onTemplateFormSaved"
                        data-loading="true">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <div class="page-block row">
            <!-- 좌: 본문 -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-envelope-paper-heart text-pastel-blue"></i>
                        <span>템플릿 내용</span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">템플릿 코드 <span class="text-danger">*</span></label>
                                <?php if ($isEdit): ?>
                                <input type="text" class="form-control fw-bold"
                                       name="formData[template_code]"
                                       value="<?= htmlspecialchars($code) ?>" readonly>
                                <div class="form-text">코드는 변경할 수 없습니다.</div>
                                <?php else: ?>
                                <input type="text" class="form-control" name="formData[template_code]"
                                       placeholder="예: order_confirmed" required>
                                <div class="form-text">발송 시 이 코드로 템플릿을 찾습니다.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">템플릿명 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="formData[template_name]"
                                       value="<?= htmlspecialchars($name) ?>" placeholder="예: 주문 확정 안내" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">메일 제목 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="formData[subject]" id="tpl_subject"
                                   value="<?= htmlspecialchars($subject) ?>"
                                   placeholder="예: #{order_no} 주문이 확정되었습니다" required>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">메일 본문 (HTML)</label>
                            <textarea class="form-control" name="formData[body]" id="tpl_body" rows="16"
                                      placeholder="<p>#{orderer_name}님, 주문(#{order_no})이 확정되었습니다.</p>"><?= htmlspecialchars($body) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 우: 설정 / 변수 / 테스트 -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-toggles text-pastel-green"></i>
                        <span>사용 설정</span>
                    </div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="tpl_is_active"
                                   name="formData[is_active]" value="1" <?= $isActive ? 'checked' : '' ?>>
                            <label class="form-check-label" for="tpl_is_active">사용</label>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-braces text-pastel-orange"></i>
                        <span>변수 삽입</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-2">제목/본문 중 마지막으로 클릭한 입력란에 추가됩니다.</p>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <?php foreach ($availableVariables as $sourceKey => $source): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="toggleVarPanel('<?= htmlspecialchars($sourceKey) ?>')">
                                <i class="bi <?= $sourceKey === 'site' ? 'bi-globe' : 'bi-list-ul' ?>"></i> <?= htmlspecialchars($source['label']) ?>
                            </button>
                            <?php if ($sourceKey === 'site'): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleImagePanel()">
                                <i class="bi bi-image"></i> 이미지
                            </button>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div id="varPanel" style="display:none">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>필드</th>
                                        <th>설명</th>
                                        <th style="width:48px" class="text-center">삽입</th>
                                    </tr>
                                </thead>
                                <tbody id="varPanelBody"></tbody>
                            </table>
                        </div>

                        <div id="imagePanel" style="display:none">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <input type="file" class="form-control form-control-sm" id="imageFile" accept="image/*">
                                <button type="button" class="btn btn-sm btn-primary text-nowrap" id="btnImageUpload">
                                    <i class="bi bi-upload"></i> 업로드
                                </button>
                            </div>
                            <p class="text-muted small mb-2">행을 클릭하면 본문에 <code>&lt;img&gt;</code>로 삽입됩니다. (한번 올려 여러 번 재사용)</p>
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:56px">미리보기</th>
                                        <th>파일</th>
                                        <th style="width:48px" class="text-center">삭제</th>
                                    </tr>
                                </thead>
                                <tbody id="imagePanelBody">
                                    <tr><td colspan="3" class="text-center text-muted py-3">불러오는 중...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-send text-pastel-blue"></i>
                        <span>테스트 발송</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label">수신 이메일</label>
                            <input type="email" class="form-control form-control-sm" id="test_recipient" placeholder="test@example.com">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">치환 변수 (JSON, 선택)</label>
                            <textarea class="form-control form-control-sm" id="test_field_values" rows="3"
                                      placeholder='{"orderer_name": "홍길동", "order_no": "20260608-0001"}'></textarea>
                            <div class="form-text">본문에 <code>#{변수}</code>가 있으면 값을 넣어야 미치환 오류가 없습니다.</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnSendTest"
                                data-code="<?= htmlspecialchars($code) ?>">
                            <i class="bi bi-send"></i> 테스트 발송
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>var _availableVariables = <?= json_encode($availableVariables ?: new \stdClass(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script>
MubloRequest.registerCallback('onTemplateFormSaved', function(response) {
    alert(response.message || '저장되었습니다.');
    if (response.result === 'success') {
        location.href = '/admin/email-notify/templates';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // 마지막으로 포커스된 입력란(제목/본문) 추적 → 변수 삽입 대상
    var lastFocused = document.getElementById('tpl_body');
    ['tpl_subject', 'tpl_body'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('focus', function() { lastFocused = el; });
    });

    function escHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : text;
        return div.innerHTML;
    }

    function insertVar(token) {
        var el = lastFocused || document.getElementById('tpl_body');
        if (!el) return;
        var start = el.selectionStart !== undefined ? el.selectionStart : el.value.length;
        var end = el.selectionEnd !== undefined ? el.selectionEnd : el.value.length;
        el.value = el.value.substring(0, start) + token + el.value.substring(end);
        var pos = start + token.length;
        el.focus();
        if (el.setSelectionRange) el.setSelectionRange(pos, pos);
    }

    window.toggleVarPanel = function(sourceKey) {
        var panel = document.getElementById('varPanel');
        var body = document.getElementById('varPanelBody');
        if (!panel || !body) return;

        var source = _availableVariables[sourceKey];
        if (!source) return;

        document.getElementById('imagePanel').style.display = 'none';

        body.innerHTML = '';
        var vars = source.variables || {};
        Object.keys(vars).forEach(function(field) {
            var tr = document.createElement('tr');
            tr.className = 'cursor-pointer';
            tr.innerHTML = '<td><code>#{' + field + '}</code></td>'
                + '<td>' + escHtml(vars[field]) + '</td>'
                + '<td class="text-center"><i class="bi bi-plus-circle text-primary"></i></td>';
            tr.addEventListener('click', function() { insertVar('#{' + field + '}'); });
            body.appendChild(tr);
        });

        panel.style.display = panel.style.display === 'none' ? '' : 'none';
    };

    // === 이미지 라이브러리 (도메인 공용) ===
    var imageLoaded = false;

    window.toggleImagePanel = function() {
        var panel = document.getElementById('imagePanel');
        document.getElementById('varPanel').style.display = 'none';
        var show = panel.style.display === 'none';
        panel.style.display = show ? '' : 'none';
        if (show && !imageLoaded) { loadImages(); }
    };

    function loadImages() {
        var body = document.getElementById('imagePanelBody');
        MubloRequest.requestJson('/admin/email-notify/images', {}, { method: 'GET' })
            .then(function(res) {
                imageLoaded = true;
                var items = (res.data && res.data.items) || [];
                if (!items.length) {
                    body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">업로드된 이미지가 없습니다.</td></tr>';
                    return;
                }
                body.innerHTML = '';
                items.forEach(function(img) {
                    var tr = document.createElement('tr');
                    var thumb = document.createElement('td');
                    thumb.innerHTML = '<img src="' + img.url + '" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px" class="cursor-pointer">';
                    var nameTd = document.createElement('td');
                    nameTd.className = 'cursor-pointer small text-break';
                    nameTd.textContent = img.filename;
                    var delTd = document.createElement('td');
                    delTd.className = 'text-center';
                    var delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn btn-sm btn-outline-danger py-0 px-1';
                    delBtn.innerHTML = '<i class="bi bi-trash"></i>';
                    delBtn.addEventListener('click', function(e) { e.stopPropagation(); deleteImage(img.filename); });
                    delTd.appendChild(delBtn);

                    // 썸네일/파일명 클릭 → 본문에 <img> 삽입
                    [thumb, nameTd].forEach(function(td) {
                        td.addEventListener('click', function() { insertImage(img.url); });
                    });

                    tr.appendChild(thumb);
                    tr.appendChild(nameTd);
                    tr.appendChild(delTd);
                    body.appendChild(tr);
                });
            })
            .catch(function(err) {
                body.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-3">' + ((err && err.message) || '불러오기 실패') + '</td></tr>';
            });
    }

    function insertImage(url) {
        // 이미지는 항상 본문에 삽입
        var el = document.getElementById('tpl_body');
        var tag = '<img src="' + url + '" alt="" style="max-width:100%">';
        var start = el.selectionStart !== undefined ? el.selectionStart : el.value.length;
        var end = el.selectionEnd !== undefined ? el.selectionEnd : el.value.length;
        el.value = el.value.substring(0, start) + tag + el.value.substring(end);
        var pos = start + tag.length;
        el.focus();
        if (el.setSelectionRange) el.setSelectionRange(pos, pos);
    }

    function deleteImage(filename) {
        if (!confirm('이 이미지를 삭제하시겠습니까?')) return;
        doDeleteImage(filename, false);
    }

    function doDeleteImage(filename, force) {
        MubloRequest.requestJson('/admin/email-notify/images/delete', { filename: filename, force: !!force }, { loading: true })
            .then(function(res) {
                var d = res.data || {};
                if (d.inUse) {
                    var list = (d.templates || []).map(function(t) { return '- ' + t; }).join('\n');
                    if (confirm('다음 템플릿에서 사용 중입니다:\n' + list + '\n\n그래도 삭제하시겠습니까? 해당 템플릿의 이미지가 깨집니다.')) {
                        doDeleteImage(filename, true);
                    }
                    return;
                }
                loadImages();
            })
            .catch(function(err) { alert((err && err.message) || '삭제에 실패했습니다.'); });
    }

    var btnUpload = document.getElementById('btnImageUpload');
    if (btnUpload) {
        btnUpload.addEventListener('click', function() {
            var input = document.getElementById('imageFile');
            if (!input.files || !input.files.length) { alert('이미지 파일을 선택하세요.'); return; }

            var fd = new FormData();
            fd.append('file', input.files[0]);

            btnUpload.disabled = true;
            btnUpload.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 업로드';

            MubloRequest.sendRequest({
                method: 'POST',
                url: '/admin/email-notify/images/upload',
                payloadType: MubloRequest.PayloadType.FORM,
                data: fd,
                loading: true
            })
                .then(function() {
                    btnUpload.disabled = false;
                    btnUpload.innerHTML = '<i class="bi bi-upload"></i> 업로드';
                    input.value = '';
                    imageLoaded = false;
                    loadImages();
                })
                .catch(function(err) {
                    btnUpload.disabled = false;
                    btnUpload.innerHTML = '<i class="bi bi-upload"></i> 업로드';
                    alert((err && err.message) || '업로드에 실패했습니다.');
                });
        });
    }

    // 테스트 발송 (수정 모드)
    var btnTest = document.getElementById('btnSendTest');
    if (btnTest) {
        btnTest.addEventListener('click', function() {
            var code = btnTest.dataset.code;
            var recipient = document.getElementById('test_recipient').value.trim();
            var rawFv = document.getElementById('test_field_values').value.trim();

            if (!recipient) { alert('수신 이메일을 입력하세요.'); return; }

            var fieldValues = {};
            if (rawFv !== '') {
                try { fieldValues = JSON.parse(rawFv); }
                catch (e) { alert('치환 변수 JSON 형식이 올바르지 않습니다.'); return; }
            }

            btnTest.disabled = true;
            btnTest.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 발송 중...';

            MubloRequest.requestJson('/admin/email-notify/templates/test', {
                template_code: code,
                recipient: recipient,
                field_values: fieldValues
            }, { loading: true })
                .then(function(response) {
                    btnTest.disabled = false;
                    btnTest.innerHTML = '<i class="bi bi-send"></i> 테스트 발송';
                    alert(response.message || '발송되었습니다.');
                })
                .catch(function(err) {
                    btnTest.disabled = false;
                    btnTest.innerHTML = '<i class="bi bi-send"></i> 테스트 발송';
                    alert((err && err.message) || '발송에 실패했습니다.');
                });
        });
    }
});
</script>
