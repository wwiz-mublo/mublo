<?php
/**
 * Admin Blockkit - Index
 *
 * 블록 킷 보관소 목록 (설계 10.1)
 *
 * 블록 킷의 이름·설명·저작자는 제3자 파일이 실어 온 문자열이다. 서버가 길이를 잘랐을 뿐
 * 내용은 그대로이므로 화면에서 전부 이스케이프한다.
 *
 * @var string $pageTitle
 * @var array $kits 목록 (kit_json 없음)
 * @var array $pagination
 * @var bool $canOperateKit 업로드·적용·삭제 가능 여부
 * @var array $positions 위치 코드 → 한글 라벨 (BlockPosition::options())
 * @var string $currentKeyword 검색어 (이름·설명·저작자 OR)
 * @var string $currentTargetKind 대상 필터 ('' | position | screen | page)
 * @var string $currentScript 스크립트 필터 ('' | 1 | 0)
 * @var string $csrfToken AdminViewRenderer 가 모든 관리자 뷰에 넣어 준다
 */

$currentPage = (int) ($pagination['page'] ?? 1);
$lastPage = (int) ($pagination['last_page'] ?? 1);
$totalCount = (int) ($pagination['total'] ?? count($kits));

// 상세 왕복 컨텍스트 (검색어/필터/페이지). 상세의 "목록" 버튼이 이 상태로 복귀한다.
$ctx = [];
if ($currentKeyword !== '') { $ctx['keyword'] = $currentKeyword; }
if ($currentTargetKind !== '') { $ctx['target_kind'] = $currentTargetKind; }
if ($currentScript !== '') { $ctx['script'] = $currentScript; }
if ($currentPage > 1) { $ctx['page'] = $currentPage; }
$ctxQuery = $ctx ? '?' . http_build_query($ctx) : '';

// 검색/필터 활성 여부 — 빈 목록 문구와 리셋 아이콘 표시에 쓴다
$hasFilter = $currentKeyword !== '' || $currentTargetKind !== '' || $currentScript !== '';

// 일시 2행 렌더 (1행: 일자, 2행: 시간) — 회원 목록 패턴
$renderDateTime = function ($value): string {
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '<span class="text-muted">-</span>';
    }
    $parts = explode(' ', $value, 2);
    $date = htmlspecialchars($parts[0]);
    $time = htmlspecialchars(substr($parts[1] ?? '', 0, 8)); // HH:MM:SS
    return '<div>' . $date . '</div>'
        . ($time !== '' ? '<div class="text-muted small">' . $time . '</div>' : '');
};

// 컬럼 정의 (블록 페이지 목록 패턴)
$columnBuilder = $this->columns();

// 체크박스(일괄 삭제)는 운영자 전용 — 일반 관리자에게는 열 자체를 그리지 않는다
if ($canOperateKit) {
    $columnBuilder->checkbox('chk', '', [
        'id_key' => 'kit_id',
        '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'],
        '_cell_attr' => ['class' => 'text-center'],
    ]);
}

$columns = $columnBuilder
    ->add('screenshot_path', '스크린샷', [
        'render' => function ($row) {
            if (!empty($row['screenshot_path'])) {
                return '<img src="' . htmlspecialchars($row['screenshot_path']) . '" alt=""'
                    . ' class="img-thumbnail" style="width: 100px; height: 75px; object-fit: cover;">';
            }
            return '<div class="bg-body-tertiary border rounded d-flex align-items-center justify-content-center text-body-secondary small"'
                . ' style="width: 100px; height: 75px;">없음</div>';
        },
        '_th_attr' => ['style' => 'width:120px'],
    ])
    ->add('kit_name', '이름', [
        'render' => function ($row) use ($ctxQuery) {
            $html = '<a href="/admin/block-kit/show/' . (int) $row['kit_id'] . $ctxQuery . '" class="fw-semibold text-decoration-none">'
                . htmlspecialchars($row['kit_name']) . '</a>';
            $html .= '<div class="text-muted small">v' . htmlspecialchars($row['kit_version']);
            if ($row['kit_author'] !== '') {
                $html .= ' · ' . htmlspecialchars($row['kit_author']);
            }
            $html .= '</div>';
            if ($row['kit_description'] !== '') {
                $html .= '<div class="text-muted small text-truncate" style="max-width: 380px;">'
                    . htmlspecialchars($row['kit_description']) . '</div>';
            }
            return $html;
        },
    ])
    ->add('target', '대상', [
        'render' => function ($row) use ($positions) {
            if ($row['target_kind'] === 'screen') {
                $line = '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">화면</span>'
                    . '메인화면';
            } elseif ($row['target_kind'] === 'page') {
                $line = '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">페이지</span>'
                    . '<span class="text-truncate" style="max-width: 150px;" title="' . htmlspecialchars($row['target_page_code'] ?? '?') . '">'
                    . htmlspecialchars($row['target_page_code'] ?? '?') . '</span>';
            } else {
                $line = '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">위치</span>'
                    . htmlspecialchars($positions[$row['target_position']] ?? $row['target_position'] ?? '?');
                // index(메인화면)는 메뉴 개념이 없으므로 스코프 표시를 생략한다 — 블록 행 목록과 동일
                if (($row['target_position'] ?? '') !== 'index') {
                    $line .= '<small class="text-muted">· '
                        . ($row['target_menu_code'] !== null ? htmlspecialchars($row['target_menu_code']) : '전역')
                        . '</small>';
                }
            }
            return '<div class="d-flex align-items-center gap-2">' . $line . '</div>'
                . '<div class="d-flex align-items-center gap-2 text-muted mt-1">'
                . '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">구성</span>'
                . (int) $row['row_count'] . '행 ' . (int) $row['column_count'] . '칸'
                . '</div>';
        },
    ])
    ->add('contains_script', '스크립트', [
        'render' => function ($row) {
            if ($row['contains_script']) {
                return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="업로드 시 실측한 값입니다">'
                    . '<i class="bi bi-code-slash"></i> 포함</span>';
            }
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">'
                . '<i class="bi bi-shield-check"></i> 없음</span>';
        },
        '_th_attr' => ['style' => 'width:110px'],
    ])
    ->add('created_at', '등록일시', [
        'render' => fn ($row) => $renderDateTime($row['created_at'] ?? ''),
        '_th_attr' => ['style' => 'width:100px'],
        '_td_attr' => ['class' => 'small text-nowrap'],
    ])
    ->actions('actions', '관리', function ($row) use ($ctxQuery, $canOperateKit) {
        $id = (int) $row['kit_id'];
        $html = '<a href="/admin/block-kit/download/' . $id . '" class="btn btn-outline-secondary btn-sm">내려받기</a> ';
        $html .= '<a href="/admin/block-kit/show/' . $id . $ctxQuery . '" class="btn btn-outline-primary btn-sm">상세</a> ';
        if ($canOperateKit) {
            $html .= '<button type="button" class="btn btn-outline-danger btn-sm js-kit-delete" data-kit-id="' . $id . '">삭제</button>';
        }
        return $html;
    }, ['_th_attr' => ['style' => 'width:170px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '블록 킷 관리') ?></h3>
            <p>블록 행이나 블록 페이지에서 내보낸 블록 킷을 보관하고, 검증 후 적용합니다.</p>
        </div>
        <div class="page-title-actions">
            <?php if ($canOperateKit): ?>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#kitUploadModal">
                <i class="bi bi-upload"></i> 블록 킷 업로드
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-sm btn-primary" disabled
                    title="블록 킷 업로드·적용은 도메인 운영자 권한이 필요합니다.">
                <i class="bi bi-upload"></i> 블록 킷 업로드
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="page-block">
        <!-- 요약 + 검색 영역 (블록 페이지 목록 패턴) -->
        <div class="row align-items-center gy-2 gy-xl-0 mb-2">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/block-kit">전체</a></span>
                    <span class="ov-num"><b><?= $totalCount ?></b> 개</span>
                </span>
            </div>
            <div class="col-auto ms-xl-auto">
                <form method="get" class="d-flex gap-2">
                    <label for="filter_target_kind" class="visually-hidden">대상 필터</label>
                    <select name="target_kind" id="filter_target_kind" class="form-select form-select-sm w-auto"
                            onchange="this.form.submit()">
                        <option value="">대상 전체</option>
                        <option value="position" <?= $currentTargetKind === 'position' ? 'selected' : '' ?>>위치</option>
                        <option value="screen" <?= $currentTargetKind === 'screen' ? 'selected' : '' ?>>메인화면</option>
                        <option value="page" <?= $currentTargetKind === 'page' ? 'selected' : '' ?>>페이지</option>
                    </select>
                    <label for="filter_script" class="visually-hidden">스크립트 필터</label>
                    <select name="script" id="filter_script" class="form-select form-select-sm w-auto"
                            onchange="this.form.submit()">
                        <option value="">스크립트 전체</option>
                        <option value="1" <?= $currentScript === '1' ? 'selected' : '' ?>>포함</option>
                        <option value="0" <?= $currentScript === '0' ? 'selected' : '' ?>>없음</option>
                    </select>
                    <div class="search-wrapper">
                        <label for="search_keyword" class="visually-hidden">검색</label>
                        <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm" style="min-width:220px"
                               placeholder="이름 · 설명 · 저작자 검색"
                               value="<?= htmlspecialchars($currentKeyword, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-search search-icon"></i>
                        <?php if ($hasFilter): ?>
                        <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/block-kit'"></i>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-sm btn-default text-nowrap">
                        <i class="bi bi-search"></i> 검색
                    </button>
                </form>
            </div>
        </div>

        <!-- 블록 킷 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($kits)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->setEmptyText($hasFilter
                        ? '조건에 맞는 블록 킷이 없습니다.'
                        : "보관된 블록 킷이 없습니다.\n블록 행이나 블록 페이지에서 내보낸 블록 킷을 업로드해 보관하세요.")
                    ->showHeader(true)
                    ->render() ?>
            </div>
        </form>

        <!-- 하단 액션바 + 페이지네이션 (블록 페이지 목록 패턴) -->
        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto">
                <?php if ($canOperateKit): ?>
                <button type="button" class="btn btn-sm btn-default" id="kitBulkDeleteBtn">
                    <i class="d-inline d-md-none bi bi-trash"></i>
                    <span class="d-none d-md-inline">선택 삭제</span>
                </button>
                <?php endif; ?>
            </div>
            <div class="col-auto">
                <?php if ($lastPage > 1): ?>
                    <?= $this->pagination(['currentPage' => $currentPage, 'totalPages' => $lastPage]) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 안내 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-info-circle text-pastel-sky"></i>
                <span>블록 킷 안내</span>
            </div>
            <div class="card-body">
                <ul class="mb-0 small">
                    <li>블록 킷은 블록 행·블록 페이지 구성을 담은 JSON 파일입니다. 업로드하면 검증을 거쳐 보관됩니다.</li>
                    <li>보관된 블록 킷은 <strong>상세</strong> 화면에서 미리보기(dry-run)로 확인한 뒤 적용할 수 있습니다.</li>
                    <li><strong>스크립트</strong> 배지는 업로드 시 실측한 값입니다. 신뢰하는 배포자의 블록 킷만 적용하세요.</li>
                    <li>업로드·적용·삭제는 도메인 운영자 이상만 할 수 있습니다.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($canOperateKit): ?>
<div class="modal fade" id="kitUploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="kitUploadForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">블록 킷 업로드</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">블록 킷 JSON 파일</label>
                    <input type="file" name="kit_file" class="form-control form-control-sm" accept="application/json,.json" required>
                    <div class="form-text">2 MiB 이하. 업로드 시 검증하며, 검증에 실패한 블록 킷은 보관하지 않습니다.</div>
                    <div id="kitUploadResult" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">닫기</button>
                    <button type="submit" class="btn btn-primary btn-sm">업로드</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    // CsrfMiddleware 는 모든 POST 를 검증한다(excludePaths 비어 있음). 토큰 없이 보내면 419.
    const CSRF_TOKEN = <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>;

    function postForm(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body,
        });
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function renderMessages(payload) {
        const errors = (payload && payload.errors) || [];
        const warnings = (payload && payload.warnings) || [];
        let html = '';

        if (errors.length) {
            html += '<div class="alert alert-danger small mb-2"><ul class="mb-0">'
                + errors.map(e => '<li>' + escapeHtml(e) + '</li>').join('')
                + '</ul></div>';
        }
        if (warnings.length) {
            html += '<div class="alert alert-warning small mb-0"><ul class="mb-0">'
                + warnings.map(w => '<li>' + escapeHtml(w) + '</li>').join('')
                + '</ul></div>';
        }
        return html;
    }

    const form = document.getElementById('kitUploadForm');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const result = document.getElementById('kitUploadResult');
        result.innerHTML = '<div class="text-muted small">검증 중…</div>';

        const response = await postForm('/admin/block-kit/upload', new FormData(form));
        const json = await response.json();
        const payload = json.data || {};

        if (!response.ok || !json.success) {
            result.innerHTML = renderMessages(payload)
                || '<div class="alert alert-danger small mb-0">' + escapeHtml(json.message || '업로드에 실패했습니다.') + '</div>';
            return;
        }

        // 경고가 있어도 보관은 됐다. 사용자가 읽을 시간을 준 뒤 새로고침한다.
        result.innerHTML = '<div class="alert alert-success small mb-2">블록 킷이 보관되었습니다.</div>' + renderMessages(payload);
        setTimeout(() => window.location.reload(), payload.warnings?.length ? 2500 : 700);
    });

    // 전체 선택 ↔ 개별 체크박스 연결 — 리스트 스킨은 chk_all/chk[] 를 그릴 뿐,
    // 연결은 각 뷰의 몫이다 (누락하면 전체선택이 먹통이 된다)
    const chkAll = document.querySelector('input[name="chk_all"]');
    chkAll?.addEventListener('change', () => {
        document.querySelectorAll('input[name="chk[]"]').forEach((chk) => { chk.checked = chkAll.checked; });
    });

    document.getElementById('kitBulkDeleteBtn')?.addEventListener('click', async () => {
        const ids = [...document.querySelectorAll('input[name="chk[]"]:checked')].map((chk) => chk.value);
        if (!ids.length) {
            alert('삭제할 블록 킷을 선택해주세요.');
            return;
        }
        if (!confirm(ids.length + '개 블록 킷을 삭제할까요? 적용 이력은 남습니다.')) {
            return;
        }

        const body = new FormData();
        ids.forEach((id) => body.append('chk[]', id));

        const response = await postForm('/admin/block-kit/list-delete', body);
        const json = await response.json();

        if (response.ok && json.success) {
            window.location.reload();
        } else {
            alert(json.message || '블록 킷을 삭제하지 못했습니다.');
        }
    });

    document.querySelectorAll('.js-kit-delete').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('이 블록 킷을 삭제할까요? 적용 이력은 남습니다.')) {
                return;
            }

            const body = new FormData();
            body.append('kit_id', button.dataset.kitId);

            const response = await postForm('/admin/block-kit/delete', body);
            if (response.ok) {
                window.location.reload();
            } else {
                alert('블록 킷을 삭제하지 못했습니다.');
            }
        });
    });
})();
</script>
<?php endif; ?>
