<?php
/** @var array $aiConfig */
/** @var array $aiAssets */
$providers = $aiConfig['providers'] ?? [];
$selectedProvider = $aiConfig['provider'] ?? 'openai';
$aiAssets = $aiAssets ?? [];
$imageCount = count(array_filter($aiAssets, static fn (array $asset): bool => ($asset['kind'] ?? '') === 'image'));
$documentCount = count($aiAssets) - $imageCount;
?>
<style>
.ai-settings-tabs { margin-bottom:1.25rem; }
.ai-asset-layout { display:grid; grid-template-columns:minmax(320px, 5fr) minmax(0, 7fr); gap:1rem; min-height:560px; }
.ai-asset-list { display:grid; grid-auto-rows:max-content; align-content:start; gap:.5rem; max-height:680px; overflow-y:auto; padding-right:.25rem; }
.ai-asset-item { display:grid; grid-template-columns:52px minmax(0,1fr) auto; gap:.75rem; align-items:center; width:100%; min-height:72px; height:auto; padding:.65rem; border:1px solid var(--bs-border-color); border-radius:.65rem; background:var(--bs-body-bg); color:inherit; text-align:left; }
.ai-asset-item:hover, .ai-asset-item.active { border-color:var(--bs-primary); background:var(--bs-primary-bg-subtle); }
.ai-asset-thumb { width:52px; height:52px; border-radius:.5rem; object-fit:cover; background:var(--bs-tertiary-bg); }
.ai-asset-icon { display:grid; place-items:center; font-size:1.4rem; }
.ai-asset-name { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:600; }
.ai-asset-detail { min-height:520px; }
.ai-asset-preview { display:grid; place-items:center; min-height:280px; max-height:440px; overflow:auto; border:1px solid var(--bs-border-color); border-radius:.75rem; background:var(--bs-tertiary-bg); }
.ai-asset-preview img { display:block; max-width:100%; max-height:420px; object-fit:contain; }
.ai-asset-text { width:100%; max-height:360px; overflow:auto; margin:0; padding:1rem; white-space:pre-wrap; overflow-wrap:anywhere; font:12px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace; }
.ai-asset-meta { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.65rem 1rem; }
.ai-asset-meta dt { color:var(--bs-secondary-color); font-size:.75rem; font-weight:500; }
.ai-asset-meta dd { margin:0; overflow-wrap:anywhere; }
@media (max-width:991.98px) { .ai-asset-layout { grid-template-columns:1fr; } .ai-asset-list { max-height:360px; } }
@media (max-width:575.98px) { .ai-asset-meta { grid-template-columns:1fr; } .ai-asset-detail .card-header { align-items:flex-start!important; } }
</style>
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? 'AI 설정') ?></h3>
            <p><?= htmlspecialchars($description ?? '') ?></p>
        </div>
    </div>

    <ul class="nav nav-tabs ai-settings-tabs" id="ai-settings-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="ai-config-tab" data-bs-toggle="tab" data-bs-target="#ai-config-pane" type="button" role="tab" aria-controls="ai-config-pane" aria-selected="true">
                <i class="bi bi-sliders me-1"></i> 설정
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ai-assets-tab" data-bs-toggle="tab" data-bs-target="#ai-assets-pane" type="button" role="tab" aria-controls="ai-assets-pane" aria-selected="false">
                <i class="bi bi-archive me-1"></i> 자산 목록 <span class="badge text-bg-secondary ms-1"><?= count($aiAssets) ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
    <div class="tab-pane fade show active" id="ai-config-pane" role="tabpanel" aria-labelledby="ai-config-tab" tabindex="0">
    <div class="alert alert-info d-flex gap-2 align-items-start">
        <i class="bi bi-shield-lock mt-1"></i>
        <div>
            API 키는 현재 도메인에만 적용되며 AES-256-GCM으로 암호화해 저장합니다.
            저장된 키는 화면이나 API 응답으로 다시 표시하지 않습니다.
        </div>
    </div>

    <form id="ai-settings-form">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-stars me-1"></i> 공급자 설정</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="ai-provider" class="form-label">AI 공급자</label>
                        <select id="ai-provider" name="formData[provider]" class="form-select">
                            <?php foreach ($providers as $id => $provider): ?>
                            <option value="<?= htmlspecialchars($id) ?>" <?= $id === $selectedProvider ? 'selected' : '' ?>>
                                <?= htmlspecialchars($provider['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="ai-model" class="form-label">모델</label>
                        <select id="ai-model" name="formData[model]" class="form-select"></select>
                    </div>
                    <div class="col-12" id="ai-anthropic-retention-notice" hidden>
                        <div class="alert alert-warning py-2 mb-0 small">
                            <i class="bi bi-info-circle me-1"></i>
                            Claude Fable 5는 Anthropic 정책상 30일 데이터 보존을 활성화한 조직 또는 워크스페이스에서만 사용할 수 있습니다.
                            <a href="https://platform.claude.com/docs/en/manage-claude/api-and-data-retention#model-specific-data-retention-requirements" target="_blank" rel="noopener">설정 안내</a>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="ai-api-key" class="form-label">API 키</label>
                        <input id="ai-api-key" name="formData[api_key]" type="password" class="form-control"
                               autocomplete="new-password" placeholder="<?= !empty($aiConfig['api_key_configured']) ? '등록된 키를 유지하려면 비워 두세요' : 'API 키를 입력하세요' ?>">
                        <div class="form-text">
                            현재 상태:
                            <?php if (!empty($aiConfig['api_key_configured'])): ?>
                                <span class="text-success"><i class="bi bi-check-circle"></i> 키 등록됨</span>
                            <?php else: ?>
                                <span class="text-muted">등록된 키 없음</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="ai-daily-limit" class="form-label">일일 요청 한도</label>
                        <input id="ai-daily-limit" name="formData[daily_request_limit]" type="number"
                               min="1" max="1000" class="form-control"
                               value="<?= (int) ($aiConfig['daily_request_limit'] ?? 50) ?>">
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input type="hidden" name="formData[is_enabled]" value="0">
                            <input id="ai-enabled" name="formData[is_enabled]" type="checkbox"
                                   class="form-check-input" value="1" <?= !empty($aiConfig['is_enabled']) ? 'checked' : '' ?>>
                            <label for="ai-enabled" class="form-check-label">이 도메인에서 AI 기능 사용</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-end">
            <?php if (!empty($aiConfig['api_key_configured'])): ?>
            <button type="button" class="btn btn-outline-danger mublo-submit"
                    data-target="/admin/ai-settings/remove-key"
                    data-callback="aiSettingsChanged"
                    data-confirm="등록된 API 키를 삭제하고 AI 기능을 비활성화할까요?">
                <i class="bi bi-trash"></i> 키 삭제
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary mublo-submit"
                    data-target="/admin/ai-settings/update"
                    data-callback="aiSettingsChanged">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </form>
    </div>

    <div class="tab-pane fade" id="ai-assets-pane" role="tabpanel" aria-labelledby="ai-assets-tab" tabindex="0">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div>
                <h5 class="mb-1">AI 참고 자산</h5>
                <p class="text-muted small mb-0">HTML 생성에 재사용하는 이미지와 문서를 현재 도메인 단위로 관리합니다.</p>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="badge text-bg-light border">이미지 <?= $imageCount ?></span>
                <span class="badge text-bg-light border">문서 <?= $documentCount ?></span>
                <label class="btn btn-sm btn-primary mb-0" style="cursor:pointer;">
                    <i class="bi bi-upload"></i> 자산 추가
                    <input type="file" id="ai-asset-upload" hidden multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.csv,.json,.docx,.xlsx,.pptx">
                </label>
            </div>
        </div>

        <div class="ai-asset-layout">
            <section class="card">
                <div class="card-header">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="ai-asset-search" placeholder="파일명, 제목, 확장자 검색">
                        <select class="form-select" id="ai-asset-kind" style="max-width:110px;">
                            <option value="">전체</option>
                            <option value="image">이미지</option>
                            <option value="document">문서</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-2 ai-asset-list" id="ai-asset-list">
                    <?php if (!$aiAssets): ?>
                        <div class="text-center text-muted py-5" id="ai-asset-empty"><i class="bi bi-archive fs-2 d-block mb-2"></i>저장된 자산이 없습니다.</div>
                    <?php else: foreach ($aiAssets as $asset): ?>
                        <?php $searchText = mb_strtolower(($asset['title'] ?? '') . ' ' . ($asset['name'] ?? '') . ' ' . ($asset['extension'] ?? '')); ?>
                        <button type="button" class="ai-asset-item" data-ai-asset-id="<?= (int) $asset['id'] ?>" data-kind="<?= htmlspecialchars($asset['kind'] ?? '') ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                            <?php if (($asset['kind'] ?? '') === 'image'): ?>
                                <img class="ai-asset-thumb" src="<?= htmlspecialchars($asset['preview_url'] ?? '') ?>" alt="">
                            <?php else: ?>
                                <span class="ai-asset-thumb ai-asset-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <?php endif; ?>
                            <span class="overflow-hidden">
                                <span class="ai-asset-name"><?= htmlspecialchars($asset['title'] ?: $asset['name']) ?></span>
                                <small class="text-muted"><?= htmlspecialchars(strtoupper($asset['extension'] ?? '')) ?> · <?= number_format(((int) ($asset['size'] ?? 0)) / 1024, 1) ?> KB</small>
                            </span>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </button>
                    <?php endforeach; endif; ?>
                    <div class="text-center text-muted py-5" id="ai-asset-no-results" hidden>검색 조건에 맞는 자산이 없습니다.</div>
                </div>
            </section>

            <section class="card ai-asset-detail" id="ai-asset-detail">
                <div class="card-body d-flex align-items-center justify-content-center text-center text-muted">
                    <div><i class="bi bi-cursor fs-2 d-block mb-2"></i>왼쪽 목록에서 자산을 선택하면 상세 정보를 확인할 수 있습니다.</div>
                </div>
            </section>
        </div>
    </div>
    </div>
</div>

<script>
MubloRequest.registerCallback('aiSettingsChanged', function(response) {
    if (response.result !== 'success') {
        MubloRequest.showAlert(response.message || 'AI 설정을 처리하지 못했습니다.', 'error');
        return;
    }
    MubloRequest.showToast(response.message || 'AI 설정이 저장되었습니다.', 'success');
    setTimeout(function() { window.location.reload(); }, 700);
});

(() => {
    const providers = <?= json_encode($providers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialModel = <?= json_encode($aiConfig['model'] ?? '') ?>;
    const providerSelect = document.getElementById('ai-provider');
    const modelSelect = document.getElementById('ai-model');
    const retentionNotice = document.getElementById('ai-anthropic-retention-notice');

    function updateProviderNotice() {
        if (!retentionNotice) return;
        retentionNotice.hidden = !(providerSelect.value === 'anthropic' && modelSelect.value === 'claude-fable-5');
    }

    function fillModels(preferred) {
        const provider = providers[providerSelect.value] || {};
        const models = provider.models || [];
        modelSelect.replaceChildren(...models.map(model => {
            const option = document.createElement('option');
            option.value = model;
            option.textContent = model;
            option.selected = model === preferred;
            return option;
        }));
        if (!models.includes(preferred) && provider.default_model) {
            modelSelect.value = provider.default_model;
        }
        updateProviderNotice();
    }

    providerSelect.addEventListener('change', () => fillModels(''));
    modelSelect.addEventListener('change', updateProviderNotice);
    fillModels(initialModel);
})();
</script>

<script>
(() => {
    const csrf = <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>;
    const list = document.getElementById('ai-asset-list');
    const detail = document.getElementById('ai-asset-detail');
    const search = document.getElementById('ai-asset-search');
    const kind = document.getElementById('ai-asset-kind');
    const upload = document.getElementById('ai-asset-upload');
    if (!list || !detail) return;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
    })[char]);
    const formatBytes = bytes => {
        const value = Number(bytes || 0);
        if (value >= 1048576) return (value / 1048576).toFixed(1) + ' MB';
        return Math.max(1, value / 1024).toFixed(1) + ' KB';
    };
    const readJson = async response => {
        const text = await response.text();
        if (!(response.headers.get('content-type') || '').includes('application/json')) {
            throw new Error(`서버가 JSON이 아닌 응답을 반환했습니다. (HTTP ${response.status})`);
        }
        try { return JSON.parse(text); }
        catch (_) { throw new Error('서버 응답 형식을 확인할 수 없습니다.'); }
    };
    const succeeded = json => json?.success === true || json?.result === 'success';

    function filterAssets() {
        const query = (search?.value || '').trim().toLocaleLowerCase();
        const selectedKind = kind?.value || '';
        let visible = 0;
        list.querySelectorAll('[data-ai-asset-id]').forEach(item => {
            const show = (!query || (item.dataset.search || '').includes(query))
                && (!selectedKind || item.dataset.kind === selectedKind);
            item.hidden = !show;
            if (show) visible++;
        });
        const noResults = document.getElementById('ai-asset-no-results');
        if (noResults) noResults.hidden = visible > 0 || list.querySelectorAll('[data-ai-asset-id]').length === 0;
    }

    async function showAsset(id, item) {
        list.querySelectorAll('[data-ai-asset-id]').forEach(button => button.classList.remove('active'));
        item?.classList.add('active');
        detail.innerHTML = '<div class="card-body d-flex align-items-center justify-content-center"><span class="spinner-border spinner-border-sm me-2"></span>자산 정보를 불러오는 중입니다.</div>';
        try {
            const response = await fetch('/admin/ai-settings/asset-detail?id=' + encodeURIComponent(id));
            const json = await readJson(response);
            if (!succeeded(json)) throw new Error(json.message || '자산 정보를 불러오지 못했습니다.');
            const asset = json.data || {};
            const meta = asset.metadata || {};
            const dimensions = meta.width && meta.height ? `${meta.width} × ${meta.height}px` : '-';
            const preview = asset.kind === 'image'
                ? `<div class="ai-asset-preview"><img src="${escapeHtml(asset.file_url)}" alt="${escapeHtml(asset.title || asset.name)}"></div>`
                : `<div class="ai-asset-preview align-items-start">${asset.extracted_text
                    ? `<pre class="ai-asset-text">${escapeHtml(asset.extracted_text)}</pre>`
                    : '<div class="text-muted p-5"><i class="bi bi-file-earmark fs-1 d-block mb-2"></i>미리 볼 수 있는 추출 텍스트가 없습니다.</div>'}</div>`;
            detail.innerHTML = `
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <span class="text-truncate fw-semibold flex-grow-1 overflow-hidden">${escapeHtml(asset.title || asset.name)}</span>
                    <span class="badge text-bg-light border">${escapeHtml(String(asset.extension || '').toUpperCase())}</span>
                    <span class="ms-auto d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-secondary" href="${escapeHtml(asset.file_url)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> 원본 보기</a>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-ai-asset-delete="${Number(asset.id)}"><i class="bi bi-trash"></i> 삭제</button>
                    </span>
                </div>
                <div class="card-body">
                    ${preview}
                    <dl class="ai-asset-meta mt-3 mb-0">
                        <div><dt>원본 파일명</dt><dd>${escapeHtml(asset.name)}</dd></div>
                        <div><dt>종류</dt><dd>${asset.kind === 'image' ? '이미지' : '문서'} · ${escapeHtml(asset.mime_type)}</dd></div>
                        <div><dt>파일 크기</dt><dd>${formatBytes(asset.size)}</dd></div>
                        <div><dt>크기</dt><dd>${escapeHtml(dimensions)}</dd></div>
                        <div><dt>등록일</dt><dd>${escapeHtml(asset.created_at || '-')}</dd></div>
                        <div><dt>수정일</dt><dd>${escapeHtml(asset.updated_at || '-')}</dd></div>
                        <div><dt>원본 자산</dt><dd>${asset.parent_id ? '#' + Number(asset.parent_id) : '-'}</dd></div>
                        <div><dt>SHA-256</dt><dd class="font-monospace small">${escapeHtml(asset.sha256 || '-')}</dd></div>
                    </dl>
                </div>`;
        } catch (error) {
            detail.innerHTML = `<div class="card-body d-flex align-items-center justify-content-center text-danger text-center"><div><i class="bi bi-exclamation-circle fs-2 d-block mb-2"></i>${escapeHtml(error.message)}</div></div>`;
        }
    }

    list.addEventListener('click', event => {
        const item = event.target.closest('[data-ai-asset-id]');
        if (item) showAsset(Number(item.dataset.aiAssetId), item);
    });
    search?.addEventListener('input', filterAssets);
    kind?.addEventListener('change', filterAssets);

    detail.addEventListener('click', async event => {
        const button = event.target.closest('[data-ai-asset-delete]');
        if (!button || !window.confirm('이 자산을 삭제할까요? 생성 기록은 유지되지만 이후 프롬프트에서는 사용할 수 없습니다.')) return;
        const formData = new FormData();
        formData.append('_token', csrf);
        formData.append('asset_id', button.dataset.aiAssetDelete);
        button.disabled = true;
        try {
            const response = await fetch('/admin/block-editor/ai-asset-delete', {method:'POST', body:formData});
            const json = await readJson(response);
            if (!succeeded(json)) throw new Error(json.message || '자산을 삭제하지 못했습니다.');
            MubloRequest.showToast(json.message || 'AI 자산이 삭제되었습니다.', 'success');
            window.location.hash = 'assets';
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            MubloRequest.showAlert(error.message || '자산을 삭제하지 못했습니다.', 'error');
        }
    });

    upload?.addEventListener('change', async event => {
        const files = Array.from(event.target.files || []);
        if (!files.length) return;
        const formData = new FormData();
        formData.append('_token', csrf);
        files.forEach(file => formData.append('files[]', file));
        try {
            const response = await fetch('/admin/block-editor/ai-assets-upload', {method:'POST', body:formData});
            const json = await readJson(response);
            if (!succeeded(json)) throw new Error(json.message || '자산을 추가하지 못했습니다.');
            MubloRequest.showToast(json.message || 'AI 자산이 추가되었습니다.', 'success');
            window.location.hash = 'assets';
            window.location.reload();
        } catch (error) {
            MubloRequest.showAlert(error.message || '자산을 추가하지 못했습니다.', 'error');
        } finally {
            event.target.value = '';
        }
    });

    const assetsTab = document.getElementById('ai-assets-tab');
    const configTab = document.getElementById('ai-config-tab');
    if (window.location.hash === '#assets' && assetsTab && window.bootstrap?.Tab) {
        bootstrap.Tab.getOrCreateInstance(assetsTab).show();
    }
    assetsTab?.addEventListener('shown.bs.tab', () => {
        history.replaceState(null, '', '#assets');
        const first = list.querySelector('[data-ai-asset-id]:not([hidden])');
        if (first && !list.querySelector('.ai-asset-item.active')) first.click();
    });
    configTab?.addEventListener('shown.bs.tab', () => history.replaceState(null, '', window.location.pathname + window.location.search));
})();
</script>
