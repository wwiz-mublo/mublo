<?php
/**
 * Admin Blockpage - Index
 *
 * 블록 페이지 목록
 *
 * @var string $pageTitle 페이지 제목
 * @var array $pages 페이지 목록
 * @var array|null $pagination 페이지네이션 정보
 * @var string $currentKeyword 현재 검색어
 */

$currentKeyword = $currentKeyword ?? '';
$currentPage = (int) ($pagination['page'] ?? 1);
$totalCount = (int) ($pagination['total'] ?? count($pages));

// 편집 왕복 컨텍스트 (검색어/페이지). 행 관리로 넘어갈 때는 유지하지 않는다.
$ctx = [];
if ($currentKeyword !== '') { $ctx['keyword'] = $currentKeyword; }
if ($currentPage > 1) { $ctx['page'] = $currentPage; }
$ctxSuffix = $ctx ? '&' . http_build_query($ctx) : '';      // 기존 쿼리 뒤에 붙일 때
$ctxCreate = $ctx ? '?' . http_build_query($ctx) : '';      // 단독 쿼리로 붙일 때

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'page_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('page_id', 'ID', [
        'render' => fn($row) => '<small class="text-muted">#' . $row['page_id'] . '</small>',
        '_th_attr' => ['style' => 'width:60px']
    ])
    ->add('page_code', '코드', [
        'render' => fn($row) => '<code>' . htmlspecialchars($row['page_code']) . '</code>'
    ])
    ->add('page_title', '제목', [
        'render' => function($row) {
            $html = '<strong>' . htmlspecialchars($row['page_title']) . '</strong>';
            // 프론트 페이지(/p/{코드})로 새 창 이동하는 익스터널 링크 아이콘
            $code = $row['page_code'] ?? '';
            if ($code !== '') {
                $html .= ' <a href="/p/' . rawurlencode($code) . '" target="_blank" rel="noopener noreferrer"'
                       . ' class="text-muted text-decoration-none ms-1"'
                       . ' title="프론트 페이지 새 창으로 열기">'
                       . '<i class="bi bi-box-arrow-up-right"></i></a>';
            }
            if (!empty($row['page_description'])) {
                $desc = mb_substr($row['page_description'], 0, 30);
                $desc .= mb_strlen($row['page_description']) > 30 ? '...' : '';
                $html .= '<br><small class="text-muted">' . htmlspecialchars($desc) . '</small>';
            }
            return $html;
        }
    ])
    ->add('row_count', '행 수', [
        'render' => fn($row) => '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">' . ($row['row_count'] ?? 0) . '</span>',
        '_th_attr' => ['style' => 'width:70px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->add('allow_level', '접근레벨', [
        'render' => function($row) {
            $level = $row['allow_level'] ?? 0;
            return $level == 0 ? '<small class="text-muted">모두</small>' : "Lv.{$level}";
        },
        '_th_attr' => ['style' => 'width:80px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->add('layout', '레이아웃', [
        'render' => function($row) {
            $mode = (int) ($row['use_fullpage'] ?? 0);
            if ($mode === 1) return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">와이드</span>';
            if ($mode === 2) return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">' . (int)($row['custom_width'] ?? 0) . 'px</span>';
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">기본</span>';
        },
        '_th_attr' => ['style' => 'width:90px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center']
    ])
    ->select('is_active', '상태', [
        1 => '사용',
        0 => '미사용',
    ], ['id_key' => 'page_id', '_th_attr' => ['style' => 'width:100px'], '_el_attr' => ['class' => 'form-select form-select-sm']])
    ->actions('actions', '관리', function($row) use ($ctxSuffix, $canExportKit) {
        $id = $row['page_id'];
        $name = htmlspecialchars($row['page_title'], ENT_QUOTES);
        $code = htmlspecialchars($row['page_code'], ENT_QUOTES);
        $rowCount = $row['row_count'] ?? 0;

        // 수정: 목록 컨텍스트 유지 / 행 관리: 다른 화면이므로 컨텍스트 미유지
        $html = '<a href="/admin/block-page/edit?id=' . $id . $ctxSuffix . '" class="btn btn-sm btn-default">수정</a> ';
        $html .= '<a href="/admin/block-row?page_id=' . $id . '" class="btn btn-sm btn-default">행 관리</a> ';

        // 블록 킷 내보내기 — 도메인 운영자 이상(설계 9.4). 보이되 화면 안에서 제한한다.
        if ($canExportKit) {
            $html .= '<button type="button" class="btn btn-sm btn-default js-page-kit-export" data-page-id="' . $id . '" data-page-name="' . $name . '" title="블록 킷 내보내기">블록 킷</button> ';
        } else {
            $html .= '<button type="button" class="btn btn-sm btn-default" disabled title="블록 킷 내보내기는 도메인 운영자 권한이 필요합니다.">블록 킷</button> ';
        }

        if ($rowCount === 0) {
            $html .= '<button type="button" class="btn btn-sm btn-default js-page-delete" data-page-id="' . $id . '" data-page-name="' . $name . '">삭제</button>';
        } else {
            $html .= '<button type="button" class="btn btn-sm btn-default" disabled title="연결된 행이 있어 삭제 불가">삭제</button>';
        }

        return $html;
    }, ['_th_attr' => ['style' => 'width:240px']])
    ->build();
?>
<style>
/* 인라인 셀렉트 변경됨 표시 (회원 관리 목록 패턴) */
#flist select.list-changed {
    border-color: var(--bs-warning, #ffc107);
    background-color: var(--bs-warning-bg-subtle, #fff3cd);
    font-weight: 600;
}
</style>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '블록 페이지 관리') ?></h3>
            <p>블록으로 구성된 개별 페이지를 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <?php if ($canExportKit): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openKitImportModal()">
                <i class="bi bi-box-arrow-in-down"></i> 블록 킷으로 추가
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                    title="블록 킷 기능은 도메인 운영자 권한이 필요합니다.">
                <i class="bi bi-box-arrow-in-down"></i> 블록 킷으로 추가
            </button>
            <?php endif; ?>
            <a href="/admin/block-page/create<?= $ctxCreate ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 페이지 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 요약 + 검색 영역 (아이콘·버튼 색상은 회원 관리 참고) -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/block-page">전체</a></span>
                    <span class="ov-num"><b><?= $totalCount ?></b> 개</span>
                </span>
            </div>
            <div class="col-auto ms-xl-auto">
                <form method="get" class="d-flex gap-1">
                    <div class="search-wrapper">
                        <label for="search_keyword" class="visually-hidden">검색</label>
                        <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm" style="min-width:220px"
                               placeholder="코드 · 제목 · 설명 검색"
                               value="<?= htmlspecialchars($currentKeyword, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-search search-icon"></i>
                        <?php if ($currentKeyword !== ''): ?>
                        <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/block-page'"></i>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-sm btn-default text-nowrap">
                        <i class="bi bi-search"></i> 검색
                    </button>
                </form>
            </div>
        </div>

        <!-- 페이지 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($pages)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/block-page/list-modify"
                                data-callback="afterListModify">
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-default mublo-submit"
                                data-target="/admin/block-page/list-delete"
                                data-callback="afterListDelete">
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                    </div>
                </div>
                <div class="col-auto">
                    <?php if ($pagination): ?>
                    <?= $this->pagination(['currentPage' => $pagination['page'], 'totalPages' => $pagination['total_pages']]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- 안내 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-info-circle text-pastel-sky"></i>
                <span>블록 페이지 안내</span>
            </div>
            <div class="card-body">
                <ul class="mb-0 small">
                    <li>블록 페이지는 <code>/p/{코드}</code> URL로 접근할 수 있습니다.</li>
                    <li>각 페이지에 행을 추가하여 레이아웃을 구성하세요.</li>
                    <li>행에는 최대 4개의 칸을 배치할 수 있습니다.</li>
                    <li><strong>블록 킷으로 추가</strong>: 페이지 블록 킷(.json)을 가져와 새 페이지를 만들 수 있습니다. 페이지 코드를 바꿔 넣으면 같은 블록 킷으로 여러 페이지를 만들 수 있습니다.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($canExportKit): ?>
<!-- 페이지 블록 킷 가져오기 모달 -->
<div class="modal fade" id="kitImportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">블록 킷으로 페이지 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle"></i>
                    블록 킷은 사이트에서 스크립트를 실행할 수 있습니다. 신뢰하는 배포자의 것만 적용하세요.
                </div>

                <div class="mb-3">
                    <label class="form-label">페이지 블록 킷 파일 (.json)</label>
                    <input type="file" id="kitFile" class="form-control form-control-sm" accept=".json,application/json">
                    <div class="form-text">위치(position) 블록 킷은 여기서 적용할 수 없습니다. 블록 행 관리에서 가져오세요.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">페이지 코드 <span class="text-muted small">(선택)</span></label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">/p/</span>
                        <input type="text" id="kitPageCode" class="form-control form-control-sm"
                               placeholder="비워 두면 블록 킷에 담긴 코드를 사용">
                    </div>
                    <div class="form-text">
                        블록 킷에 담긴 코드 대신 이 코드로 페이지를 만듭니다.
                        같은 코드의 페이지가 이미 있으면 새로 만들지 않고 그 페이지에 적용됩니다.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">적용 모드</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="kit_mode" id="kitModeAppend" value="append" checked>
                        <label class="form-check-label" for="kitModeAppend">
                            <strong>이어붙이기</strong>
                            <span class="text-muted small d-block">같은 코드의 페이지가 있으면 기존 행 뒤에 추가합니다. 없으면 새 페이지를 만듭니다.</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="kit_mode" id="kitModeReplace" value="replace">
                        <label class="form-check-label" for="kitModeReplace">
                            <strong>교체</strong>
                            <span class="text-danger small d-block">같은 코드의 페이지가 있으면 기존 행을 삭제하고 페이지 설정(레이아웃·SEO)도 블록 킷으로 덮어씁니다. 기존 행은 삭제 이력에서 복구할 수 있지만 페이지 설정은 자동 복구되지 않습니다.</span>
                        </label>
                    </div>
                </div>

                <div id="kitPreviewResult" style="display:none;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="previewKit()">
                    <i class="bi bi-search"></i> 미리보기
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="kitApplyBtn" onclick="applyKit()" disabled>
                    적용
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 페이지 블록 킷 내보내기 모달 -->
<div class="modal fade" id="kitExportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/admin/block-page/export" enctype="multipart/form-data" target="_blank">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES) ?>">
                <input type="hidden" name="page_id" id="kitExportPageId" value="">

                <div class="modal-header">
                    <h5 class="modal-title">블록 킷 내보내기 — <span id="kitExportPageName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-light border small">
                        페이지 블록 킷은 레이아웃을 자기 안에 담으므로 사이트 전역 설정을 바꾸지 않습니다.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">블록 킷 이름</label>
                            <input type="text" name="kit_name" class="form-control form-control-sm" placeholder="회사소개 페이지 블록 킷">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">버전</label>
                            <input type="text" name="kit_version" class="form-control form-control-sm" placeholder="1.0.0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">설명</label>
                            <input type="text" name="kit_description" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">저작자</label>
                            <input type="text" name="kit_author" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">저작자 URL</label>
                            <input type="url" name="kit_author_url" class="form-control form-control-sm" placeholder="https://example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">필요 코어 버전 <span class="text-muted small">(선택)</span></label>
                            <input type="text" name="kit_requires_core" class="form-control form-control-sm" placeholder="^1.0">
                            <div class="form-text">비워 두면 요구하지 않습니다. 낮은 코어에서는 경고만 뜨고 적용은 됩니다.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">스크린샷 <span class="text-muted small">(선택)</span></label>
                            <input type="file" name="kit_screenshot" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                            <div class="form-text">1200×900 webp로 변환해 블록 킷에 포함됩니다. SVG는 지원하지 않습니다.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">내보내기 모드</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_mode" id="kitModeDist" value="distribution" checked>
                                <label class="form-check-label" for="kitModeDist">
                                    <strong>배포용 블록 킷</strong>
                                    <span class="text-muted small d-block">콘텐츠 참조를 비웁니다. 남에게 배포할 때 씁니다.</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_mode" id="kitModeClone" value="clone">
                                <label class="form-check-label" for="kitModeClone">
                                    <strong>복제 / 백업</strong>
                                    <span class="text-muted small d-block">참조를 그대로 유지합니다. 같은 설치 안에서만 쓰세요.</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-download"></i> JSON 다운로드
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('click', function(event) {
    const button = event.target.closest('.js-page-kit-export, .js-page-delete');
    if (!button) return;

    const pageId = Number(button.dataset.pageId || 0);
    const pageName = button.dataset.pageName || '';
    if (!pageId) return;

    if (button.classList.contains('js-page-kit-export')) openKitExportModal(pageId, pageName);
    else deletePage(pageId, pageName);
});

/**
 * 페이지 블록 킷 내보내기 모달 열기
 *
 * 폼은 target="_blank" 로 제출되어 JSON 다운로드만 발생하고 목록 화면은 그대로 남는다.
 */
function openKitExportModal(pageId, pageName) {
    const el = document.getElementById('kitExportModal');
    if (!el) return;

    document.getElementById('kitExportPageId').value = pageId;
    document.getElementById('kitExportPageName').textContent = pageName;

    new bootstrap.Modal(el).show();
}

// ========================================
// 블록 킷으로 페이지 추가
// ========================================

function openKitImportModal() {
    const el = document.getElementById('kitImportModal');
    if (!el) return;

    document.getElementById('kitFile').value = '';
    document.getElementById('kitPageCode').value = '';
    document.getElementById('kitPreviewResult').style.display = 'none';
    document.getElementById('kitApplyBtn').disabled = true;

    new bootstrap.Modal(el).show();
}

function buildKitFormData() {
    const input = document.getElementById('kitFile');
    if (!input.files.length) {
        MubloRequest.showAlert('블록 킷 파일을 선택하세요.', 'warning');
        return null;
    }

    const formData = new FormData();
    formData.append('kit_file', input.files[0]);
    formData.append('page_code', document.getElementById('kitPageCode').value.trim());

    // CsrfMiddleware 는 모든 POST 를 검증한다(excludePaths 비어 있음). 없으면 419.
    formData.append('_token', <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>);

    return formData;
}

/**
 * dry-run 미리보기. 적용은 이 단계를 반드시 거친다(설계 6.1).
 */
async function previewKit() {
    const formData = buildKitFormData();
    if (!formData) return;

    const box = document.getElementById('kitPreviewResult');
    box.style.display = 'block';
    box.innerHTML = '<div class="text-muted small">검증 중...</div>';
    document.getElementById('kitApplyBtn').disabled = true;

    try {
        const res = await fetch('/admin/block-page/kit-preview', { method: 'POST', body: formData });
        const json = await res.json();

        if (!json.success) {
            box.innerHTML = '<div class="alert alert-danger small mb-0">' + escapeHtml(json.message || '검증에 실패했습니다.') + '</div>';
            return;
        }

        const data = json.data;
        box.innerHTML = renderKitPreview(data);
        document.getElementById('kitApplyBtn').disabled = !data.ok;
    } catch (e) {
        box.innerHTML = '<div class="alert alert-danger small mb-0">요청에 실패했습니다.</div>';
    }
}

function renderKitPreview(data) {
    const s = data.summary || {};
    const p = data.page || {};
    let html = '';

    if (!data.ok) {
        html += '<div class="alert alert-danger small"><strong>적용할 수 없습니다.</strong><ul class="mb-0 mt-1">';
        (data.errors || []).forEach(e => { html += '<li>' + escapeHtml(e) + '</li>'; });
        html += '</ul></div>';
    }

    html += '<div class="border rounded p-2 small">';

    // 어느 페이지에 어떻게 붙는가 — 이 화면의 핵심 정보라 맨 위에 둔다
    if (p.page_code) {
        const target = '<code>/p/' + escapeHtml(p.page_code) + '</code>'
            + (p.page_title ? ' (' + escapeHtml(p.page_title) + ')' : '');
        html += p.exists
            ? '<div class="mb-1"><i class="bi bi-arrow-return-right"></i> 기존 페이지 ' + target + ' 에 적용됩니다.</div>'
            : '<div class="mb-1 text-primary"><i class="bi bi-file-earmark-plus"></i> 새 페이지 ' + target + ' 가 생성됩니다.</div>';
    }

    html += '<div><strong>' + (s.row_count || 0) + '</strong>개 행 · <strong>' + (s.column_count || 0) + '</strong>개 칸이 생성됩니다.</div>';

    if (s.contains_script) {
        html += '<div class="text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> 이 블록 킷은 스크립트를 포함합니다.</div>';
    } else {
        html += '<div class="text-success mt-1"><i class="bi bi-shield-check"></i> 스크립트 없음</div>';
    }

    if ((s.missing_block_types || []).length) {
        html += '<div class="text-warning mt-1">미설치 블록 타입: ' + s.missing_block_types.map(escapeHtml).join(', ') + '</div>';
    }

    if ((s.needs_setup || []).length) {
        html += '<div class="mt-2"><strong>적용 후 설정이 필요한 칸 ' + s.needs_setup.length + '개</strong><ul class="mb-0 mt-1">';
        s.needs_setup.forEach(item => {
            const label = item.content_type ? escapeHtml(item.content_type) : '블록';
            // provider 는 블록 킷이 실어 온 문자열이다. 서버가 형식을 걸렀지만 여기서도 이스케이프한다.
            const REASONS = {
                image_missing: '이미지를 지정하세요',
                items_empty: '표시할 콘텐츠를 선택하세요',
            };
            const reason = item.reason === 'extension_missing'
                ? (item.provider ? escapeHtml(item.provider) + '를 설치하세요' : '확장 설치가 필요합니다')
                : (REASONS[item.reason] || '표시할 콘텐츠를 선택하세요');
            // 블록 킷은 제3자 파일이다. 서버가 정수로 강제하지만 여기서도 숫자로 취급한다.
            const rowNo = (parseInt(item.row_index, 10) || 0) + 1;
            const colNo = (parseInt(item.column_index, 10) || 0) + 1;
            html += '<li>' + rowNo + '행 ' + colNo + '칸 · '
                + '<span class="badge bg-light text-dark">' + label + '</span> ' + reason;
            if (item.kit_hint) {
                html += '<div class="text-muted small">' + escapeHtml(item.kit_hint) + '</div>';
            }
            html += '</li>';
        });
        html += '</ul></div>';
    }
    html += '</div>';

    if ((data.warnings || []).length) {
        html += '<div class="alert alert-warning small mt-2 mb-0"><ul class="mb-0">';
        data.warnings.forEach(w => { html += '<li>' + escapeHtml(w) + '</li>'; });
        html += '</ul></div>';
    }

    return html;
}

function applyKit() {
    const formData = buildKitFormData();
    if (!formData) return;

    const mode = document.querySelector('input[name="kit_mode"]:checked').value;
    if (mode === 'replace') {
        MubloRequest.showConfirm(
            '교체 모드는 같은 코드 페이지의 기존 행을 삭제하고 페이지 설정도 덮어씁니다. 기존 행은 삭제 이력에서 복구할 수 있지만 페이지 설정은 자동 복구되지 않습니다.',
            () => doApplyKit(formData, mode),
            { type: 'warning', confirmText: '교체' }
        );
        return;
    }
    doApplyKit(formData, mode);
}

async function doApplyKit(formData, mode) {
    formData.append('mode', mode);

    const btn = document.getElementById('kitApplyBtn');
    btn.disabled = true;

    try {
        const res = await fetch('/admin/block-page/kit-apply', { method: 'POST', body: formData });
        const json = await res.json();

        if (!json.success) {
            MubloRequest.showAlert(json.message || '적용에 실패했습니다.', 'error');
            btn.disabled = false;
            return;
        }

        // 적용 완료 후 "설정이 필요한 칸" 안내 (설계 8.3)
        const summary = (json.data && json.data.summary) || {};
        const needsSetup = summary.needs_setup || [];
        let message = summary.created_page
            ? '블록 킷이 적용되어 새 페이지가 생성되었습니다.'
            : '블록 킷이 기존 페이지에 적용되었습니다.';
        if (needsSetup.length) {
            message += ' 다음 ' + needsSetup.length + '개 칸에 콘텐츠를 설정하세요: '
                + needsSetup.map(i =>
                    (i.row_index + 1) + '행 ' + (i.column_index + 1) + '칸 (' + (i.content_type || '블록') + ')'
                    + (i.kit_hint ? ' — ' + i.kit_hint : '')
                ).join(', ');
        }

        // 확인을 누르면 목록을 새로고침한다 (토스트는 리로드에 쓸려가 안내를 못 남긴다)
        MubloRequest.showConfirm(message, () => location.reload(), {
            type: 'success', confirmText: '확인', cancelText: '닫기',
        });
    } catch (e) {
        MubloRequest.showAlert('요청에 실패했습니다.', 'error');
        btn.disabled = false;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value);
    return div.innerHTML;
}

// 파일이나 코드를 바꾸면 이전 미리보기는 무효다 — 다시 검증하기 전까지 적용을 막는다
document.addEventListener('DOMContentLoaded', function() {
    ['kitFile', 'kitPageCode'].forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function() {
            document.getElementById('kitApplyBtn').disabled = true;
        });
    });
});

// 전체 선택
document.addEventListener('DOMContentLoaded', function() {
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }
});

// 상태 셀렉트 변경 시 변경 표시 + 해당 행 자동 체크 (회원 관리 목록 패턴)
document.addEventListener('DOMContentLoaded', function() {
    var flist = document.getElementById('flist');
    if (!flist) return;

    // 각 셀렉트의 원래 값 저장 (되돌림 판정용)
    flist.querySelectorAll('select').forEach(function(sel) {
        sel.dataset.original = sel.value;
    });

    flist.addEventListener('change', function(e) {
        var el = e.target;
        if (!el || el.tagName !== 'SELECT') return;
        var row = el.closest('tr');
        if (!row) return;

        // 원래 값과 비교해 변경됨 표시 토글 (되돌리면 해제)
        el.classList.toggle('list-changed', el.value !== el.dataset.original);

        // 행 내 변경된 셀렉트가 하나라도 있으면 체크, 모두 원복이면 해제
        var anyChanged = row.querySelector('select.list-changed') !== null;
        var cb = row.querySelector('input[name="chk[]"]');
        if (cb) cb.checked = anyChanged;
    });
});

// 페이지 삭제 (단건)
function deletePage(pageId, pageName) {
    MubloRequest.showConfirm(`'${pageName}' 페이지를 삭제하시겠습니까?`, function() {
        MubloRequest.requestJson('/admin/block-page/delete', {
            page_id: pageId
        }).then(response => {
            MubloRequest.showToast(response.message || '삭제되었습니다.', 'success');
            location.reload();
        }).catch(err => {
            console.error(err);
        });
    }, { type: 'warning' });
}

// 일괄 삭제 후 콜백
function afterListDelete(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '삭제에 실패했습니다.', 'error');
    }
}

// 일괄 상태변경 후 콜백
function afterListModify(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '변경되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '변경에 실패했습니다.', 'error');
    }
}
</script>
