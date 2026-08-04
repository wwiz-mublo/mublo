<?php
/**
 * Admin Blockkit - Show
 *
 * 보관된 블록 킷 상세 (설계 10.1)
 *
 * 미리보기(dry-run)는 아무것도 쓰지 않으므로 일반 관리자도 볼 수 있다.
 * 적용만 도메인 운영자 이상이다.
 *
 * @var string $pageTitle
 * @var array $kit 상세 (kit_json 없음)
 * @var array $history 적용 이력 (site_config_snapshot 없음, can_rollback 만)
 * @var bool $canOperateKit
 * @var array $positions 위치 코드 → 한글 라벨 (BlockPosition::options())
 * @var array $listContext 목록 복귀용 검색/필터/페이지 컨텍스트
 * @var string $csrfToken
 */

// 목록 복귀 URL (왕복 시 검색/필터/페이지 유지). activeCode는 전역 스크립트가 처리.
$listContext = $listContext ?? [];
$listUrl = '/admin/block-kit' . ($listContext ? '?' . http_build_query($listContext) : '');
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($kit['kit_name']) ?></h3>
            <p>
                v<?= htmlspecialchars($kit['kit_version']) ?>
                <?php if ($kit['kit_author'] !== ''): ?>
                    · <?= htmlspecialchars($kit['kit_author']) ?>
                <?php endif; ?>
                · <?= htmlspecialchars($kit['target_label']) ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/block-kit/download/<?= (int) $kit['kit_id'] ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download"></i> 내려받기
            </a>
            <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list-ul"></i> 목록
            </a>
        </div>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-box-seam text-pastel-sky"></i>
                <span>블록 킷 정보</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <?php if (!empty($kit['screenshot_path'])): ?>
                            <img src="<?= htmlspecialchars($kit['screenshot_path']) ?>" alt="" class="img-fluid rounded border">
                        <?php else: ?>
                            <div class="bg-body-tertiary border rounded d-flex align-items-center justify-content-center text-body-secondary"
                                 style="height: 250px;">스크린샷 없음</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-7">
                        <?php if ($kit['kit_description'] !== ''): ?>
                            <p><?= nl2br(htmlspecialchars($kit['kit_description'])) ?></p>
                        <?php endif; ?>

                        <table class="table table-sm">
            <tbody>
                <tr>
                    <th style="width: 140px;">대상</th>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($kit['target_kind'] === 'screen'): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">화면</span>
                                메인화면
                            <?php elseif ($kit['target_kind'] === 'page'): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">페이지</span>
                                <?= htmlspecialchars($kit['target_page_code'] ?? '?') ?>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">위치</span>
                                <?= htmlspecialchars($positions[$kit['target_position']] ?? $kit['target_position'] ?? '?') ?>
                                <?php // index(메인화면)는 메뉴 개념이 없으므로 스코프 표시를 생략한다 — 목록과 동일 ?>
                                <?php if (($kit['target_position'] ?? '') !== 'index'): ?>
                                    <small class="text-muted">· <?= $kit['target_menu_code'] !== null ? htmlspecialchars($kit['target_menu_code']) : '전역' ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>구성</th>
                    <td><?= (int) $kit['row_count'] ?>행 <?= (int) $kit['column_count'] ?>칸</td>
                </tr>
                <tr>
                    <th>스크립트</th>
                    <td>
                        <?php if ($kit['contains_script']): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                <i class="bi bi-code-slash"></i> 포함
                            </span>
                            <span class="text-muted small">업로드 시 실측한 값입니다. 블록 킷의 주장이 아닙니다.</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                <i class="bi bi-shield-check"></i> 없음
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>배포 모드</th>
                    <td>
                        <?= $kit['export_mode'] === 'clone' ? '복제/백업' : '배포' ?>
                        <?php if ($kit['export_mode'] === 'clone'): ?>
                            <div class="text-muted small">다른 설치에 적용하면 배너·상품 참조가 엉뚱한 데이터를 가리킬 수 있습니다.</div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>등록일</th>
                    <td class="text-muted"><?= htmlspecialchars((string) $kit['created_at']) ?></td>
                </tr>
            </tbody>
        </table>

                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="kitPreviewBtn">미리보기</button>
                        </div>
                    </div>
                </div>

                <div id="kitPreviewResult" class="mt-3"></div>
            </div>
        </div>
    </div>

    <div class="page-block">
<?php if ($canOperateKit): ?>
    <div class="card">
        <div class="card-hero">
            <i class="bi bi-magic text-pastel-sky"></i>
            <span>적용</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">적용 전에 반드시 미리보기로 확인하세요. 교체 전 행은 블록 행 관리의 삭제 이력에 보관됩니다.</p>

            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="kit_mode" id="modeAppend" value="append" checked>
                    <label class="form-check-label" for="modeAppend">추가 — 기존 행 뒤에 붙입니다</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="kit_mode" id="modeReplace" value="replace">
                    <label class="form-check-label" for="modeReplace">교체 — 해당 위치의 기존 행을 삭제합니다</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="kitApplySiteSettings"
                           <?= $kit['target_kind'] === 'screen' ? 'checked disabled' : '' ?>>
                    <label class="form-check-label" for="kitApplySiteSettings">
                        <?= $kit['target_kind'] === 'screen'
                            ? '메인화면 레이아웃 설정도 함께 적용됩니다'
                            : '블록 킷이 요구하는 레이아웃 설정도 적용' ?>
                    </label>
                </div>

                <button type="button" class="btn btn-primary btn-sm ms-auto" id="kitApplyBtn" disabled>적용</button>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-light border small mb-0">블록 킷 적용은 도메인 운영자 권한이 필요합니다.</div>
<?php endif; ?>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-clock-history text-pastel-sky"></i>
                <span>적용 이력</span>
            </div>
            <div class="card-body">

        <?php if (empty($history)): ?>
            <p class="text-muted small mb-0">아직 적용한 적이 없습니다.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>적용 일시</th>
                            <th>모드</th>
                            <th>대상</th>
                            <th>생성된 행</th>
                            <th class="text-end">레이아웃 설정 복구</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars((string) $entry['applied_at']) ?></td>
                            <td class="small"><?= $entry['apply_mode'] === 'replace' ? '교체' : '추가' ?></td>
                            <td class="small">
                                <?= $entry['target_kind'] === 'screen'
                                    ? '화면: 메인화면'
                                    : ($entry['target_kind'] === 'page'
                                        ? '페이지: ' . htmlspecialchars((string) $entry['target_page_code'])
                                        : '위치: ' . htmlspecialchars((string) $entry['target_position'])) ?>
                            </td>
                            <td class="small"><?= (int) $entry['created_row_count'] ?>행</td>
                            <td class="text-end">
                                <?php if (!$entry['can_rollback']): ?>
                                    <span class="text-muted small">해당 없음</span>
                                <?php elseif (!$canOperateKit): ?>
                                    <span class="text-muted small">운영자 권한 필요</span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm js-kit-rollback"
                                            data-application-id="<?= (int) $entry['application_id'] ?>">
                                        레이아웃 설정 되돌리기
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted small mt-2">
                되돌리기는 블록 킷이 바꾼 <strong>레이아웃 설정</strong>만 복원합니다. 블록 행은 그대로 둡니다 —
                적용 후 편집했을 수 있기 때문입니다. 한 번만 되돌릴 수 있습니다.
            </div>
        <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // CsrfMiddleware 는 모든 POST 를 검증한다(excludePaths 비어 있음). 토큰 없이 보내면 419.
    const CSRF_TOKEN = <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>;
    const KIT_ID = <?= (int) $kit['kit_id'] ?>;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function postKit(url, extra = {}) {
        const body = new FormData();
        body.append('kit_id', KIT_ID);
        Object.entries(extra).forEach(([key, value]) => body.append(key, value));

        return fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body });
    }

    function renderList(items, cssClass) {
        if (!items || !items.length) {
            return '';
        }
        return '<div class="alert ' + cssClass + ' small"><ul class="mb-0">'
            + items.map(item => '<li>' + escapeHtml(item) + '</li>').join('')
            + '</ul></div>';
    }

    function renderNeedsSetup(needsSetup) {
        if (!needsSetup || !needsSetup.length) {
            return '';
        }

        const REASONS = {
            image_missing: '이미지를 지정하세요',
            items_empty: '표시할 콘텐츠를 선택하세요',
        };

        let html = '<div class="mt-2"><strong>적용 후 설정이 필요한 칸 ' + needsSetup.length + '개</strong><ul class="mb-0 mt-1">';
        needsSetup.forEach((item) => {
            const label = item.content_type ? escapeHtml(item.content_type) : '블록';
            const reason = item.reason === 'extension_missing'
                ? (item.provider ? escapeHtml(item.provider) + '를 설치하세요' : '확장 설치가 필요합니다')
                : (REASONS[item.reason] || '표시할 콘텐츠를 선택하세요');

            // 블록 킷은 제3자 파일이다. 서버가 정수로 강제하지만 여기서도 숫자로 취급한다.
            const rowNo = (parseInt(item.row_index, 10) || 0) + 1;
            const colNo = (parseInt(item.column_index, 10) || 0) + 1;

            html += '<li>' + rowNo + '행 ' + colNo + '칸 · '
                + '<span class="badge bg-light text-dark">' + label + '</span> ' + reason + '</li>';
        });
        return html + '</ul></div>';
    }

    const box = document.getElementById('kitPreviewResult');
    const applyBtn = document.getElementById('kitApplyBtn');

    document.getElementById('kitPreviewBtn').addEventListener('click', async () => {
        box.innerHTML = '<div class="text-muted small">검증 중…</div>';
        if (applyBtn) applyBtn.disabled = true;

        const response = await postKit('/admin/block-kit/preview');
        const json = await response.json();

        if (!json.success) {
            box.innerHTML = '<div class="alert alert-danger small mb-0">' + escapeHtml(json.message || '검증에 실패했습니다.') + '</div>';
            return;
        }

        const data = json.data || {};
        box.innerHTML = renderList(data.errors, 'alert-danger')
            + renderList(data.warnings, 'alert-warning')
            + '<div class="alert alert-info small mb-0">'
            + (data.summary?.row_count ?? 0) + '행 ' + (data.summary?.column_count ?? 0) + '칸이 적용됩니다.'
            + renderNeedsSetup(data.summary?.needs_setup)
            + '</div>';

        if (applyBtn && data.ok) {
            applyBtn.disabled = false;
        }
    });

    applyBtn?.addEventListener('click', async () => {
        const mode = document.querySelector('input[name="kit_mode"]:checked').value;
        if (mode === 'replace' && !confirm(<?= json_encode(
            $kit['target_kind'] === 'screen'
                ? '교체 모드는 메인화면 대상 슬롯의 기존 전역 행을 삭제합니다. 공용 영역은 다른 화면에도 영향을 줍니다. 계속할까요?'
                : '교체 모드는 해당 위치의 기존 행을 삭제합니다. 교체 전 행은 삭제 이력에 보관됩니다. 계속할까요?',
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>)) {
            return;
        }

        applyBtn.disabled = true;

        const extra = { mode };
        if (document.getElementById('kitApplySiteSettings').checked) {
            extra.apply_site_settings = '1';
        }

        const response = await postKit('/admin/block-kit/apply', extra);
        const json = await response.json();

        if (!json.success) {
            box.innerHTML = '<div class="alert alert-danger small mb-0">' + escapeHtml(json.message || '적용에 실패했습니다.') + '</div>';
            applyBtn.disabled = false;
            return;
        }

        const data = json.data || {};
        box.innerHTML = '<div class="alert alert-success small mb-2">블록 킷이 적용되었습니다.</div>'
            + renderList(data.warnings, 'alert-warning')
            + (renderNeedsSetup(data.summary?.needs_setup)
                ? '<div class="alert alert-info small mb-0">' + renderNeedsSetup(data.summary?.needs_setup) + '</div>'
                : '');

        // 이력이 한 줄 늘었다. 새로고침해야 되돌리기 버튼이 보인다.
        setTimeout(() => window.location.reload(), 2000);
    });

    document.querySelectorAll('.js-kit-rollback').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('적용 시점의 레이아웃 설정으로 되돌립니다. 블록 행은 그대로 둡니다. 계속할까요?')) {
                return;
            }

            button.disabled = true;

            const body = new FormData();
            body.append('application_id', button.dataset.applicationId);

            const response = await fetch('/admin/block-kit/rollback', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN },
                body,
            });
            const json = await response.json();

            if (!json.success) {
                alert(json.message || '되돌리지 못했습니다.');
                button.disabled = false;
                return;
            }

            window.location.reload();
        });
    });
})();
</script>
