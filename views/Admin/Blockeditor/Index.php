<?php
/**
 * Admin Blockeditor - Index
 *
 * 블록 에디터 — 미리보기 기반 페이지 관리 (블록 에디터 설계 4)
 *
 * fullPage 렌더(관리자 셸 없음). 실제 프론트를 iframe 으로 띄우고 렌더 마커로
 * 클릭을 행/칸에 매핑한다. 이 화면은 읽기 전용 조회 + 기존 폼으로의 문이다.
 *
 * @var array $initialContexts 컨텍스트 트리 초기 데이터
 * @var string $initialContext 진입 시 열 컨텍스트 ID (?context=)
 * @var array $contentTypes    콘텐츠 타입 카드 (BlockRegistry)
 * @var array $skinLists       타입별 스킨 목록
 * @var bool  $canEditInclude  Include(서버 실행) 편집 가능 여부 — raw JS 는 편집 신뢰 전원 허용
 */
?>
<?php /* HTML 콘텐츠 모달의 비주얼 에디터(BlockHtmlEditor)는 도메인 에디터
   설정과 무관하게 항상 뜨므로, editor_css()(설정이 textarea 면 빈 값) 대신
   스타일을 직접 로드한다. 아래 BlockHtmlEditorBase.js 무조건 로드와 짝. */ ?>
<link rel="stylesheet" href="<?= asset('/assets/lib/editor/mublo-editor/MubloEditor.css') ?>">
<link rel="stylesheet" href="<?= asset('/assets/css/admin/block-editor.css') ?>">

<script>document.body.classList.add('bke-body');</script>

<!-- ================= 상단바 ================= -->
<div class="bke-topbar">
    <a href="/admin/block-page?activeCode=004_002" class="bke-back" title="관리자로 돌아가기">
        <i class="bi bi-arrow-left"></i> 관리자
    </a>
    <div class="bke-topbar__divider"></div>
    <span class="bke-brand"><i class="bi bi-easel2"></i>블록 에디터</span>
    <span class="bke-context-chip" id="bkeContextChip" style="display:none;"></span>
    <div class="bke-topbar__spacer"></div>
    <div style="position:relative;">
        <button type="button" class="bke-iconbtn" id="bkeKitBtn" title="블록 킷 — 가져오기 · 백업 · 내보내기">
            <i class="bi bi-box-seam"></i>
        </button>
        <div class="bke-kitmenu" id="bkeKitMenu" style="display:none;">
            <button type="button" data-kit="import"><i class="bi bi-box-arrow-in-down"></i> 블록 킷 가져오기</button>
            <button type="button" data-kit="backup"><i class="bi bi-shield-check"></i> 현재 상태 백업 <span>블록 페이지 전용</span></button>
            <button type="button" data-kit="export"><i class="bi bi-box-arrow-up"></i> 배포용 블록 킷 내보내기 <span>블록 페이지 전용</span></button>
        </div>
    </div>
    <button type="button" class="bke-iconbtn" id="bkeInteract"
            title="미리보기 조작 모드 — 슬라이더·버튼을 직접 눌러볼 수 있습니다. 링크로 이동하면 자동으로 돌아옵니다.">
        <i class="bi bi-hand-index"></i>
    </button>
    <div class="bke-topbar__divider"></div>
    <div class="bke-devices" role="group" aria-label="미리보기 폭">
        <button type="button" data-device="pc" class="active" title="PC (스테이지 폭)"><i class="bi bi-display"></i></button>
        <button type="button" data-device="wide" title="와이드 (1440px)">1440</button>
        <button type="button" data-device="desktop" title="데스크톱 (1280px)">1280</button>
        <button type="button" data-device="tablet" title="태블릿 (768px)">768</button>
        <button type="button" data-device="mobile" title="모바일 (360px)">360</button>
    </div>
    <button type="button" class="bke-iconbtn" id="bkeRefresh" title="미리보기 새로고침"><i class="bi bi-arrow-clockwise"></i></button>
    <a href="#" target="_blank" rel="noopener" class="bke-iconbtn" id="bkeOpenTab" title="새 탭에서 열기"><i class="bi bi-box-arrow-up-right"></i></a>
</div>

<!-- ================= 본문 ================= -->
<div class="bke-main">
    <!-- 좌: 컨텍스트 트리 -->
    <aside class="bke-left" id="bkeTree"></aside>

    <!-- 중앙: 미리보기 스테이지 -->
    <section class="bke-stage">
        <div class="bke-frame-wrap">
            <div class="bke-frame" id="bkeFrame">
                <iframe id="bkeIframe" title="미리보기"></iframe>
                <div class="bke-overlay" id="bkeOverlay">
                    <div class="bke-hover-box" id="bkeHoverBox"></div>
                    <div class="bke-hover-label" id="bkeHoverLabel"></div>
                    <div class="bke-selected-box" id="bkeSelectedBox"></div>
                    <button type="button" class="bke-add-pill" id="bkeAddPill" style="display:none;">
                        <i class="bi bi-plus-lg"></i> 아래에 행 추가
                    </button>
                </div>
            </div>
        </div>
        <div class="bke-stage-empty" id="bkeStageEmpty" style="display:none;"></div>
    </section>

    <!-- 우: 편집 패널 — 제목은 지금 보고 있는 것을 그대로 말한다 (직관적 탐색 원칙, 설계 6.8) -->
    <aside class="bke-right">
        <div class="bke-inspector__head" id="bkePanelTitle"><i class="bi bi-grid-1x2"></i> 화면 구성</div>
        <div class="bke-inspector__body" id="bkeInspector">
            <div class="bke-inspector-empty">
                <i class="bi bi-cursor"></i>
                왼쪽에서 편집할 화면을 선택하세요.
            </div>
        </div>
    </aside>
</div>

<!-- 행 편집 모달: 기존 행 폼을 임베드 모드(_embed=1)로 띄운다 -->
<div class="bke-editmodal" id="bkeEditModal">
    <div class="bke-editmodal__backdrop" data-bke-close></div>
    <div class="bke-editmodal__panel">
        <div class="bke-editmodal__head">
            <span class="bke-editmodal__title" id="bkeEditTitle"><i class="bi bi-pencil-square"></i>행 설정</span>
            <button type="button" class="bke-iconbtn" data-bke-close title="닫기 (저장하지 않은 변경은 사라집니다)">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <iframe id="bkeEditIframe" title="행 설정"></iframe>
    </div>
</div>

<!-- 프레임 편집 모달: header/footer HTML 오버라이드 (도메인 프레임 편집) -->
<div class="bke-framemodal" id="bkeFrameModal">
    <div class="bke-editmodal__backdrop" data-bkef-close></div>
    <div class="bke-framemodal__panel">
        <div class="bke-editmodal__head">
            <span class="bke-editmodal__title" id="bkefTitle"><i class="bi bi-window-desktop"></i>프레임 편집</span>
            <span class="bkef-status" id="bkefStatus"></span>
            <button type="button" class="bke-iconbtn" data-bkef-close title="닫기 (저장하지 않은 변경은 사라집니다)">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="bkef-notice" id="bkefNotice" style="display:none;"></div>
        <div class="bkef-body">
            <div class="bkef-left">
                <div class="bkef-ai">
                    <textarea class="form-control form-control-sm" id="bkefAiPrompt" rows="3"
                              placeholder="AI에게 요청 — 예: 로고는 왼쪽, 메뉴는 가운데, 어두운 톤으로 바꿔줘"></textarea>
                    <div class="bkef-ai-row">
                        <select class="form-select form-select-sm" id="bkefAiMode" title="생성 모드">
                            <option value="auto">자동 판단</option>
                            <option value="create">새로 만들기</option>
                            <option value="modify">현재 내용 수정</option>
                        </select>
                        <button type="button" class="bke-btn bke-btn--soft" id="bkefAiRun"><i class="bi bi-stars"></i> AI 생성</button>
                    </div>
                    <button type="button" class="bke-btn" id="bkefAiUndo" style="display:none;">AI 결과 되돌리기</button>
                    <div class="bkef-ai-status" id="bkefAiStatus"></div>
                    <div class="bke-quality" id="bkefQuality"></div>
                    <details class="bkef-group" id="bkefHistoryWrap" style="display:none;">
                        <summary>최근 AI 이력<em id="bkefHistoryCount"></em></summary>
                        <div class="bkef-group__body" id="bkefHistory"></div>
                    </details>
                </div>
                <div class="bkef-palette" id="bkefPalette"></div>
            </div>
            <div class="bkef-editor">
                <div class="bkef-tabs">
                    <button type="button" class="bkef-tab active" data-bkef-tab="html">HTML</button>
                    <button type="button" class="bkef-tab" data-bkef-tab="css">CSS</button>
                    <button type="button" class="bkef-tab" data-bkef-tab="js">JS</button>
                    <button type="button" class="bkef-tab bkef-wrap-toggle" id="bkefWrapToggle" title="긴 줄을 화면 폭에 맞춰 접기">줄바꿈</button>
                </div>
                <div class="bkef-code" data-bkef-code="html">
                    <div class="bkef-gutter" aria-hidden="true">1</div>
                    <textarea class="bkef-textarea" id="bkefHtml" spellcheck="false" wrap="off"></textarea>
                </div>
                <div class="bkef-code" data-bkef-code="css" style="display:none;">
                    <div class="bkef-gutter" aria-hidden="true">1</div>
                    <textarea class="bkef-textarea" id="bkefCss" spellcheck="false" wrap="off" placeholder=".mublo-frame-header 를 기준으로 스타일을 작성하세요"></textarea>
                </div>
                <div class="bkef-code" data-bkef-code="js" style="display:none;">
                    <div class="bkef-gutter" aria-hidden="true">1</div>
                    <textarea class="bkef-textarea" id="bkefJs" spellcheck="false" wrap="off" placeholder="선택 — 게시 시 body 끝 근처에 삽입됩니다"></textarea>
                </div>
            </div>
        </div>
        <div class="bkef-foot">
            <button type="button" class="bke-btn bke-btn--warn" id="bkefRevert" style="display:none;" title="게시를 해제하고 파일 스킨으로 복귀 — 사이트에 즉시 반영">스킨으로 되돌리기</button>
            <button type="button" class="bke-btn" id="bkefReseed" style="display:none;" title="에디터 내용만 시드로 교체 — 저장 전까지 사이트·보관 초안 무변경">시드에서 다시 시작</button>
            <span style="flex:1"></span>
            <button type="button" class="bke-btn" id="bkefSave">초안 저장</button>
            <button type="button" class="bke-btn bke-btn--soft" id="bkefPreview">저장 + 미리보기</button>
            <button type="button" class="bke-btn bke-btn--primary" id="bkefPublish">게시</button>
        </div>
    </div>
</div>

<!-- 콘텐츠 모달: HTML·이미지·동영상의 내용을 바로 등록/편집한다 (여백·스타일은 인스펙터 소관) -->
<div class="bke-contentmodal" id="bkeContentModal">
    <div class="bke-editmodal__backdrop" data-bkec-close></div>
    <div class="bke-contentmodal__panel">
        <div class="bke-editmodal__head">
            <span class="bke-editmodal__title" id="bkecTitle"><i class="bi bi-pencil-square"></i>내용 편집</span>
            <button type="button" class="bke-iconbtn" data-bkec-close title="닫기 (저장하지 않은 변경은 사라집니다)">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="bke-contentmodal__body" id="bkecBody">
            <!-- HTML: 비주얼 에디터 단독. 텍스트영역은 상주시킨다 — BlockHtmlEditor 인스턴스가 id 에 묶인다. -->
            <div id="bkecHtml" style="display:none;">
              <div class="bkec-html-workspace">
                <aside class="bkec-ai-workbench">
                <div class="card" id="bkecAiPanel">
                    <div class="card-body p-2">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <strong style="font-size:12.5px;"><i class="bi bi-stars"></i> AI로 HTML 만들기</strong>
                            <a href="/admin/ai-settings" target="_blank" rel="noopener" id="bkecAiSettings" class="ms-auto" style="font-size:11.5px;">AI 설정</a>
                        </div>
                        <textarea id="bkecAiPrompt" class="form-control form-control-sm" rows="7" maxlength="4000"
                                  placeholder="예: 서비스 장점을 3개의 카드로 보여주는 반응형 섹션을 만들어줘"></textarea>
                        <div class="text-muted mt-1" style="font-size:10.5px;">슬라이드·탭·아코디언은 검증된 Core 동작으로 안전하게 생성됩니다.</div>
                        <div class="d-flex align-items-center mt-2 mb-1">
                            <strong style="font-size:12px;">참고 자료</strong>
                            <label class="bke-btn ms-auto" style="cursor:pointer;font-size:11px;">
                                <i class="bi bi-paperclip"></i> 업로드
                                <input type="file" id="bkecAiFiles" multiple hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.md,.csv,.json,.docx,.xlsx,.pptx">
                            </label>
                        </div>
                        <div class="text-muted mb-1" style="font-size:10.5px;">이미지·PDF·TXT·MD·CSV·JSON·DOCX·XLSX·PPTX</div>
                        <div id="bkecAiAssets" class="bkec-asset-list"><span class="text-muted small">자료를 불러오는 중…</span></div>
                        <details class="mt-2">
                            <summary style="font-size:12px;cursor:pointer;">최근 생성 기록</summary>
                            <div id="bkecAiHistory" class="d-grid gap-1 mt-1"></div>
                        </details>
                        <div class="bkec-ai-actions">
                            <select id="bkecAiMode" class="form-select form-select-sm">
                                <option value="create">새로 만들기</option>
                                <option value="modify">현재 내용 수정</option>
                            </select>
                            <button type="button" class="bke-btn bke-btn--primary" id="bkecAiGenerate"><i class="bi bi-stars"></i> 생성</button>
                            <button type="button" class="bke-btn" id="bkecAiUndo" style="display:none;"><i class="bi bi-arrow-counterclockwise"></i> 되돌리기</button>
                        </div>
                        <span id="bkecAiStatus" class="text-muted bkec-ai-status" aria-live="polite"></span>
                        <div class="bke-quality" id="bkecQuality"></div>
                    </div>
                </div>
                </aside>
                <section class="bkec-editor-pane">
                <div class="block-html-editor-wrapper" data-editor-id="bke_html_content">
                    <textarea id="bke_html_content" style="width:100%;min-height:300px;"></textarea>
                </div>
                <details class="bke-acc" style="margin-top:10px;flex:0 0 auto;" id="bkecCssAcc">
                    <summary><i class="bi bi-filetype-css"></i> CSS <span style="font-weight:400;color:var(--bs-secondary-color);font-size:11px;" id="bkecCssBadge"></span> <i class="bi bi-chevron-down"></i></summary>
                    <div class="bke-acc__body">
                        <textarea id="bke_html_css" class="form-control form-control-sm" rows="6" spellcheck="false"
                                  placeholder=".my-block { padding: 20px; }" style="font-family:'JetBrains Mono',monospace;font-size:12px;"></textarea>
                        <div class="bke-note" style="margin-top:6px;">이 칸에만 적용되는 CSS 입니다. 입력하면 에디터 미리보기에 바로 반영됩니다.</div>
                    </div>
                </details>
                <details class="bke-acc" style="flex:0 0 auto;" id="bkecJsAcc">
                    <summary><i class="bi bi-filetype-js"></i> JavaScript <span style="font-weight:400;color:var(--bs-secondary-color);font-size:11px;" id="bkecJsBadge"></span> <i class="bi bi-chevron-down"></i></summary>
                    <div class="bke-acc__body">
                        <textarea id="bke_html_js" class="form-control form-control-sm" rows="6" spellcheck="false"
                                  placeholder="// block = 이 블록의 컨테이너 요소&#10;block.querySelector('.my-btn')?.addEventListener('click', function(){ ... });" style="font-family:'JetBrains Mono',monospace;font-size:12px;"></textarea>
                        <div class="bke-note" style="margin-top:6px;">방문자 화면에서 이 블록의 컨테이너를 <code>block</code> 변수로 받는 함수로 감싸져 실행됩니다 (CSS 도 이 블록 안으로 자동 스코핑). 직접 작성 영역은 신뢰할 수 있는 코드만 사용하며, AI 동작 코드는 Core가 별도 구간으로 관리합니다.</div>
                    </div>
                </details>
                </section>
              </div>
            </div>
            <div id="bkecImage" style="display:none;"></div>
            <div id="bkecVideo" style="display:none;"></div>
        </div>
        <div class="bke-contentmodal__foot">
            <button type="button" class="bke-btn" data-bkec-close>취소</button>
            <button type="button" class="bke-btn bke-btn--primary" id="bkecSave">저장</button>
        </div>
    </div>
</div>

<script>
// 프론트 미리보기 토큰 — 캔버스가 프론트와 같은 캐스케이드
// (tokens.css → 프레임 스킨 변수 rebind → 도메인 브랜드색)를 재현한다.
// 소비자: 바로 아래 front-preview-tokens.js (MubloFrontPreviewCss)
window.MubloFrontPreviewTokens = <?= json_encode($frontPreviewTokens ?? [], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= asset('/assets/js/admin/front-preview-tokens.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-html-editor/BlockHtmlEditorBase.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/block-html-editor/index.js') ?>"></script>

<script>
/* =====================================================================
 * 블록 에디터 (Phase 1: 뷰어 + 선택)
 *
 * 동일 출처 iframe 의 DOM 을 직접 읽어 렌더 마커를 행/칸에 매핑한다(설계 5.1).
 * 오버레이가 포인터를 캡처하므로 프론트 링크는 눌리지 않는다(설계 5.2).
 * ===================================================================== */
(function () {
    'use strict';

    const CONTEXTS = <?= json_encode($initialContexts ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const INITIAL = <?= json_encode($initialContext ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const CSRF = <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>;
    // 와이드 가능 위치 — 행 폼 updateWidthTypeByPosition() 과 같은 규칙
    const WIDE_POSITIONS = ['topbar', 'index', 'subhead', 'subfoot'];
    // 콘텐츠 타입 카드 + 스킨 목록 (BlockRegistry / BlockSkinService)
    const CONTENT_TYPES = <?= json_encode($contentTypes ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const SKIN_LISTS = <?= json_encode($skinLists ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const CAN_EDIT_INCLUDE = <?= !empty($canEditInclude) ? 'true' : 'false' ?>;
    // 전용 선택 UI(업로드·커스텀 셀렉터)를 쓰는 타입 — 네이티브 아이템 피커 대신 전체 설정으로 보낸다
    const COMPLEX_ITEM_TYPES = ['image'];
    const KIND_LABELS = { CORE: '기본', PLUGIN: '플러그인', PACKAGE: '패키지' };

    const els = {
        tree: document.getElementById('bkeTree'),
        chip: document.getElementById('bkeContextChip'),
        frame: document.getElementById('bkeFrame'),
        iframe: document.getElementById('bkeIframe'),
        overlay: document.getElementById('bkeOverlay'),
        hoverBox: document.getElementById('bkeHoverBox'),
        hoverLabel: document.getElementById('bkeHoverLabel'),
        selectedBox: document.getElementById('bkeSelectedBox'),
        stageEmpty: document.getElementById('bkeStageEmpty'),
        inspector: document.getElementById('bkeInspector'),
        refresh: document.getElementById('bkeRefresh'),
        openTab: document.getElementById('bkeOpenTab'),
        editModal: document.getElementById('bkeEditModal'),
        editIframe: document.getElementById('bkeEditIframe'),
        editTitle: document.getElementById('bkeEditTitle'),
        addPill: document.getElementById('bkeAddPill'),
    };

    const state = {
        context: null,      // 트리에서 고른 컨텍스트 객체
        rowsMeta: new Map(), // row_id → 행 메타 (rows API)
        targets: [],        // hit-test 대상 (frame/row/column) — DOM 스캔 결과
        hovered: null,
        selected: null,
    };

    /* ---------------- 좌측 트리 ---------------- */

    function esc(v) {
        const d = document.createElement('div');
        d.textContent = String(v ?? '');
        return d.innerHTML;
    }

    function treeItem(ctx, opts = {}) {
        const disabled = !ctx.preview_url;
        const cls = ['bke-tree-item'];
        if (opts.child) cls.push('bke-tree-item--child');
        if (disabled) cls.push('bke-tree-item--disabled');

        const badge = ctx.renderable === false
            ? '<i class="bi bi-eye-slash bke-tree-badge" title="현재 레이아웃에서 렌더되지 않습니다"></i>'
            : '';

        // 같은 제목의 페이지가 있을 수 있다 — 코드가 유일한 구분자다.
        const sub = ctx.code ? ` <span style="font-size:11px;color:var(--bs-secondary-color);font-weight:400;">/${esc(ctx.code)}</span>` : '';
        const title = disabled ? '미리보기할 URL이 없습니다' : (ctx.code ? '/p/' + ctx.code : '');

        return `<button type="button" class="${cls.join(' ')}" data-context-id="${esc(ctx.id)}"
                    ${title ? `title="${esc(title)}"` : ''}>
            <i class="bi ${opts.icon || 'bi-square'}"></i>
            <span class="bke-tree-item__label">${esc(ctx.label)}${sub}</span>
            ${badge}
            <span class="bke-tree-item__count">${ctx.row_count ?? 0}</span>
        </button>`;
    }

    function renderTree() {
        let html = '';

        html += '<div class="bke-tree-group"><div class="bke-tree-group__title">화면</div>';
        (CONTEXTS.screen || []).forEach(c => { html += treeItem(c, { icon: 'bi-display' }); });
        html += '</div>';

        // 편집 단위는 페이지다 — 메뉴 페이지(사이트의 실제 페이지들)가 트리의 중심.
        html += '<div class="bke-tree-group"><div class="bke-tree-group__title">페이지</div>';
        if ((CONTEXTS.menus || []).length === 0) {
            html += '<div style="font-size:12px;color:var(--bs-secondary-color);padding:2px 10px;">편집할 메뉴 페이지가 없습니다.</div>';
        }
        (CONTEXTS.menus || []).forEach(c => {
            html += treeItem(c, { icon: 'bi-window', child: (c.depth || 0) > 0 });
        });
        html += '</div>';

        html += '<div class="bke-tree-group"><div class="bke-tree-group__title">블록 페이지</div>';
        if ((CONTEXTS.pages || []).length === 0) {
            html += '<div style="font-size:12px;color:var(--bs-secondary-color);padding:2px 10px;">등록된 페이지가 없습니다.</div>';
        }
        (CONTEXTS.pages || []).forEach(c => { html += treeItem(c, { icon: 'bi-file-earmark' }); });
        html += '</div>';

        els.tree.innerHTML = html;

        els.tree.querySelectorAll('[data-context-id]').forEach(btn => {
            btn.addEventListener('click', () => selectContext(btn.dataset.contextId));
        });
    }

    function findContext(id) {
        const all = [
            ...(CONTEXTS.screen || []),
            ...(CONTEXTS.menus || []),
            ...(CONTEXTS.pages || []),
        ];
        return all.find(c => c.id === id) || null;
    }

    /* ---------------- 컨텍스트 선택 → 미리보기 로드 ---------------- */

    async function selectContext(id) {
        const ctx = findContext(id);
        if (!ctx) return;

        state.context = ctx;
        state.selected = null;
        state.hovered = null;

        els.tree.querySelectorAll('.bke-tree-item').forEach(b =>
            b.classList.toggle('active', b.dataset.contextId === id));

        els.chip.style.display = '';
        els.chip.textContent = ctx.label;

        history.replaceState(null, '', '?context=' + encodeURIComponent(id));

        hideOverlayBoxes();
        renderInspectorList();

        if (!ctx.preview_url) {
            els.frame.style.display = 'none';
            els.stageEmpty.style.display = '';
            els.stageEmpty.innerHTML = '<i class="bi bi-eye-slash"></i>'
                + '이 영역은 미리보기할 URL이 없습니다.<br>'
                + '<span style="font-size:12px;">외부 링크 메뉴이거나 현재 레이아웃에서 렌더되지 않는 위치입니다. 목록 기반 편집(블록 행 관리)을 이용하세요.</span>';
            els.openTab.style.visibility = 'hidden';
            return;
        }

        els.frame.style.display = '';
        els.stageEmpty.style.display = 'none';
        els.openTab.style.visibility = '';
        els.openTab.href = ctx.preview_url;

        // 행 메타와 iframe 을 병렬로 — 메타는 라벨용이라 늦게 와도 동작한다.
        loadRowsMeta(id);
        els.iframe.src = ctx.preview_url + (ctx.preview_url.includes('?') ? '&' : '?') + '_editor=1';
    }

    async function loadRowsMeta(contextId) {
        state.rowsMeta = new Map();
        try {
            const res = await fetch('/admin/block-editor/rows?context=' + encodeURIComponent(contextId));
            const json = await res.json();
            if (json.success && json.data && Array.isArray(json.data.rows)) {
                json.data.rows.forEach(r => state.rowsMeta.set(r.row_id, r));
            }
        } catch (e) { /* 라벨 없이도 선택은 동작한다 */ }

        // 선택이 살아 있으면(저장 직후 재선택 대기 포함) 목록으로 덮어쓰지 않는다.
        if (!state.selected && !state.pendingSelect) {
            renderInspectorList();
        }
        refreshTargets();
    }

    /* ---------------- iframe 스캔 → hit-test 대상 구축 ---------------- */

    function iframeDoc() {
        try { return els.iframe.contentDocument; } catch (e) { return null; }
    }

    function refreshTargets() {
        const doc = iframeDoc();
        state.targets = [];
        if (!doc || !doc.body) return;

        // 헤더/푸터 특수 영역 (설계 5.2) — 마커 우선, 시맨틱 폴백
        const header = doc.querySelector('.mublo-header') || doc.querySelector('body > header, header');
        const footer = doc.querySelector('.mublo-footer') || doc.querySelector('body > footer, footer');
        if (header) state.targets.push({ kind: 'frame', part: 'header', el: header, label: '헤더 · 사이트 프레임' });
        if (footer) state.targets.push({ kind: 'frame', part: 'footer', el: footer, label: '푸터 · 사이트 프레임' });

        // 행: .block-section--{rowId} (마커 계약, 설계 10.3)
        doc.querySelectorAll('.block-section').forEach(section => {
            const m = String(section.className).match(/block-section--(\d+)/);
            if (!m) return;
            const rowId = parseInt(m[1], 10);
            state.targets.push({ kind: 'row', rowId, el: section });

            // 칸: #bc-{columnId} / 스택 콘텐츠 항목: #bc-{columnId}-c-{contentId}
            section.querySelectorAll('[id^="bc-"]').forEach(col => {
                const cm = String(col.id).match(/^bc-(\d+)$/);
                if (cm) {
                    state.targets.push({ kind: 'column', rowId, columnId: parseInt(cm[1], 10), el: col });
                    return;
                }
                const sm = String(col.id).match(/^bc-(\d+)-c-(\d+)$/);
                if (sm) {
                    // 항목이 칸 안쪽이므로 히트테스트(작은 면적 우선)가 항목을 먼저 잡는다
                    state.targets.push({ kind: 'column', rowId, columnId: parseInt(sm[1], 10), contentId: parseInt(sm[2], 10), el: col });
                }
            });
        });

        // 스크롤/리사이즈 시 좌표 재계산 (rAF 스로틀)
        const win = els.iframe.contentWindow;
        if (win && !win.__bkeBound) {
            win.__bkeBound = true;
            let raf = null;
            const onMove = () => {
                if (raf) return;
                raf = win.requestAnimationFrame(() => { raf = null; repositionBoxes(); });
            };
            win.addEventListener('scroll', onMove, { passive: true });
            win.addEventListener('resize', onMove);
        }
    }

    /* ---------------- 오버레이: hover / 선택 ---------------- */

    function rectOf(target) {
        // iframe 뷰포트 기준 rect — 오버레이가 iframe 을 정확히 덮으므로 그대로 쓴다.
        return target.el.getBoundingClientRect();
    }

    function hitTest(x, y) {
        // 칸 → 행 → 프레임 순으로, 같은 종류끼리는 면적이 작은 것을 맞춘다
        // (겹치거나 늘어난 rect 가 있어도 가장 구체적인 대상이 이긴다).
        const order = { column: 0, row: 1, frame: 2 };
        let best = null, bestArea = Infinity;
        for (const t of state.targets) {
            const r = rectOf(t);
            if (x < r.left || x > r.right || y < r.top || y > r.bottom) continue;
            const area = r.width * r.height;
            if (!best
                || order[t.kind] < order[best.kind]
                || (order[t.kind] === order[best.kind] && area < bestArea)) {
                best = t; bestArea = area;
            }
        }
        return best;
    }

    function labelOf(target) {
        if (target.kind === 'frame') return { text: target.label, global: true };
        const meta = state.rowsMeta.get(target.rowId);
        if (!meta) return { text: '행 #' + target.rowId + ' · 이 컨텍스트 밖', global: false };
        if (target.kind === 'row') {
            return { text: meta.admin_title + ' · ' + meta.scope_label, global: meta.is_global };
        }
        const col = (meta.columns || []).find(c => c.column_id === target.columnId);
        const colLabel = col ? (col.label || '빈 칸') : '칸';
        return { text: meta.admin_title + ' › ' + colLabel, global: meta.is_global };
    }

    function positionBox(box, rect) {
        box.style.left = rect.left + 'px';
        box.style.top = rect.top + 'px';
        box.style.width = rect.width + 'px';
        box.style.height = rect.height + 'px';
        box.style.opacity = '1';
    }

    function showHover(target) {
        if (!target) {
            els.hoverBox.style.opacity = '0';
            els.hoverLabel.style.opacity = '0';
            els.addPill.style.display = 'none';
            return;
        }
        const rect = rectOf(target);

        // 행/칸 hover 시 그 행 아래에 "+ 행 추가" 필을 띄운다 (설계 6.4 ①)
        if (target.kind === 'row' || target.kind === 'column') {
            const rowTarget = target.kind === 'row' ? target
                : state.targets.find(x => x.kind === 'row' && x.rowId === target.rowId);
            if (rowTarget) {
                const rowRect = rectOf(rowTarget);
                els.addPill.style.display = '';
                els.addPill.style.left = (rowRect.left + rowRect.width / 2) + 'px';
                els.addPill.style.top = rowRect.bottom + 'px';
                els.addPill.dataset.afterRow = rowTarget.rowId;
            }
        } else {
            els.addPill.style.display = 'none';
        }
        els.hoverBox.className = 'bke-hover-box'
            + (target.kind === 'column' ? ' is-column' : '')
            + (target.kind === 'frame' ? ' is-frame' : '');
        positionBox(els.hoverBox, rect);

        const label = labelOf(target);
        els.hoverLabel.className = 'bke-hover-label' + (target.kind === 'frame' ? ' is-frame' : '');
        els.hoverLabel.innerHTML = (label.global && target.kind !== 'frame'
                ? '<span class="bke-global-mark"><i class="bi bi-exclamation-triangle-fill"></i> 모든 페이지 공통</span>' : '')
            + esc(label.text);
        els.hoverLabel.style.left = rect.left + 'px';
        els.hoverLabel.style.top = Math.max(rect.top, 22) + 'px';
        els.hoverLabel.style.opacity = '1';
    }

    function repositionBoxes() {
        if (state.hovered) showHover(state.hovered);
        if (state.selected) {
            const rect = rectOf(state.selected);
            els.selectedBox.className = 'bke-selected-box' + (state.selected.kind === 'frame' ? ' is-frame' : '');
            positionBox(els.selectedBox, rect);
        } else {
            els.selectedBox.style.opacity = '0';
        }
    }

    function hideOverlayBoxes() {
        state.hovered = null;
        els.hoverBox.style.opacity = '0';
        els.hoverLabel.style.opacity = '0';
        els.selectedBox.style.opacity = '0';
    }

    els.overlay.addEventListener('mousemove', (e) => {
        if (e.target === els.addPill) return; // 필 위에서는 hover 상태를 유지한다
        const box = els.overlay.getBoundingClientRect();
        const t = hitTest(e.clientX - box.left, e.clientY - box.top);
        if (t !== state.hovered) { state.hovered = t; showHover(t); }
        els.overlay.style.cursor = t ? 'pointer' : 'default';
    });
    els.overlay.addEventListener('mouseleave', () => { state.hovered = null; showHover(null); });
    els.overlay.addEventListener('click', (e) => {
        const box = els.overlay.getBoundingClientRect();
        const t = hitTest(e.clientX - box.left, e.clientY - box.top);
        // 클릭 라우팅 (설계 6.1): 칸이든 행이든 인스펙터가 곧 편집 폼이다.
        // 단, 콘텐츠가 본체인 칸(HTML·이미지·동영상)은 내용 편집 모달로 직행하고,
        // 전용 셀렉터 타입(쇼핑몰 상품 등 객체형 아이템)은 그 셀렉터가 들어 있는
        // 전용 설정 모달로 직행한다 — "상품 클릭 = 상품 교체창".
        select(t);
        if (t && t.kind === 'column') {
            const m = state.rowsMeta.get(t.rowId);
            const col = m?.columns?.find(c => c.column_id === t.columnId);
            if (!col) return;
            if (col.content_mode === 'stack') {
                // 스택 칸 — 인스펙터가 목록/콘텐츠 편집을 직접 담당 (계획 8.3).
                // 항목 클릭(t.contentId)이면 renderColumnEditor 가 해당 콘텐츠를 바로 연다.
                return;
            }
            if (CONTENT_MODAL_TYPES.includes(col.content_type)) {
                openContentModal(t, m);
            } else if (col.object_items) {
                openRowEditor(t.rowId, domColumnIndex(t));
            }
        }
    });
    // 오버레이가 휠을 삼키면 미리보기를 스크롤할 수 없다 — iframe 으로 전달한다.
    els.overlay.addEventListener('wheel', (e) => {
        e.preventDefault();
        els.iframe.contentWindow?.scrollBy(e.deltaX, e.deltaY);
    }, { passive: false });

    function select(target) {
        state.selected = target;
        repositionBoxes();
        if (!target) { renderInspectorList(); return; }
        renderInspectorDetail(target);
    }

    /* ---------------- 행 편집 모달 (임베드, 설계 6) ---------------- */

    function columnIndexOf(target) {
        const meta = state.rowsMeta.get(target.rowId);
        const col = (meta?.columns || []).find(c => c.column_id === target.columnId);
        return col ? (col.index ?? -1) : -1;
    }

    /**
     * 칸 목록에서 칸을 열 때의 공통 라우팅 — 미리보기 클릭과 동일한 규칙(설계 6.1).
     * 콘텐츠가 본체인 타입은 콘텐츠 편집기로 직행, 그 외는 칸 인스펙터.
     * 미리보기 DOM 에 없는 칸(숨김 행 등)만 임베드 폼으로 폴백한다.
     */
    function openColumnByIndex(rowId, colIndex) {
        const meta = state.rowsMeta.get(rowId);
        const colMeta = (meta?.columns || []).find(c => (c.index ?? 0) === colIndex);
        const cols = state.targets.filter(t => t.kind === 'column' && !t.contentId && t.rowId === rowId);
        const target = cols[colIndex];

        if (target) {
            target.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            select(target);
            if (colMeta && colMeta.content_mode === 'stack') {
                // 인스펙터의 스택 목록이 편집 진입점 — select 만으로 충분
            } else if (colMeta && CONTENT_MODAL_TYPES.includes(colMeta.content_type)) {
                openContentModal(target, meta);
            } else if (colMeta && colMeta.object_items) {
                openRowEditor(rowId, colIndex);
            }
            return;
        }

        openRowEditor(rowId, colIndex);
    }

    function openRowEditor(rowId, columnIndex = -1) {
        const meta = state.rowsMeta.get(rowId);
        els.editTitle.innerHTML = '<i class="bi bi-pencil-square"></i>'
            + esc(meta ? meta.admin_title : ('행 #' + rowId))
            + (columnIndex >= 0 ? ` <span style="color:var(--bs-secondary-color);font-weight:400;">› ${columnIndex + 1}번째 칸</span>` : '');
        els.editIframe.src = '/admin/block-row/edit?id=' + rowId + '&_embed=1'
            + (columnIndex >= 0 ? '&open_column=' + columnIndex : '');
        els.editModal.classList.add('open');
    }

    function closeRowEditor() {
        els.editModal.classList.remove('open');
        els.editIframe.src = 'about:blank';
    }

    els.editModal.querySelectorAll('[data-bke-close]').forEach(el =>
        el.addEventListener('click', closeRowEditor));

    // 임베드 폼과의 통신: 저장되면 모달을 닫고 미리보기를 갱신한다.
    window.addEventListener('message', (e) => {
        if (e.origin !== window.location.origin || !e.data || typeof e.data !== 'object') return;

        if (e.data.type === 'bke:close') closeRowEditor();

        if (e.data.type === 'bke:row-saved') {
            closeRowEditor();
            refreshPreviewAfterSave(e.data.rowId);
        }
    });

    /* ---------------- 인스펙터 ---------------- */

    /** 편집 패널 제목 — 지금 편집 중인 대상을 그대로 말한다 */
    function setPanelTitle(icon, text) {
        document.getElementById('bkePanelTitle').innerHTML = `<i class="bi ${icon}"></i> ${esc(text)}`;
    }

    function chipsHtml(meta) {
        const chips = (meta.columns || []).map(c => {
            // 스택 칸 — 타입 하나가 아니라 콘텐츠 badge 목록 (계획 8.3)
            if (c.content_mode === 'stack' && Array.isArray(c.contents)) {
                return c.contents.map(ct => {
                    const label = ct.label || ct.content_type || '미설정';
                    const warn = ct.installed ? '' : ' style="color:var(--bke-global)"';
                    const dim = ct.is_active ? '' : ' bke-chip--empty';
                    const tip = ct.installed ? label : `${label} — 확장 설치 필요`;
                    return `<span class="bke-chip${dim}"${warn} title="${esc(tip)}">${esc(label)}</span>`;
                }).join('');
            }
            if (!c.content_type) return '<span class="bke-chip bke-chip--empty" title="빈 칸">빈 칸</span>';
            const label = c.label || c.content_type;
            const warn = c.installed ? '' : ' style="color:var(--bke-global)"';
            const tip = c.installed ? label : `${label} — 확장 설치 필요`;
            return `<span class="bke-chip"${warn} title="${esc(tip)}">${esc(label)}</span>`;
        });
        return chips.length ? `<div class="bke-chips">${chips.join('')}</div>` : '';
    }

    /** 칸 목록·인스펙터의 칸 라벨 — 스택 칸은 콘텐츠 요약으로 표기 */
    function columnDisplayLabel(c) {
        if (c && c.content_mode === 'stack' && Array.isArray(c.contents)) {
            const names = c.contents.map(ct => ct.label || ct.content_type || '미설정');
            return names.length ? `${names.join(' + ')} (${names.length})` : '스택 (비어 있음)';
        }
        return c && c.content_type ? (c.label || c.content_type) : '빈 칸';
    }

    function renderInspectorList() {
        if (!state.context) return;
        setPanelTitle('bi-grid-1x2', '화면 구성');

        const rows = Array.from(state.rowsMeta.values());
        let html = `<div style="display:flex;align-items:center;margin-bottom:10px;">
            <span style="flex:1;font-size:12px;color:var(--bs-secondary-color);">${esc(state.context.label)} · 행 ${rows.length}개</span>
            <button type="button" class="bke-btn" style="flex:0 0 auto;padding:5px 10px;" id="bkeAddRowBtn">
                <i class="bi bi-plus-lg"></i> 행 추가</button>
        </div>`;

        html += kitSetupHtml(); // 블록 킷 적용 직후의 설정 순회 체크리스트 (설계 6.7 ③)

        if (rows.length === 0) {
            html += '<div class="bke-inspector-empty"><i class="bi bi-plus-square-dotted"></i>아직 행이 없습니다.<br>[행 추가]로 시작하세요.</div>';
        }

        html += '<div id="bkeRowList">';
        rows.forEach((meta, i) => {
            const pills = [];
            if (meta.is_global) pills.push('<span class="bke-pill bke-pill--global">전역</span>');
            if (!meta.is_active) pills.push('<span class="bke-pill bke-pill--hidden">숨김</span>');
            html += `<div class="bke-row-card" data-row-id="${meta.row_id}">
                <div class="bke-row-card__title">
                    <i class="bi bi-grip-vertical bke-row-card__grip" title="드래그로 순서 변경"></i>
                    <span class="bke-row-card__no">${i + 1}</span>
                    ${esc(meta.admin_title)} ${pills.join('')}
                </div>
                ${meta.excerpt ? `<div class="bke-row-card__excerpt">“${esc(meta.excerpt)}”</div>` : ''}
                <div class="bke-row-card__scope">${esc(meta.scope_label)}</div>
                ${chipsHtml(meta)}
            </div>`;
        });
        html += '</div>';

        html += `<div class="bke-note"><i class="bi bi-info-circle"></i>
            미리보기에서 <strong>칸을 클릭하면 바로 설정</strong>이 열립니다. 저장하면 미리보기가 자동으로 갱신됩니다.</div>`;

        els.inspector.innerHTML = html;

        document.getElementById('bkeAddRowBtn')?.addEventListener('click', () =>
            openAddRowDialog(contextTargetFields(), null));

        bindKitSetup();
        initRowListSortable();

        els.inspector.querySelectorAll('.bke-row-card').forEach(card => {
            const rowId = parseInt(card.dataset.rowId, 10);

            card.addEventListener('click', () => {
                const target = state.targets.find(t => t.kind === 'row' && t.rowId === rowId);
                if (target) {
                    target.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    select(target);
                } else {
                    // 숨김 행 등 DOM 에 없는 행 — 정보만 보여준다.
                    select({ kind: 'row', rowId, el: null, offDom: true });
                }
            });

            // 카드에 마우스를 올리면 미리보기의 해당 섹션이 밝아진다 —
            // "이 행이 화면 어디인가"를 눈으로 연결해 준다.
            card.addEventListener('mouseenter', () => {
                const target = state.targets.find(t => t.kind === 'row' && t.rowId === rowId);
                if (target) showHover(target);
            });
            card.addEventListener('mouseleave', () => showHover(state.hovered));
        });
    }

    function renderInspectorDetail(target) {
        let html = `<button type="button" class="bke-detail-back" id="bkeBack">
            <i class="bi bi-chevron-left"></i> ${esc(state.context?.label || '')} 화면 구성으로
        </button>`;

        if (target.kind === 'frame') {
            els.inspector.innerHTML = html + '<div class="text-muted" style="font-size:12px;">불러오는 중…</div>';
            document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
            renderFramePanel(target, html);
            return;
        } else {
            const meta = state.rowsMeta.get(target.rowId);
            if (!meta) {
                html += `<div class="bke-detail">
                    <h6>행 #${target.rowId}</h6>
                    <div class="bke-detail__scope">이 컨텍스트 밖의 행 (같은 화면에 함께 렌더됨)</div>
                    <div class="bke-detail__actions">
                        <button type="button" class="bke-btn bke-btn--primary" data-bke-edit-row="${target.rowId}">행 설정 열기</button>
                    </div>
                </div>`;
            } else if (target.kind === 'row' && !target.offDom) {
                // 행 선택 → 인스펙터가 곧 편집 폼이다 (모달 없음)
                els.inspector.innerHTML = html + '<div class="text-muted" style="font-size:12px;">불러오는 중…</div>';
                document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
                renderRowEditor(target, meta, html);
                return;
            } else if (target.kind === 'column') {
                // 칸 선택 → 칸 인스펙터 (설계 6.1 클릭 라우팅)
                els.inspector.innerHTML = html + '<div class="text-muted" style="font-size:12px;">불러오는 중…</div>';
                document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
                renderColumnEditor(target, meta, html);
                return;
            } else {
                const pills = [];
                if (meta.is_global) pills.push('<span class="bke-pill bke-pill--global">전역</span>');
                if (!meta.is_active) pills.push('<span class="bke-pill bke-pill--hidden">숨김</span>');

                let colsHtml = '';
                (meta.columns || []).forEach(c => {
                    const isSel = target.kind === 'column' && c.column_id === target.columnId;
                    colsHtml += `<div class="bke-col-item${isSel ? ' active' : ''}" style="cursor:pointer;"
                        data-bke-edit-column="${c.index ?? 0}" title="클릭해서 콘텐츠 편집">
                        <span class="bke-col-item__no">${(c.index ?? 0) + 1}</span>
                        <span class="bke-col-item__label">${esc(columnDisplayLabel(c))}</span>
                        <span class="bke-col-item__hint">${c.content_type ? (c.installed ? '' : '설치 필요') : '콘텐츠 없음'}</span>
                        <i class="bi bi-pencil" style="font-size:11px;color:var(--bs-secondary-color);"></i>
                    </div>`;
                });

                html += `<div class="bke-detail">
                    <h6>${esc(meta.admin_title)} ${pills.join('')}</h6>
                    <div class="bke-detail__scope">${esc(meta.scope_label)}</div>
                    ${meta.is_global ? `<div class="bke-alert-global"><i class="bi bi-exclamation-triangle"></i>
                        이 행은 <strong>모든 페이지 공통</strong>입니다. 여기서 고치면 다른 페이지에도 함께 반영됩니다.</div>` : ''}
                    ${target.offDom ? '<div class="bke-note">숨김 상태라 미리보기에는 나타나지 않습니다.</div>' : ''}
                    ${colsHtml}
                    <div class="bke-detail__actions">
                        <button type="button" class="bke-btn bke-btn--primary" data-bke-edit-row="${target.rowId}">행 설정 열기</button>
                    </div>
                </div>`;
            }
        }

        els.inspector.innerHTML = html;
        document.getElementById('bkeBack')?.addEventListener('click', () => select(null));

        els.inspector.querySelectorAll('[data-bke-edit-row]').forEach(btn =>
            btn.addEventListener('click', () => openRowEditor(parseInt(btn.dataset.bkeEditRow, 10))));
        els.inspector.querySelectorAll('[data-bke-edit-column]').forEach(item =>
            item.addEventListener('click', () =>
                openColumnByIndex(target.rowId, parseInt(item.dataset.bkeEditColumn, 10))));
    }

    /* ---------------- 행 인스펙터 — 네이티브 편집 (설계 6.1: 모달 없음) ---------------- */

    async function renderRowEditor(target, meta, backHtml) {
        setPanelTitle('bi-pencil-square', '행 편집');
        let data;
        try {
            const res = await fetch('/admin/block-editor/row-data?id=' + target.rowId);
            const json = await res.json();
            if (!json.success) throw new Error(json.message);
            data = json.data;
        } catch (e) {
            els.inspector.innerHTML = backHtml
                + '<div class="bke-alert-global">행 데이터를 불러오지 못했습니다.</div>';
            document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
            return;
        }

        const f = data.form;
        const pills = [];
        if (meta.is_global) pills.push('<span class="bke-pill bke-pill--global">전역</span>');

        // 컨테이너 내부 위치는 와이드가 불가능하다 (행 폼과 같은 규칙)
        const wideAllowed = !f.position || WIDE_POSITIONS.includes(f.position);

        let colsHtml = '';
        (meta.columns || []).forEach(c => {
            colsHtml += `<div class="bke-col-item" style="cursor:pointer;" data-bke-edit-column="${c.index ?? 0}" title="클릭해서 콘텐츠 편집">
                <span class="bke-col-item__no">${(c.index ?? 0) + 1}</span>
                <span class="bke-col-item__label">${esc(columnDisplayLabel(c))}</span>
                <i class="bi bi-pencil" style="font-size:11px;color:var(--bs-secondary-color);"></i>
            </div>`;
        });

        els.inspector.innerHTML = backHtml + `<div class="bke-detail">
            <h6>${esc(meta.admin_title)} ${pills.join('')}</h6>
            <div class="bke-detail__scope">${esc(meta.scope_label)}</div>
            ${meta.is_global ? `<div class="bke-alert-global"><i class="bi bi-exclamation-triangle"></i>
                이 행은 <strong>모든 페이지 공통</strong>입니다. 저장하면 다른 페이지에도 함께 반영됩니다.</div>` : ''}

            <div class="bke-rowactions">
                <button type="button" id="rfMoveUp" title="위로 이동"><i class="bi bi-arrow-up"></i> 위로</button>
                <button type="button" id="rfMoveDown" title="아래로 이동"><i class="bi bi-arrow-down"></i> 아래로</button>
                <button type="button" id="rfDelete" class="danger" title="행 삭제"><i class="bi bi-trash"></i> 삭제</button>
            </div>

            <div class="bke-form-row">
                <label class="bke-form-label">관리용 제목</label>
                <input type="text" class="form-control form-control-sm" id="rf_admin_title" value="${esc(f.admin_title)}">
            </div>
            <div class="bke-form-row bke-switch">
                <input type="checkbox" class="form-check-input" role="switch" id="rf_is_active" ${f.is_active ? 'checked' : ''}>
                <label for="rf_is_active" style="cursor:pointer;">화면에 표시 <span style="color:var(--bs-secondary-color);">(끄면 숨김)</span></label>
            </div>

            <details class="bke-acc" open>
                <summary><i class="bi bi-layout-three-columns"></i> 레이아웃 <i class="bi bi-chevron-down"></i></summary>
                <div class="bke-acc__body">
                    <div class="bke-form-grid">
                        <div class="bke-form-row">
                            <label class="bke-form-label">넓이 타입</label>
                            <select class="form-select form-select-sm" id="rf_width_type" ${wideAllowed ? '' : 'disabled title="이 위치는 최대넓이만 가능합니다"'}>
                                <option value="0" ${String(f.width_type) === '0' ? 'selected' : ''}>와이드 (전체)</option>
                                <option value="1" ${String(f.width_type) !== '0' ? 'selected' : ''}>최대넓이</option>
                            </select>
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">칸 수</label>
                            <select class="form-select form-select-sm" id="rf_column_count">
                                ${[1,2,3,4].map(n => `<option value="${n}" ${Number(f.column_count) === n ? 'selected' : ''}>${n}칸</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div class="bke-form-row">
                        <label class="bke-form-label">칸 간격 (px)</label>
                        <input type="number" class="form-control form-control-sm" id="rf_column_margin" min="0" value="${esc(f.column_margin)}">
                    </div>
                </div>
            </details>

            ${f.position === 'topbar' ? `
            <details class="bke-acc">
                <summary><i class="bi bi-eye-slash"></i> 보지 않기 <i class="bi bi-chevron-down"></i></summary>
                <div class="bke-acc__body">
                    <div class="bke-form-row bke-switch">
                        <input type="checkbox" class="form-check-input" role="switch" id="rf_dismissible" ${Number(f.dismissible) ? 'checked' : ''}>
                        <label for="rf_dismissible" style="cursor:pointer;">"보지 않기" 버튼 표시 <span style="color:var(--bs-secondary-color);">(방문자가 일정 기간 숨김)</span></label>
                    </div>
                    <div class="bke-form-row">
                        <label class="bke-form-label">숨김 기간</label>
                        <select class="form-select form-select-sm" id="rf_dismiss_hours">
                            ${[[24,'1일'],[72,'3일'],[168,'7일'],[336,'14일'],[720,'30일']].map(o => `<option value="${o[0]}" ${Number(f.dismiss_hours) === o[0] ? 'selected' : ''}>${o[1]}</option>`).join('')}
                        </select>
                    </div>
                </div>
            </details>` : ''}

            <details class="bke-acc">
                <summary><i class="bi bi-arrows-angle-expand"></i> 상세 설정 <i class="bi bi-chevron-down"></i></summary>
                <div class="bke-acc__body">
                    <div class="bke-note" style="margin:0 0 8px;">
                        <strong>바깥 여백</strong>은 행 사이 간격, <strong>안쪽 여백</strong>은 배경 안의 공간입니다.<br>
                        순서는 <strong>위 → 오른쪽 → 아래 → 왼쪽</strong> —
                        하나만 쓰면 사방 동일(<code>20px</code>),
                        두 개면 위아래 · 좌우(<code>20px 10px</code>).
                    </div>
                    <div class="bke-form-grid">
                        <div class="bke-form-row">
                            <label class="bke-form-label">PC 바깥 여백</label>
                            <input type="text" class="form-control form-control-sm" id="rf_pc_margin" placeholder="예: 40px 0 0 0" value="${esc(f.pc_margin)}">
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">모바일 바깥 여백</label>
                            <input type="text" class="form-control form-control-sm" id="rf_mobile_margin" placeholder="예: 20px 0 0 0" value="${esc(f.mobile_margin)}">
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">PC 안쪽 여백</label>
                            <input type="text" class="form-control form-control-sm" id="rf_pc_padding" placeholder="예: 25px 10px 20px 25px" value="${esc(f.pc_padding)}">
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">모바일 안쪽 여백</label>
                            <input type="text" class="form-control form-control-sm" id="rf_mobile_padding" placeholder="예: 15px 10px" value="${esc(f.mobile_padding)}">
                        </div>
                    </div>
                    <div class="bke-form-row">
                        <label class="bke-form-label">배경 색상</label>
                        <div style="display:flex;gap:6px;">
                            <input type="color" class="form-control form-control-color form-control-sm" id="rf_bg_color_picker"
                                   value="${esc(f.bg_color || '#ffffff')}" style="width:38px;padding:2px;">
                            <input type="text" class="form-control form-control-sm" id="rf_bg_color" placeholder="비워두면 기본" value="${esc(f.bg_color)}">
                        </div>
                    </div>
                    ${f.bg_image_old ? `<div class="bke-note" style="margin-top:0;">배경 이미지가 설정되어 있습니다. 이미지 교체·삭제는 <button type="button" class="bke-linkbtn" data-bke-edit-row="${target.rowId}">전체 설정</button>에서.</div>` : ''}
                </div>
            </details>

            <label class="bke-form-label" style="margin-top:4px;">칸 구성 <span style="font-weight:400;">— 클릭해서 콘텐츠 편집</span></label>
            ${colsHtml}

            <div class="bke-apply-bar">
                <button type="button" class="bke-btn bke-btn--primary" id="rfApply" style="flex:1;">적용</button>
                <button type="button" class="bke-linkbtn" data-bke-edit-row="${target.rowId}">전체 설정</button>
            </div>
        </div>`;

        document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
        els.inspector.querySelectorAll('[data-bke-edit-column]').forEach(item =>
            item.addEventListener('click', () =>
                openColumnByIndex(target.rowId, parseInt(item.dataset.bkeEditColumn, 10))));
        els.inspector.querySelectorAll('[data-bke-edit-row]').forEach(btn =>
            btn.addEventListener('click', () => openRowEditor(parseInt(btn.dataset.bkeEditRow, 10))));

        // 컬러 픽커 ↔ 텍스트 동기화
        const picker = document.getElementById('rf_bg_color_picker');
        const colorText = document.getElementById('rf_bg_color');
        picker.addEventListener('input', () => { colorText.value = picker.value; });

        document.getElementById('rfApply').addEventListener('click', () => applyRowForm(target.rowId, data));
        document.getElementById('rfMoveUp').addEventListener('click', () => moveRow(meta, -1));
        document.getElementById('rfMoveDown').addEventListener('click', () => moveRow(meta, 1));
        document.getElementById('rfDelete').addEventListener('click', () => deleteRowAction(meta));
    }

    /** 행 폼(blockrow-form.js)이 저장하는 공통 콘텐츠 설정 기본값과 동일하게 맞춘다 */
    function defaultContentConfig() {
        return {
            pc_count: 4, mo_count: 4, aos: null, aos_duration: 600,
            pc_style: 'list', mo_style: 'list', pc_cols: '4', mo_cols: '2',
            pc_autoplay: 0, mo_autoplay: 0, pc_loop: false, mo_loop: false,
            pc_slide_cover: false, mo_slide_cover: false,
        };
    }

    /* ---------------- 출력 스타일 공용 컴포넌트 ----------------
     * 행 폼(블록 행 관리)의 출력 스타일 카드와 동일한 구성·명칭·저장 키.
     * 이미지 콘텐츠 모달과 칸 편집 패널이 함께 쓴다.
     * 값은 전달받은 config 객체에 바로 쓴다 — 저장은 호출부 소관. */

    function outputStyleHtml(c) {
        const colOptions = (sel) => Array.from({ length: 12 }, (_, i) => i + 1)
            .map(n => `<option value="${n}" ${String(n) === String(sel) ? 'selected' : ''}>${n}개</option>`).join('')
            + `<option value="auto" ${String(sel) === 'auto' ? 'selected' : ''}>자동</option>`;
        const styleOptions = (sel) => ['list|리스트형', 'slide|슬라이드형', 'none|숨김']
            .map(o => { const [v, l] = o.split('|'); return `<option value="${v}" ${v === sel ? 'selected' : ''}>${l}</option>`; }).join('');

        const device = (dev, label, defCols, defDelay) => {
            const style = c[`${dev}_style`] || 'list';
            const autoplay = Number(c[`${dev}_autoplay`] || 0);
            return `<div>
                <div style="font-size:12px;font-weight:600;color:var(--bs-secondary-color);margin-bottom:6px;">${label}</div>
                <div class="bke-form-grid">
                    <div class="bke-form-row">
                        <label class="bke-form-label">스타일</label>
                        <select class="form-select form-select-sm" data-oscfg="${dev}_style">${styleOptions(style)}</select>
                    </div>
                    <div class="bke-form-row">
                        <label class="bke-form-label">1줄 출력갯수</label>
                        <select class="form-select form-select-sm" data-oscfg="${dev}_cols">${colOptions(c[`${dev}_cols`] || defCols)}</select>
                    </div>
                </div>
                <div data-oscfg-slide="${dev}" style="${style === 'slide' ? '' : 'display:none;'}">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                        <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                            <input type="checkbox" class="form-check-input" data-oscfg="${dev}_autoplay_check" ${autoplay > 0 ? 'checked' : ''}> 자동재생
                        </label>
                        <input type="number" class="form-control form-control-sm" style="width:90px;"
                               data-oscfg="${dev}_autoplay_delay" value="${autoplay || defDelay}" min="1000" max="30000" step="500" ${autoplay > 0 ? '' : 'disabled'}>
                        <span style="font-size:11.5px;color:var(--bs-secondary-color);">ms</span>
                    </div>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;margin-bottom:4px;">
                        <input type="checkbox" class="form-check-input" data-oscfg="${dev}_loop" ${c[`${dev}_loop`] ? 'checked' : ''}> 무한반복
                    </label>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                        <input type="checkbox" class="form-check-input" data-oscfg="${dev}_slide_cover" ${c[`${dev}_slide_cover`] ? 'checked' : ''}> 이미지 높이 맞춤 (cover)
                    </label>
                </div>
            </div>`;
        };

        return `<div class="bke-form-grid" style="align-items:start;">
            ${device('pc', 'PC 출력', '4', 5000)}
            ${device('mo', '모바일 출력', '2', 3000)}
        </div>`;
    }

    function bindOutputStyle(container, c) {
        const el = (key) => container.querySelector(`[data-oscfg="${key}"]`);
        const apply = () => {
            ['pc', 'mo'].forEach(dev => {
                const style = el(`${dev}_style`).value;
                c[`${dev}_style`] = style;
                c[`${dev}_cols`] = el(`${dev}_cols`).value;

                const autoOn = el(`${dev}_autoplay_check`).checked;
                el(`${dev}_autoplay_delay`).disabled = !autoOn;
                c[`${dev}_autoplay`] = autoOn
                    ? (parseInt(el(`${dev}_autoplay_delay`).value) || (dev === 'pc' ? 5000 : 3000)) : 0;
                c[`${dev}_loop`] = el(`${dev}_loop`).checked;
                c[`${dev}_slide_cover`] = el(`${dev}_slide_cover`).checked;

                container.querySelector(`[data-oscfg-slide="${dev}"]`).style.display
                    = style === 'slide' ? '' : 'none';
            });
        };
        container.querySelectorAll('[data-oscfg]').forEach(input =>
            input.addEventListener('change', apply));
    }

    function emptyColumnPayload() {
        return {
            width: '', pc_padding: '', mobile_padding: '',
            content_type: '', content_kind: 'CORE', content_skin: '',
            background_config: '{"color":""}',
            border_config: '{"width":"","color":"","radius":""}',
            title_config: '{}',
            content_config: JSON.stringify(defaultContentConfig()),
            content_items: '[]',
            is_active: 1,
        };
    }

    async function applyRowForm(rowId, data) {
        const f = { ...data.form };
        f.admin_title = document.getElementById('rf_admin_title').value.trim();
        f.is_active = document.getElementById('rf_is_active').checked ? 1 : 0;
        f.width_type = document.getElementById('rf_width_type').value;
        // topbar "보지 않기" (해당 필드는 topbar 행에서만 렌더됨)
        f.dismissible = document.getElementById('rf_dismissible')?.checked ? 1 : 0;
        f.dismiss_hours = parseInt(document.getElementById('rf_dismiss_hours')?.value, 10) || 24;
        f.column_count = parseInt(document.getElementById('rf_column_count').value, 10) || 1;
        f.column_margin = document.getElementById('rf_column_margin').value || 0;
        f.pc_margin = document.getElementById('rf_pc_margin').value.trim();
        f.mobile_margin = document.getElementById('rf_mobile_margin').value.trim();
        f.pc_padding = document.getElementById('rf_pc_padding').value.trim();
        f.mobile_padding = document.getElementById('rf_mobile_padding').value.trim();
        f.bg_color = document.getElementById('rf_bg_color').value.trim();

        // 칸 수 조정 — 나머지 칸 데이터는 그대로 통과시킨다 (row-data 계약).
        let columns = data.columns.slice();
        const doSubmit = () =>
            submitRow(f, columns, document.getElementById('rfApply'), { rowId });

        if (f.column_count > columns.length) {
            while (columns.length < f.column_count) columns.push(emptyColumnPayload());
        } else if (f.column_count < columns.length) {
            const dropped = columns.slice(f.column_count).filter(c => c.content_type);
            const removed = columns.length - f.column_count;
            columns = columns.slice(0, f.column_count);
            if (dropped.length) {
                MubloRequest.showConfirm(
                    `칸 수를 줄이면 뒤쪽 ${removed}개 칸의 콘텐츠가 삭제됩니다. 계속할까요?`,
                    doSubmit,
                    { type: 'warning', confirmText: '줄이기' }
                );
                return;
            }
        }

        await doSubmit();
    }

    /* ---------------- 블록 킷 동선 (설계 6.7) — 가져오기·백업·내보내기·설정 순회 ---------------- */

    const kitMenu = document.getElementById('bkeKitMenu');
    document.getElementById('bkeKitBtn').addEventListener('click', (e) => {
        e.stopPropagation();
        // 백업/내보내기는 페이지 블록 킷이 자기완결적인 블록 페이지 컨텍스트 전용
        const isPage = /^page:\d+$/.test(state.context?.id || '');
        kitMenu.querySelector('[data-kit="backup"]').disabled = !isPage;
        kitMenu.querySelector('[data-kit="export"]').disabled = !isPage;
        kitMenu.style.display = kitMenu.style.display === 'none' ? '' : 'none';
    });
    document.addEventListener('click', () => { kitMenu.style.display = 'none'; });
    kitMenu.addEventListener('click', (e) => e.stopPropagation());

    kitMenu.querySelector('[data-kit="import"]').addEventListener('click', () => {
        kitMenu.style.display = 'none';
        openKitImportDialog();
    });
    kitMenu.querySelector('[data-kit="backup"]').addEventListener('click', () => {
        kitMenu.style.display = 'none';
        downloadPageKit('clone');
    });
    kitMenu.querySelector('[data-kit="export"]').addEventListener('click', () => {
        kitMenu.style.display = 'none';
        downloadPageKit('distribution');
    });

    /**
     * 현재 블록 페이지를 블록 킷 JSON 으로 내려받는다 — 기존 내보내기 엔드포인트를
     * 숨은 폼 제출로 호출한다(응답이 attachment 다운로드).
     * clone = 백업(참조 유지), distribution = 배포용(참조 비움).
     * 스크린샷·버전 등 저작 메타는 블록 페이지 관리의 내보내기 모달 소관.
     */
    function downloadPageKit(mode) {
        const m = (state.context?.id || '').match(/^page:(\d+)$/);
        if (!m) return;

        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/admin/block-page/export';
        form.target = '_blank';
        form.style.display = 'none';
        const add = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = name; input.value = value;
            form.appendChild(input);
        };
        add('_token', CSRF);
        add('page_id', m[1]);
        add('export_mode', mode);
        add('kit_name', (state.context.label || '페이지') + (mode === 'clone' ? ' 백업' : ' 블록 킷'));
        document.body.appendChild(form);
        form.submit();
        form.remove();
        MubloRequest.showToast(mode === 'clone' ? '백업 블록 킷을 내려받습니다.' : '배포용 블록 킷을 내려받습니다.', 'success');
    }

    /** 블록 킷 가져오기 — 업로드 → dry-run 미리보기 → 적용 → 대상 컨텍스트 자동 오픈 */
    function openKitImportDialog() {
        const overlay = document.createElement('div');
        overlay.className = 'bke-typepicker';
        overlay.innerHTML = `<div class="bke-typepicker__backdrop"></div>
            <div class="bke-typepicker__panel" style="width:480px;">
                <div class="bke-typepicker__title">블록 킷 가져오기</div>
                <div class="bke-alert-global" style="margin-bottom:10px;"><i class="bi bi-exclamation-triangle"></i>
                    블록 킷은 사이트에서 스크립트를 실행할 수 있습니다. 신뢰하는 배포자의 것만 적용하세요.</div>
                <div class="bke-form-row">
                    <label class="bke-form-label">블록 킷 파일 (.json)</label>
                    <input type="file" class="form-control form-control-sm" id="bkeKitFile" accept=".json,application/json">
                </div>
                <div class="bke-form-row">
                    <label class="bke-form-label">적용 모드</label>
                    <div style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;">
                        <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                            <input type="radio" class="form-check-input" name="bkeKitMode" value="append" checked> 이어붙이기
                        </label>
                        <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                            <input type="radio" class="form-check-input" name="bkeKitMode" value="replace">
                            교체 <span style="color:var(--bke-global);font-size:11px;">(대상의 기존 행을 삭제합니다)</span>
                        </label>
                    </div>
                </div>
                <div id="bkeKitPreview" style="display:none;margin-bottom:10px;"></div>
                <div style="display:flex;gap:6px;">
                    <button type="button" class="bke-btn" style="flex:1;" id="bkeKitPreviewBtn">미리보기</button>
                    <button type="button" class="bke-btn bke-btn--primary" style="flex:1;" id="bkeKitApplyBtn" disabled>적용</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('.bke-typepicker__backdrop').addEventListener('click', () => overlay.remove());
        let previewTargetKind = null;

        const buildFd = () => {
            const input = overlay.querySelector('#bkeKitFile');
            if (!input.files.length) { MubloRequest.showAlert('블록 킷 파일을 선택하세요.', 'warning'); return null; }
            const fd = new FormData();
            fd.append('kit_file', input.files[0]);
            fd.append('_token', CSRF);
            return fd;
        };

        overlay.querySelector('#bkeKitFile').addEventListener('change', () => {
            overlay.querySelector('#bkeKitApplyBtn').disabled = true;
            previewTargetKind = null;
        });

        overlay.querySelector('#bkeKitPreviewBtn').addEventListener('click', async () => {
            const fd = buildFd();
            if (!fd) return;
            const box = overlay.querySelector('#bkeKitPreview');
            box.style.display = '';
            box.innerHTML = '<div class="text-muted" style="font-size:12px;">검증 중…</div>';
            try {
                const res = await fetch('/admin/block-row/kit-preview', { method: 'POST', body: fd });
                const json = await res.json();
                const d = json.data || {};
                if (!json.success || !d.ok) {
                    box.innerHTML = `<div class="bke-alert-global">${esc(json.message || (d.errors || []).join(' ') || '적용할 수 없는 블록 킷입니다.')}</div>`;
                    return;
                }
                const s = d.summary || {};
                previewTargetKind = s.target_kind || null;
                box.innerHTML = `<div class="bke-note" style="margin:0;">
                    <strong>${s.row_count || 0}</strong>개 행 · <strong>${s.column_count || 0}</strong>개 칸이 생성됩니다.
                    ${s.target_kind === 'screen' ? '<br><strong>메인화면 복합 킷</strong> — 슬롯과 레이아웃 설정을 함께 적용합니다.' : ''}
                    ${s.contains_script ? '<span style="color:var(--bke-global);"> · ⚠ 스크립트 포함</span>' : ' · 스크립트 없음'}
                    ${(s.needs_setup || []).length ? `<br>적용 후 설정이 필요한 칸 <strong>${s.needs_setup.length}</strong>개 — 적용하면 체크리스트로 안내합니다.` : ''}
                    ${(d.warnings || []).length ? '<br>' + d.warnings.map(esc).join('<br>') : ''}
                </div>`;
                overlay.querySelector('#bkeKitApplyBtn').disabled = false;
            } catch (e) {
                box.innerHTML = '<div class="bke-alert-global">요청에 실패했습니다.</div>';
            }
        });

        overlay.querySelector('#bkeKitApplyBtn').addEventListener('click', async () => {
            const fd = buildFd();
            if (!fd) return;
            const mode = overlay.querySelector('input[name="bkeKitMode"]:checked').value;
            if (mode === 'replace') {
                const message = previewTargetKind === 'screen'
                    ? '메인화면 대상 슬롯의 기존 전역 행을 삭제합니다. 공용 영역은 다른 화면에도 영향을 줍니다. 계속할까요?'
                    : '대상의 기존 행을 삭제합니다. 계속할까요?';
                if (!confirm(message)) return;
            }
            fd.append('mode', mode);
            const btn = overlay.querySelector('#bkeKitApplyBtn');
            btn.disabled = true; btn.textContent = '적용 중…';
            try {
                const res = await fetch('/admin/block-row/kit-apply', { method: 'POST', body: fd });
                const json = await res.json();
                if (!(json.success || json.result === 'success')) {
                    MubloRequest.showAlert(json.message || '적용에 실패했습니다.', 'error');
                    btn.disabled = false; btn.textContent = '적용';
                    return;
                }
                overlay.remove();
                await afterKitApplied(json.data || {});
            } catch (e) {
                MubloRequest.showAlert('요청에 실패했습니다.', 'error');
                btn.disabled = false; btn.textContent = '적용';
            }
        });
    }

    /**
     * 블록 킷 적용 직후 — 대상 컨텍스트를 자동으로 열고(§6.7 ②) needs_setup 을
     * 클릭 순회 체크리스트로 바꾼다(§6.7 ③).
     */
    async function afterKitApplied(result) {
        const s = result.summary || {};
        MubloRequest.showToast('블록 킷이 적용되었습니다.', 'success');

        // 대상 컨텍스트 결정: 페이지 블록 킷 → 그 페이지, 위치 블록 킷 → 메뉴 페이지 or 메인화면
        let contextId = null;
        if (s.target_kind === 'page' && s.page_id) {
            contextId = 'page:' + s.page_id;
            if (s.created_page) {
                // 새 페이지는 트리에 없다 — 컨텍스트 트리를 다시 읽는다
                try {
                    const res = await fetch('/admin/block-editor/contexts');
                    const json = await res.json();
                    if (json.success) {
                        Object.assign(CONTEXTS, json.data);
                        renderTree();
                    }
                } catch (e) { /* 트리만 낡을 뿐 동작한다 */ }
            }
        } else if (s.target_kind === 'position') {
            contextId = s.menu_code ? 'menu:' + s.menu_code : 'screen:main';
        } else if (s.target_kind === 'screen' && s.screen === 'main') {
            contextId = 'screen:main';
        }

        // 설정 체크리스트 준비 — 실제 행 ID 매핑은 컨텍스트 로드 후에 한다
        state.kitSetup = (s.needs_setup || []).length ? {
            contextId,
            createdRows: s.created_rows || 0,
            mode: s.mode || 'append',
            targetKind: s.target_kind,
            pageId: s.page_id || 0,
            items: s.needs_setup.map(i => ({ ...i, done: false })),
            rowIds: null,
        } : null;

        if (contextId && findContext(contextId)) {
            await selectContext(contextId);
        } else if (state.context) {
            await selectContext(state.context.id);
        }
    }

    /**
     * 블록 킷 행 인덱스 → 실제 행 ID 매핑.
     *
     * 캐시하지 않는다 — 컨텍스트 전환 직후의 목록 렌더는 이전 컨텍스트의 메타로
     * 실행될 수 있어, 그때 캐시하면 엉뚱한 행에 매핑된다. 메타 로드가 끝나면
     * 목록이 다시 렌더되므로 매번 현재 메타로 계산한다.
     */
    function resolveKitRowIds() {
        const ks = state.kitSetup;
        if (!ks) return;

        let metas = Array.from(state.rowsMeta.values());
        if (ks.targetKind === 'page') {
            metas = metas.sort((a, b) => (a.sort_order - b.sort_order) || (a.row_id - b.row_id));
        }
        // 이어붙이기는 맨 뒤에 생성됐고, 교체는 블록 킷 행이 전부다.
        ks.rowIds = (ks.mode === 'replace' ? metas : metas.slice(-ks.createdRows)).map(m => m.row_id);
    }

    function kitSetupHtml() {
        const ks = state.kitSetup;
        if (!ks || ks.contextId !== state.context?.id) return '';
        resolveKitRowIds();

        const remaining = ks.items.filter(i => !i.done).length;
        const REASONS = { image_missing: '이미지를 지정하세요', items_empty: '표시할 내용을 고르세요', extension_missing: '확장 설치가 필요합니다' };

        let html = `<div class="bke-kitsetup">
            <div class="bke-kitsetup__title"><i class="bi bi-list-check"></i>
                블록 킷 설정 체크리스트 — 남은 칸 ${remaining}개
                <button type="button" class="bke-linkbtn" id="bkeKitSetupClose">닫기</button>
            </div>`;
        ks.items.forEach((item, idx) => {
            const rowId = (ks.rowIds || [])[item.row_index];
            const label = `${item.row_index + 1}행 ${item.column_index + 1}칸 · ${esc(item.content_type || '블록')}`;
            html += `<div class="bke-kitsetup__item ${item.done ? 'done' : ''}" data-ks-idx="${idx}" ${rowId ? `data-ks-row="${rowId}"` : ''}>
                <input type="checkbox" class="form-check-input" ${item.done ? 'checked' : ''}>
                <span style="flex:1;">${label} — ${esc(REASONS[item.reason] || '설정하세요')}${item.kit_hint ? ` <span style="color:var(--bs-secondary-color);">(${esc(item.kit_hint)})</span>` : ''}</span>
            </div>`;
        });
        html += '</div>';
        return html;
    }

    function bindKitSetup() {
        document.getElementById('bkeKitSetupClose')?.addEventListener('click', () => {
            state.kitSetup = null;
            renderInspectorList();
        });
        els.inspector.querySelectorAll('.bke-kitsetup__item').forEach(el => {
            const idx = parseInt(el.dataset.ksIdx, 10);
            el.querySelector('input').addEventListener('click', (e) => {
                e.stopPropagation();
                state.kitSetup.items[idx].done = e.target.checked;
                el.classList.toggle('done', e.target.checked);
            });
            el.addEventListener('click', () => {
                const rowId = parseInt(el.dataset.ksRow || '0', 10);
                if (!rowId) { MubloRequest.showAlert('이 칸의 행을 찾지 못했습니다. 미리보기에서 직접 클릭하세요.', 'warning'); return; }
                const cols = state.targets.filter(t => t.kind === 'column' && !t.contentId && t.rowId === rowId);
                const target = cols[state.kitSetup.items[idx].column_index]
                    || state.targets.find(t => t.kind === 'row' && t.rowId === rowId);
                if (!target) { MubloRequest.showAlert('미리보기에서 해당 칸을 찾지 못했습니다.', 'warning'); return; }
                target.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                select(target);
            });
        });
    }

    /* ---------------- 행 드래그 순서 (설계 6.5) ---------------- */

    /**
     * 인스펙터 행 목록을 드래그로 재배열한다 (Sortable — 관리자 프레임에 이미 로드됨).
     *
     * 순서는 스코프(scope_label) 안에서만 의미가 있다 — 드롭 후 DOM 순서를
     * 스코프별로 끊어 1..n 을 재부여하므로, 다른 스코프 카드 사이에 떨어져도
     * "자기 스코프 안에서의 상대 순서"만 반영된다.
     */
    function initRowListSortable() {
        const list = document.getElementById('bkeRowList');
        if (!list || typeof Sortable === 'undefined' || list.children.length < 2) return;

        new Sortable(list, {
            animation: 150,
            handle: '.bke-row-card__grip',
            onEnd: async () => {
                const orders = {};
                const counters = {}; // scope_label → 다음 순번
                let changed = false;

                list.querySelectorAll('.bke-row-card').forEach(card => {
                    const meta = state.rowsMeta.get(parseInt(card.dataset.rowId, 10));
                    if (!meta) return;
                    const scope = meta.scope_label;
                    counters[scope] = (counters[scope] || 0) + 1;
                    orders[meta.row_id] = counters[scope];
                    if (meta.sort_order !== counters[scope]) changed = true;
                });

                if (!changed) return;

                try {
                    await MubloRequest.requestJson('/admin/block-row/order-set', { orders });
                    MubloRequest.showToast('순서가 저장되었습니다.', 'success');
                    refreshPreviewAfterSave(null);
                } catch (e) { /* MubloRequest 가 오류 모달을 띄운다 */ }
            },
        });
    }

    /* ---------------- 프레임 스킨 패널 (설계 6.6) ---------------- */

    async function renderFramePanel(target, backHtml) {
        setPanelTitle('bi-window-desktop', '사이트 프레임');
        let data;
        let frameData = null;
        try {
            const [skinRes, statusRes] = await Promise.all([
                fetch('/admin/block-editor/frame-skins'),
                fetch('/admin/block-editor/frame-status?part=' + encodeURIComponent(target.part)),
            ]);
            const json = await skinRes.json();
            if (!json.success) throw new Error(json.message);
            data = json.data;
            const statusJson = await statusRes.json();
            if (statusJson.success) frameData = statusJson.data;
        } catch (e) {
            els.inspector.innerHTML = backHtml + '<div class="bke-alert-global">스킨 목록을 불러오지 못했습니다.</div>';
            document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
            return;
        }

        const partLabel = target.part === 'header' ? '헤더' : '푸터';
        const published = !!(frameData && frameData.published);
        const editStateLabel = published
            ? '게시됨 — HTML 오버라이드가 파일 스킨 대신 렌더 중'
            : (frameData && frameData.has_draft ? '사용안함 — 초안 보관됨 (재게시 가능)' : '파일 스킨 사용 중');

        els.inspector.innerHTML = backHtml + `<div class="bke-detail">
            <h6>${esc(partLabel)}</h6>
            <div class="bke-detail__scope">사이트 프레임 · 전 페이지 (패키지 전용 프레임 화면 — 예: 결제 — 제외)</div>
            <div class="bke-alert-global"><i class="bi bi-exclamation-triangle"></i>
                프레임 스킨은 헤더·푸터·레이아웃을 <strong>한 벌</strong>로 바꾸며
                <strong>사이트 전체</strong>에 적용됩니다.</div>
            <div class="bke-form-row">
                <label class="bke-form-label">프레임 스킨</label>
                <select class="form-select form-select-sm" id="fsSkin">
                    ${data.skins.map(s => `<option value="${esc(s.value)}" ${s.value === data.current ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}
                </select>
            </div>
            ${data.skins.length <= 1 ? '<div class="bke-note">지금은 스킨이 하나뿐입니다. views/Front/frame/ 에 스킨 폴더를 추가하면 자동으로 목록에 나타납니다.</div>' : ''}
            <div class="bke-detail__actions">
                <button type="button" class="bke-btn bke-btn--primary" id="fsApply">적용</button>
            </div>
            ${frameData ? `
            <hr>
            <h6>${esc(partLabel)} HTML 직접 편집</h6>
            <div class="bke-detail__scope">${esc(editStateLabel)}</div>
            <div class="bke-note">파일 배포 없이 ${esc(partLabel)}를 HTML로 직접 편집합니다.
                게시 전까지는 사이트에 반영되지 않으며, 언제든 파일 스킨으로 되돌릴 수 있습니다.</div>
            <div class="bke-detail__actions">
                <button type="button" class="bke-btn bke-btn--primary" id="bkefOpen">HTML 직접 편집</button>
                ${published ? '<button type="button" class="bke-btn" id="bkefQuickRevert">스킨으로 되돌리기</button>' : ''}
            </div>` : ''}
        </div>`;

        document.getElementById('bkefOpen')?.addEventListener('click', () => openFrameEditor(target.part, frameData));
        document.getElementById('bkefQuickRevert')?.addEventListener('click', () => frameRevert(target.part, () => renderFramePanel(target, backHtml)));

        document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
        document.getElementById('fsApply').addEventListener('click', async () => {
            const skin = document.getElementById('fsSkin').value;
            if (skin === data.current) { MubloRequest.showToast('이미 사용 중인 스킨입니다.', 'info'); return; }

            MubloRequest.showConfirm(
                `프레임 스킨을 '${skin}' 으로 바꿉니다. 사이트 전체의 헤더·푸터·레이아웃이 함께 바뀝니다.`,
                async () => {
                    const fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('skin', skin);
                    try {
                        const res = await fetch('/admin/block-editor/frame-skin', { method: 'POST', body: fd });
                        const json = await res.json();
                        if (!(json.success || json.result === 'success')) {
                            MubloRequest.showAlert(json.message || '변경에 실패했습니다.', 'error');
                            return;
                        }
                        MubloRequest.showToast(json.message || '프레임 스킨이 변경되었습니다.', 'success');
                        data.current = skin;
                        refreshPreviewAfterSave(null);
                    } catch (e) { MubloRequest.showAlert('요청에 실패했습니다.', 'error'); }
                },
                { type: 'warning', confirmText: '변경' }
            );
        });
    }

    /* ---------------- 프레임 HTML 편집 (도메인 프레임 편집) ---------------- */

    const bkef = {
        modal: document.getElementById('bkeFrameModal'),
        title: document.getElementById('bkefTitle'),
        status: document.getElementById('bkefStatus'),
        notice: document.getElementById('bkefNotice'),
        palette: document.getElementById('bkefPalette'),
        html: document.getElementById('bkefHtml'),
        css: document.getElementById('bkefCss'),
        js: document.getElementById('bkefJs'),
        save: document.getElementById('bkefSave'),
        preview: document.getElementById('bkefPreview'),
        publish: document.getElementById('bkefPublish'),
        revert: document.getElementById('bkefRevert'),
        reseed: document.getElementById('bkefReseed'),
        aiPrompt: document.getElementById('bkefAiPrompt'),
        aiRun: document.getElementById('bkefAiRun'),
        aiStatus: document.getElementById('bkefAiStatus'),
        quality: document.getElementById('bkefQuality'),
        aiMode: document.getElementById('bkefAiMode'),
        aiUndo: document.getElementById('bkefAiUndo'),
        historyWrap: document.getElementById('bkefHistoryWrap'),
        historyCount: document.getElementById('bkefHistoryCount'),
        history: document.getElementById('bkefHistory'),
    };
    const frameState = { part: null, seededFromSkin: null };

    document.querySelectorAll('[data-bkef-close]').forEach(el =>
        el.addEventListener('click', () => bkef.modal.classList.remove('open')));

    document.querySelectorAll('[data-bkef-tab]').forEach(btn =>
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-bkef-tab]').forEach(b => b.classList.toggle('active', b === btn));
            const tab = btn.getAttribute('data-bkef-tab');
            document.querySelectorAll('[data-bkef-code]').forEach(w =>
                w.style.display = w.getAttribute('data-bkef-code') === tab ? '' : 'none');
        }));

    /* 코드 에디터 흉내: 줄번호 거터 + Tab 들여쓰기 + Enter 자동 들여쓰기 + 줄바꿈 토글 */
    let bkefWrap = localStorage.getItem('bkef-wrap') === '1';

    // 줄바꿈 모드에서 각 논리 줄의 렌더 높이를 재는 미러 (1회 reflow로 전체 측정)
    const bkefMirror = document.createElement('div');
    bkefMirror.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;'
        + 'font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12.5px;line-height:1.55;'
        + 'white-space:pre-wrap;overflow-wrap:anywhere;';
    document.body.appendChild(bkefMirror);

    function enhanceCodeArea(ta) {
        const gutter = ta.parentElement.querySelector('.bkef-gutter');
        const update = () => {
            const lines = ta.value.split('\n');
            if (!bkefWrap) {
                gutter.textContent = Array.from({ length: lines.length }, (_, i) => i + 1).join('\n');
            } else {
                // 접힌 줄 높이에 맞춰 줄번호 정렬
                bkefMirror.style.width = Math.max(50, ta.clientWidth - 24) + 'px';
                bkefMirror.innerHTML = lines.map(l => '<div>' + (l ? esc(l) : '&nbsp;') + '</div>').join('');
                const kids = bkefMirror.children;
                let html = '';
                for (let i = 0; i < kids.length; i++) {
                    html += '<div style="height:' + kids[i].offsetHeight + 'px">' + (i + 1) + '</div>';
                }
                gutter.innerHTML = html;
                bkefMirror.innerHTML = '';
            }
            gutter.scrollTop = ta.scrollTop;
        };
        ta.addEventListener('input', update);
        ta.addEventListener('scroll', () => { gutter.scrollTop = ta.scrollTop; });
        ta.addEventListener('keydown', e => {
            const s = ta.selectionStart, epos = ta.selectionEnd, v = ta.value;
            if (e.key === 'Tab') {
                e.preventDefault();
                if (s !== epos && v.slice(s, epos).includes('\n')) {
                    // 여러 줄 선택: 줄 단위 들여쓰기/내어쓰기
                    const ls = v.lastIndexOf('\n', s - 1) + 1;
                    const block = v.slice(ls, epos);
                    const changed = e.shiftKey
                        ? block.replace(/^ {1,4}/gm, '')
                        : block.replace(/^/gm, '    ');
                    ta.value = v.slice(0, ls) + changed + v.slice(epos);
                    ta.selectionStart = ls;
                    ta.selectionEnd = ls + changed.length;
                } else if (e.shiftKey) {
                    // 커서 줄 내어쓰기
                    const ls = v.lastIndexOf('\n', s - 1) + 1;
                    const m = v.slice(ls).match(/^ {1,4}/);
                    if (m) {
                        ta.value = v.slice(0, ls) + v.slice(ls + m[0].length);
                        ta.selectionStart = ta.selectionEnd = Math.max(ls, s - m[0].length);
                    }
                } else {
                    ta.value = v.slice(0, s) + '    ' + v.slice(epos);
                    ta.selectionStart = ta.selectionEnd = s + 4;
                }
                update();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const ls = v.lastIndexOf('\n', s - 1) + 1;
                const indent = (v.slice(ls, s).match(/^[ \t]*/) || [''])[0];
                ta.value = v.slice(0, s) + '\n' + indent + v.slice(epos);
                ta.selectionStart = ta.selectionEnd = s + 1 + indent.length;
                update();
                // 커서 위치가 보이도록 스크롤
                ta.blur(); ta.focus();
            }
        });
        return update;
    }
    const bkefGutters = [bkef.html, bkef.css, bkef.js].map(enhanceCodeArea);
    function bkefRefreshGutters() { bkefGutters.forEach(u => u()); }

    function bkefApplyWrap() {
        document.querySelectorAll('.bkef-code').forEach(w => w.classList.toggle('is-wrap', bkefWrap));
        [bkef.html, bkef.css, bkef.js].forEach(ta => ta.setAttribute('wrap', bkefWrap ? 'soft' : 'off'));
        document.getElementById('bkefWrapToggle').classList.toggle('is-on', bkefWrap);
        bkefRefreshGutters();
    }
    document.getElementById('bkefWrapToggle').addEventListener('click', () => {
        bkefWrap = !bkefWrap;
        localStorage.setItem('bkef-wrap', bkefWrap ? '1' : '0');
        bkefApplyWrap();
    });
    bkefApplyWrap();
    window.addEventListener('resize', () => { if (bkefWrap && bkef.modal.classList.contains('open')) bkefRefreshGutters(); });

    async function openFrameEditor(part, statusData) {
        if (!statusData) {
            try {
                const res = await fetch('/admin/block-editor/frame-status?part=' + encodeURIComponent(part));
                const json = await res.json();
                if (!json.success) throw new Error(json.message);
                statusData = json.data;
            } catch (e) {
                MubloRequest.showAlert('프레임 편집 정보를 불러오지 못했습니다.', 'error');
                return;
            }
        }

        const partLabel = part === 'header' ? '헤더' : '푸터';
        frameState.part = part;
        frameState.seededFromSkin = statusData.seeded_from_skin || null;

        bkef.title.innerHTML = `<i class="bi bi-window-desktop"></i>${esc(partLabel)} HTML 편집`;
        bkef.status.textContent = statusData.published ? '게시됨' : (statusData.has_draft ? '사용안함 · 초안 보관' : '새 편집');
        bkef.status.className = 'bkef-status' + (statusData.published ? ' published' : '');
        bkef.revert.style.display = statusData.published ? '' : 'none';
        bkef.reseed.style.display = (statusData.has_draft && statusData.seed && statusData.seed.html) ? '' : 'none';

        const notices = [];
        if (statusData.has_draft) {
            bkef.html.value = statusData.draft.html;
            bkef.css.value = statusData.draft.css;
            bkef.js.value = statusData.draft.js;
            if (!statusData.published) {
                notices.push(`<strong>사용안함 상태</strong> — 보관된 초안입니다. 게시하면 다시 이 내용이 파일 스킨 대신 렌더됩니다.`);
            }
            if (frameState.seededFromSkin) {
                notices.push(`<strong>분리 사본</strong> — '${esc(frameState.seededFromSkin)}' 스킨 시드에서 시작한 사본입니다. 이후 스킨 변경은 이 ${esc(partLabel)}에 반영되지 않습니다.`);
                if (statusData.frame_skin && frameState.seededFromSkin !== statusData.frame_skin) {
                    notices.push(`⚠ 이 편집본은 '${esc(frameState.seededFromSkin)}' 스킨 기준으로 작성되었는데, 현재 스킨은 '${esc(statusData.frame_skin)}'입니다 — 게시하면 현재 스킨과 모양이 다를 수 있습니다.`);
                }
            }
        } else {
            const seed = statusData.seed || { html: '', skin: '' };
            bkef.html.value = seed.html;
            bkef.css.value = '';
            bkef.js.value = '';
            frameState.seededFromSkin = seed.skin || null;
            if (seed.skin) {
                notices.push(`<strong>시드에서 시작</strong> — 현재 화면과 동일한 상태에서 출발합니다. 편집을 시작하면 스킨에서 분리된 사본이 되어, 이후 스킨 변경이 반영되지 않습니다.`);
                if (statusData.frame_skin && seed.skin !== statusData.frame_skin) {
                    notices.push(`현재 스킨('${esc(statusData.frame_skin)}')은 편집 시드를 제공하지 않아 <strong>기본(${esc(seed.skin)}) 시드</strong>에서 시작합니다 — 현재 화면과 모양이 다를 수 있습니다.`);
                }
            } else {
                notices.push('사용 가능한 시드가 없어 빈 캔버스에서 시작합니다.');
            }
        }
        bkef.notice.innerHTML = notices.join('<br>');
        bkef.notice.style.display = notices.length ? '' : 'none';

        // 팔레트: 출처별 아코디언 — 기본 변수/슬롯 + 확장(패키지·플러그인)별 그룹
        const palette = statusData.palette || [];
        const token = p => `<div class="bkef-token" data-token="{{${esc(p.name)}}}"><code>{{${esc(p.name)}}}</code><span>${esc(p.label)}${p.kind === 'slot' ? ' · 슬롯' : ''}</span></div>`;
        const group = (title, items, open) => items.length
            ? `<details class="bkef-group"${open ? ' open' : ''}><summary>${title}<em>${items.length}</em></summary><div class="bkef-group__body">${items.map(token).join('')}</div></details>`
            : '';

        const extSources = [...new Set(palette.filter(p => p.source !== 'core').map(p => p.source))].sort();
        bkef.palette.innerHTML =
            group('기본 변수', palette.filter(p => p.source === 'core' && p.kind === 'variable'), false)
            + group('기본 슬롯', palette.filter(p => p.source === 'core' && p.kind === 'slot'), false)
            + extSources.map(src =>
                group(`확장 · ${esc(src)}`, palette.filter(p => p.source === src), false)).join('')
            + '<div class="bke-note" style="margin-top:8px">클릭하면 HTML 커서 위치에 삽입됩니다.</div>';
        bkef.palette.querySelectorAll('.bkef-token').forEach(el =>
            el.addEventListener('click', () => insertFrameToken(el.getAttribute('data-token'))));

        bkef.save.onclick = () => frameSaveDraft(false);
        bkef.preview.onclick = async () => {
            if (!(await frameSaveDraft(true))) return;
            const base = state.context?.preview_url || '/';
            els.iframe.src = base + (base.includes('?') ? '&' : '?') + '_editor=1&_frame_draft=' + encodeURIComponent(frameState.part);
            MubloRequest.showToast('초안을 미리보기에 반영했습니다. 뒤쪽 미리보기 화면을 확인하세요.', 'success');
        };
        bkef.publish.onclick = () => {
            // 게시 시 '수정 필요' 상태면 확인 경고 (§9.3) — 게시 자체는 막지 않는다
            const qualityWarning = bkef.quality.dataset.status === 'needs_fix'
                ? '⚠ 반응형 진단이 "수정 필요" 상태입니다. 좁은 화면에서 레이아웃이 깨질 수 있습니다.\n\n'
                : '';
            MubloRequest.showConfirm(
                qualityWarning + `${partLabel}를 게시합니다. 패키지 전용 프레임 화면(결제 등)을 제외한 모든 페이지에 즉시 반영됩니다.`,
                async () => {
                    if (!(await frameSaveDraft(true))) return;
                    const fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('part', frameState.part);
                    try {
                        const res = await fetch('/admin/block-editor/frame-publish', { method: 'POST', body: fd });
                        const json = await res.json();
                        if (!json.success) { MubloRequest.showAlert(json.message || '게시에 실패했습니다.', 'error'); return; }
                        MubloRequest.showToast(json.message || '게시되었습니다.', 'success');
                        bkef.modal.classList.remove('open');
                        refreshPreviewAfterSave(null);
                    } catch (e) { MubloRequest.showAlert('요청에 실패했습니다.', 'error'); }
                },
                { type: 'warning', confirmText: '게시' }
            );
        };
        bkef.revert.onclick = () => frameRevert(frameState.part, () => {
            bkef.modal.classList.remove('open');
            refreshPreviewAfterSave(null);
        });
        bkef.reseed.onclick = () => {
            MubloRequest.showConfirm(
                '편집 중인 내용을 버리고 시드에서 다시 시작합니다. 저장하기 전까지는 보관된 초안이 바뀌지 않습니다.',
                () => {
                    const seed = statusData.seed || { html: '', skin: '' };
                    bkef.html.value = seed.html;
                    bkef.css.value = '';
                    bkef.js.value = '';
                    frameState.seededFromSkin = seed.skin || null;
                    bkefRefreshGutters();
                    MubloRequest.showToast('시드를 불러왔습니다. 초안 저장을 눌러야 반영됩니다.', 'info');
                },
                { type: 'warning', confirmText: '다시 시작' }
            );
        };

        bkef.aiPrompt.value = '';
        bkef.aiStatus.textContent = '';
        bkef.aiMode.value = 'auto';
        bkef.aiUndo.style.display = 'none';
        frameState.aiSnapshot = null;
        bkef.quality.style.display = 'none';
        bkef.quality.dataset.status = '';
        bkef.quality.innerHTML = '';

        // 반응형 품질 진단 (§9.2·§9.3) — 서버 정적 검사 + 브라우저 실제 렌더 검사
        const bkefRunQuality = async (serverAudit) => {
            const findings = await bkeResponsiveProbe({
                html: bkef.html.value,
                css: bkef.css.value,
                frameLike: true,
                scopeClass: 'mublo-frame-' + frameState.part,
                headerPart: frameState.part === 'header',
            });
            bkeRenderQuality(bkef.quality, serverAudit, findings, {
                onRecheck: () => bkefRunQuality(serverAudit),
                onFix: (messages) => {
                    // 사용자가 보정 버튼을 눌렀을 때만 새 AI 요청을 보낸다 (§9.3)
                    bkef.aiMode.value = 'modify';
                    bkef.aiPrompt.value = '아래 반응형 진단 문제를 구조·내용·템플릿 토큰은 유지하면서 수정해줘:\n- '
                        + messages.slice(0, 8).join('\n- ');
                    bkef.aiRun.onclick();
                },
            });
        };

        // AI 결과 적용 공통: 적용 전 스냅샷 → 되돌리기 노출 (P4)
        const applyAiResult = (html, css, statusText, audit) => {
            frameState.aiSnapshot = { html: bkef.html.value, css: bkef.css.value, js: bkef.js.value };
            bkef.html.value = html;
            bkef.css.value = css;
            bkefRefreshGutters();
            bkef.aiUndo.style.display = '';
            bkef.aiStatus.textContent = statusText;
            bkefRunQuality(audit || null);
        };
        bkef.aiUndo.onclick = () => {
            if (!frameState.aiSnapshot) return;
            bkef.html.value = frameState.aiSnapshot.html;
            bkef.css.value = frameState.aiSnapshot.css;
            bkef.js.value = frameState.aiSnapshot.js;
            frameState.aiSnapshot = null;
            bkef.aiUndo.style.display = 'none';
            bkefRefreshGutters();
            MubloRequest.showToast('AI 적용 전 상태로 되돌렸습니다.', 'info');
        };

        // 최근 AI 이력 (P4) — 클릭 시 프롬프트 + 결과물 복원
        const loadFrameHistory = async () => {
            try {
                const res = await fetch('/admin/block-editor/frame-ai-history?part=' + encodeURIComponent(part));
                const json = await res.json();
                const items = json.success ? (json.data || []) : [];
                bkef.historyWrap.style.display = items.length ? '' : 'none';
                bkef.historyCount.textContent = items.length;
                bkef.history.innerHTML = items.map(r =>
                    `<div class="bkef-history-item" data-record="${r.record_id}" title="클릭: 프롬프트 + 결과 불러오기">
                        <div class="prompt">${esc(r.prompt)}</div>
                        <div class="meta">${r.status === 'invalid' ? '<span class="text-danger fw-bold">검증실패 · </span>' : ''}${esc(r.model)} · ${esc(r.created_at)}</div>
                    </div>`).join('');
                bkef.history.querySelectorAll('.bkef-history-item').forEach(el =>
                    el.addEventListener('click', async () => {
                        try {
                            const rec = await fetch('/admin/block-editor/ai-record?id=' + el.getAttribute('data-record'));
                            const recJson = await rec.json();
                            if (!recJson.success) { MubloRequest.showAlert(recJson.message || '이력을 불러오지 못했습니다.', 'error'); return; }
                            bkef.aiPrompt.value = recJson.data.prompt || '';
                            if ((recJson.data.result_html || '').trim() !== '') {
                                applyAiResult(recJson.data.result_html, recJson.data.result_css || '',
                                    '이력 결과를 불러왔습니다 — 이어서 수정하거나 저장하세요.');
                            }
                        } catch (e) { MubloRequest.showAlert('이력을 불러오지 못했습니다.', 'error'); }
                    }));
            } catch (e) { /* 이력은 보조 기능 — 실패해도 편집은 계속 */ }
        };
        loadFrameHistory();

        // AI 준비 상태 — HTML 블록 편집과 동일한 UX (개선 계획 §10.2)
        const frameAiReady = !!statusData.ai_ready;
        bkef.aiRun.disabled = !frameAiReady;
        bkef.aiPrompt.disabled = !frameAiReady;
        bkef.aiMode.disabled = !frameAiReady;
        if (!frameAiReady) {
            bkef.aiStatus.innerHTML = (statusData.api_key_configured
                ? 'AI 기능이 비활성화되어 있습니다. '
                : 'AI API 키가 등록되지 않았습니다. ')
                + '<a href="/admin/ai-settings" target="_blank" rel="noopener">AI 설정으로 이동</a>';
        }

        bkef.aiRun.onclick = async () => {
            if (!frameAiReady) return;
            const prompt = bkef.aiPrompt.value.trim();
            if (!prompt) { MubloRequest.showAlert('AI에게 요청할 내용을 입력하세요.', 'error'); return; }

            const modeSel = bkef.aiMode.value;
            const isModify = modeSel === 'modify' || (modeSel === 'auto' && bkef.html.value.trim() !== '');
            bkef.aiRun.disabled = true;
            bkef.aiStatus.textContent = isModify
                ? 'AI가 현재 코드를 수정하는 중입니다...'
                : 'AI가 새 코드를 생성하는 중입니다...';

            const fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('part', frameState.part);
            fd.append('prompt', prompt);
            fd.append('mode', isModify ? 'modify' : 'create');
            if (isModify) {
                fd.append('current_html', bkef.html.value);
                fd.append('current_css', bkef.css.value);
            }
            try {
                const res = await fetch('/admin/block-editor/frame-ai', { method: 'POST', body: fd });
                const json = await res.json();
                if (!json.success) {
                    bkef.aiStatus.textContent = json.message || 'AI 생성에 실패했습니다.';
                    MubloRequest.showAlert(json.message || 'AI 생성에 실패했습니다.', 'error');
                    return;
                }
                applyAiResult(json.data.html, json.data.css,
                    json.data.notes || 'AI 결과를 반영했습니다. 검토 후 저장하세요.', json.data.audit);
                MubloRequest.showToast('AI 결과를 에디터에 반영했습니다 — 저장 전까지 사이트에는 영향이 없습니다.', 'success');
                loadFrameHistory();
            } catch (e) {
                bkef.aiStatus.textContent = '요청에 실패했습니다.';
                MubloRequest.showAlert('요청에 실패했습니다.', 'error');
            } finally {
                bkef.aiRun.disabled = false;
            }
        };

        bkef.modal.classList.add('open');
        bkefRefreshGutters(); // 줄바꿈 모드 폭 측정은 모달이 보인 뒤에
    }

    function insertFrameToken(token) {
        const ta = bkef.html;
        const start = ta.selectionStart ?? ta.value.length;
        ta.value = ta.value.slice(0, start) + token + ta.value.slice(ta.selectionEnd ?? start);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + token.length;
        bkefRefreshGutters();
    }

    async function frameSaveDraft(silent) {
        if (!bkef.html.value.trim()) {
            MubloRequest.showAlert('HTML이 비어 있습니다.', 'error');
            return false;
        }
        const fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('part', frameState.part);
        fd.append('html', bkef.html.value);
        fd.append('css', bkef.css.value);
        fd.append('js', bkef.js.value);
        if (frameState.seededFromSkin) fd.append('seeded_from_skin', frameState.seededFromSkin);
        try {
            const res = await fetch('/admin/block-editor/frame-draft', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.success) { MubloRequest.showAlert(json.message || '저장에 실패했습니다.', 'error'); return false; }
            if (!silent) MubloRequest.showToast(json.message || '초안이 저장되었습니다.', 'success');
            return true;
        } catch (e) {
            MubloRequest.showAlert('요청에 실패했습니다.', 'error');
            return false;
        }
    }

    function frameRevert(part, onDone) {
        MubloRequest.showConfirm(
            '파일 스킨으로 되돌립니다. 편집 내용은 초안으로 보관되며 언제든 다시 게시할 수 있습니다.',
            async () => {
                const fd = new FormData();
                fd.append('_token', CSRF);
                fd.append('part', part);
                try {
                    const res = await fetch('/admin/block-editor/frame-revert', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (!json.success) { MubloRequest.showAlert(json.message || '되돌리기에 실패했습니다.', 'error'); return; }
                    MubloRequest.showToast(json.message || '파일 스킨으로 되돌렸습니다.', 'success');
                    onDone && onDone();
                } catch (e) { MubloRequest.showAlert('요청에 실패했습니다.', 'error'); }
            },
            { type: 'warning', confirmText: '되돌리기' }
        );
    }

    /* ---------------- 행 추가 · 순서 · 삭제 (설계 6.3/6.4) ---------------- */

    /**
     * 현재 컨텍스트가 새 행에 부여할 소속.
     * position 이 null 이면 다이얼로그가 "어디에 표시할까요"를 묻는다.
     */
    function contextTargetFields() {
        const id = state.context?.id || '';
        let m;
        if ((m = id.match(/^page:(\d+)$/))) {
            return { page_id: parseInt(m[1], 10), position: '', position_menu: '', slots: null };
        }
        if ((m = id.match(/^menu:(.+)$/))) {
            // 메뉴 페이지 — 슬롯 선택 + "이 페이지만/모든 페이지" 스코프를 묻는다
            return { page_id: 0, position: null, position_menu: m[1], slots: CONTEXTS.sub_slots || [], askScope: true };
        }
        // screen:main — 슬롯 선택(메인 본문 기본). 전역 위치(topbar 등)는 "전역/메인전용" 스코프를 묻는다.
        return { page_id: 0, position: null, position_menu: '', slots: CONTEXTS.main_slots || [], defaultSlot: 'index', askMainScope: true };
    }

    /**
     * 행 추가 3단계의 ① — 칸 수·넓이만 묻고 즉시 만든다 (설계 6.4).
     * afterMeta 가 있으면 그 행의 소속을 상속하고 바로 아래에 배치한다.
     */
    function openAddRowDialog(target, afterMeta) {
        // "이 행 아래에 추가"는 기준 행의 소속을 상속한다 — 슬롯을 묻지 않는다.
        if (afterMeta) target = { ...afterMeta.target, slots: null };

        const askSlot = target.position === null && (target.slots || []).length;

        const overlay = document.createElement('div');
        overlay.className = 'bke-typepicker';
        overlay.innerHTML = `<div class="bke-typepicker__backdrop"></div>
            <div class="bke-typepicker__panel" style="width:420px;">
                <div class="bke-typepicker__title">행 추가${afterMeta ? ` — "${esc(afterMeta.admin_title)}" 아래에` : ''}</div>
                ${askSlot ? `
                <label class="bke-form-label">어디에 표시할까요</label>
                <select class="form-select form-select-sm" id="bkeArSlot" style="margin-bottom:12px;">
                    ${target.slots.map(s => `<option value="${esc(s.value)}"
                        ${s.value === (target.defaultSlot || target.slots[0].value) ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}
                </select>` : ''}
                ${target.askScope ? `
                <label class="bke-form-label">표시 범위</label>
                <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:12px;font-size:12.5px;">
                    <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                        <input type="radio" class="form-check-input" name="bkeArScope" value="menu" checked>
                        이 페이지에만
                    </label>
                    <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                        <input type="radio" class="form-check-input" name="bkeArScope" value="global">
                        모든 페이지에 <span style="color:var(--bke-global);font-size:11px;">(전역 — 다른 페이지에도 함께 나타납니다)</span>
                    </label>
                </div>` : ''}
                ${target.askMainScope ? `
                <div id="bkeArMainScopeWrap" style="margin-bottom:12px;">
                    <label class="bke-form-label">표시 범위</label>
                    <div style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;">
                        <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                            <input type="radio" class="form-check-input" name="bkeArMainScope" value="global" checked>
                            모든 페이지에 <span style="color:var(--bke-global);font-size:11px;">(전역 — 다른 페이지에도 함께 나타납니다)</span>
                        </label>
                        <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                            <input type="radio" class="form-check-input" name="bkeArMainScope" value="index">
                            이 메인화면에만
                        </label>
                    </div>
                </div>` : ''}
                <label class="bke-form-label">칸 수</label>
                <div class="bke-addrow-cards" id="bkeArCols">
                    ${[1, 2, 3, 4].map(n => `<button type="button" data-n="${n}" class="${n === 1 ? 'sel' : ''}">
                        <span class="cols">${'<span></span>'.repeat(n)}</span>${n}칸</button>`).join('')}
                </div>
                <label class="bke-form-label">넓이</label>
                <select class="form-select form-select-sm" id="bkeArWidth" style="margin-bottom:14px;">
                    <option value="1" selected>최대넓이</option>
                    <option value="0">와이드 (전체)</option>
                </select>
                <button type="button" class="bke-btn bke-btn--primary" style="width:100%;" id="bkeArCreate">만들기</button>
                <div class="bke-note" style="margin-top:10px;">만든 뒤 빈 칸을 클릭해 콘텐츠를 고르세요.</div>
            </div>`;
        document.body.appendChild(overlay);

        let colCount = 1;
        overlay.querySelector('.bke-typepicker__backdrop').addEventListener('click', () => overlay.remove());
        overlay.querySelectorAll('#bkeArCols button').forEach(btn => btn.addEventListener('click', () => {
            overlay.querySelectorAll('#bkeArCols button').forEach(b => b.classList.remove('sel'));
            btn.classList.add('sel');
            colCount = parseInt(btn.dataset.n, 10);
        }));
        // 메인 스코프는 'index'(메인 본문) 슬롯엔 무의미 — 슬롯에 따라 표시/숨김
        const mainScopeWrap = overlay.querySelector('#bkeArMainScopeWrap');
        if (mainScopeWrap) {
            const slotSel = overlay.querySelector('#bkeArSlot');
            const toggleMainScope = () => {
                const slot = slotSel ? slotSel.value : (target.defaultSlot || '');
                mainScopeWrap.style.display = (slot === 'index') ? 'none' : '';
            };
            if (slotSel) slotSel.addEventListener('change', toggleMainScope);
            toggleMainScope();
        }
        overlay.querySelector('#bkeArCreate').addEventListener('click', async () => {
            const finalTarget = { ...target };
            if (askSlot) {
                finalTarget.position = overlay.querySelector('#bkeArSlot').value;
            }
            if (target.askScope) {
                const scope = overlay.querySelector('input[name="bkeArScope"]:checked').value;
                if (scope === 'global') finalTarget.position_menu = '';
            }
            // 메인 컨텍스트: 'index' 외 위치를 "이 메인화면에만" 으로 두면 position_menu='__index__'
            if (target.askMainScope) {
                const mScope = overlay.querySelector('input[name="bkeArMainScope"]:checked')?.value;
                if (mScope === 'index' && finalTarget.position && finalTarget.position !== 'index') {
                    finalTarget.position_menu = '__index__';
                }
            }
            // 컨테이너 내부 위치는 와이드 불가 — 행 폼과 같은 규칙으로 강제한다.
            let widthType = overlay.querySelector('#bkeArWidth')?.value ?? '1';
            if (finalTarget.position && !WIDE_POSITIONS.includes(finalTarget.position)) {
                widthType = '1';
            }
            overlay.remove();
            await createNewRow(finalTarget, colCount, widthType, afterMeta);
        });
    }

    /** 행 추가 ①의 서버 처리 — 기존 저장 API 에 기본값을 실어 즉시 생성한다 */
    async function createNewRow(target, colCount, widthType, afterMeta) {
        const f = {
            row_id: 0,
            admin_title: '새 행',
            section_id: 'section-' + Math.random().toString(16).slice(2, 10),
            is_active: 1,
            page_id: target.page_id || 0,
            position: target.position || '',
            position_menu: target.position_menu || '',
            width_type: widthType,
            column_count: colCount,
            column_margin: 0,
            pc_margin: '', mobile_margin: '', pc_padding: '', mobile_padding: '',
            bg_color: '', bg_image_old: '',
            bg_size: 'cover', bg_position: 'center center', bg_repeat: 'no-repeat', bg_attachment: 'scroll',
        };
        const columns = Array.from({ length: colCount }, () => emptyColumnPayload());

        const fd = new FormData();
        fd.append('_token', CSRF);
        Object.entries(f).forEach(([k, v]) => fd.append(`formData[${k}]`, v ?? ''));
        columns.forEach((c, i) => appendColumnFields(fd, c, i));

        let newId = 0;
        try {
            const res = await fetch('/admin/block-row/store', { method: 'POST', body: fd });
            const json = await res.json();
            if (!(json.success || json.result === 'success')) {
                MubloRequest.showAlert(json.message || '행 생성에 실패했습니다.', 'error');
                return;
            }
            newId = parseInt(json.data?.row_id, 10) || 0;
            MubloRequest.showToast('행이 추가되었습니다. 빈 칸을 클릭해 콘텐츠를 고르세요.', 'success');
        } catch (e) { MubloRequest.showAlert('요청에 실패했습니다.', 'error'); return; }

        // "아래에 추가"면 새 행(맨 뒤 생성됨)을 기준 행 다음으로 옮긴다.
        if (afterMeta && newId) {
            await loadRowsMeta(state.context.id);
            const scope = state.rowsMeta.get(newId)?.scope_label;
            const orders = scopeOrderMapping(scope, ids => {
                const from = ids.indexOf(newId);
                if (from < 0) return false;
                ids.splice(from, 1);
                ids.splice(ids.indexOf(afterMeta.row_id) + 1, 0, newId);
                return true;
            });
            if (orders) {
                try { await MubloRequest.requestJson('/admin/block-row/order-set', { orders }); } catch (e) { /* 순서만 실패 — 행은 생성됨 */ }
            }
        }

        refreshPreviewAfterSave(newId ? { rowId: newId } : null);
    }

    /**
     * 같은 스코프(scope_label)의 행들을 정렬 순서대로 모아 재배열하고,
     * rowId → 새 sort_order 매핑을 만든다. arrange 가 false 를 돌려주면 중단.
     */
    function scopeOrderMapping(scopeLabel, arrange) {
        const rows = Array.from(state.rowsMeta.values())
            .filter(r => r.scope_label === scopeLabel)
            .sort((a, b) => (a.sort_order - b.sort_order) || (a.row_id - b.row_id));
        const ids = rows.map(r => r.row_id);
        if (arrange(ids) === false) return null;
        const orders = {};
        ids.forEach((id, i) => { orders[id] = i + 1; });
        return orders;
    }

    async function moveRow(meta, dir) {
        const orders = scopeOrderMapping(meta.scope_label, ids => {
            const i = ids.indexOf(meta.row_id);
            const j = i + dir;
            if (i < 0 || j < 0 || j >= ids.length) return false;
            [ids[i], ids[j]] = [ids[j], ids[i]];
        });
        if (!orders) return;

        try {
            await MubloRequest.requestJson('/admin/block-row/order-set', { orders });
        } catch (e) { return; }
        refreshPreviewAfterSave({ rowId: meta.row_id });
    }

    function deleteRowAction(meta) {
        const warn = meta.is_global ? ' ⚠ 이 행은 모든 페이지 공통입니다.' : '';
        MubloRequest.showConfirm(
            `'${meta.admin_title}' 행을 삭제합니다. 삭제한 행은 블록 행 관리의 삭제 이력에서 복구할 수 있습니다.${warn}`,
            async () => {
                const fd = new FormData();
                fd.append('_token', CSRF);
                fd.append('chk[]', meta.row_id);

                try {
                    const res = await fetch('/admin/block-row/list-delete', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (!(json.success || json.result === 'success')) {
                        MubloRequest.showAlert(json.message || '삭제에 실패했습니다.', 'error');
                        return;
                    }
                    MubloRequest.showToast('행이 삭제되었습니다.', 'success');
                } catch (e) { MubloRequest.showAlert('요청에 실패했습니다.', 'error'); return; }

                state.selected = null;
                refreshPreviewAfterSave(null);
            },
            { type: 'warning', confirmText: '삭제' }
        );
    }

    // hover 필 클릭 → 그 행 아래에 추가 (행의 소속을 상속)
    els.addPill.addEventListener('click', (e) => {
        e.stopPropagation();
        const afterMeta = state.rowsMeta.get(parseInt(els.addPill.dataset.afterRow, 10));
        if (afterMeta) openAddRowDialog(null, afterMeta);
    });

    /* ---------------- 칸 인스펙터 — 네이티브 편집 (설계 6.1/6.4/6.6) ---------------- */

    const itemsCache = new Map(); // content_type → [{id, label}]

    async function fetchItems(type) {
        if (itemsCache.has(type)) return itemsCache.get(type);
        try {
            const res = await fetch('/admin/block-row/get-content-items?content_type=' + encodeURIComponent(type));
            const json = await res.json();
            const items = (json.success && json.data && Array.isArray(json.data.items)) ? json.data.items : [];
            itemsCache.set(type, items);
            return items;
        } catch (e) { return []; }
    }

    function typeInfo(type) {
        return CONTENT_TYPES.find(t => t.value === type) || null;
    }

    // 타입별 대표 아이콘 — 데이터가 없으니 휴리스틱. 모르면 기본 아이콘.
    function typeIcon(type) {
        const map = {
            html: 'bi-code-slash', image: 'bi-image', movie: 'bi-play-btn',
            outlogin: 'bi-person-check', menu: 'bi-list-ul', banner: 'bi-images',
            faq: 'bi-question-circle', board: 'bi-card-text', comment: 'bi-chat-dots',
            boardgroup: 'bi-collection', product: 'bi-bag', product_auto: 'bi-bag-check',
            review: 'bi-star', visitor: 'bi-graph-up', visitor_trend: 'bi-activity',
        };
        return map[type] || 'bi-box';
    }

    // 콘텐츠가 본체인 타입 — 클릭하면 콘텐츠 모달로 직행한다 (설계 6.1 라우팅)
    const CONTENT_MODAL_TYPES = ['html', 'image', 'movie'];

    /**
     * 칸 데이터를 FormData 로 펼친다 — 스택 칸의 contents 배열은
     * columns[i][contents][j][field] 중첩 형식으로 (계획 6.3 payload).
     * 단순 Object.entries 펼침은 배열을 "[object Object]" 로 뭉개 스택
     * 데이터가 유실된다.
     */
    function appendColumnFields(fd, c, i) {
        Object.entries(c).forEach(([k, v]) => {
            if (k === 'contents' && Array.isArray(v)) {
                v.forEach((ct, j) => Object.entries(ct || {}).forEach(([ck, cv]) =>
                    fd.append(`columns[${i}][contents][${j}][${ck}]`, cv ?? '')));
                return;
            }
            fd.append(`columns[${i}][${k}]`, v ?? '');
        });
    }

    /** 저장 시 칸이 재생성돼 column_id 가 바뀌므로, 인덱스는 DOM 순서로 계산한다. */
    function domColumnIndex(target) {
        const cols = state.targets.filter(x => x.kind === 'column' && !x.contentId && x.rowId === target.rowId);
        const i = cols.findIndex(x => x.columnId === target.columnId);
        return i < 0 ? 0 : i;
    }

    async function renderColumnEditor(target, meta, backHtml) {
        setPanelTitle('bi-pencil-square', '칸 편집');
        let data;
        try {
            const res = await fetch('/admin/block-editor/row-data?id=' + target.rowId);
            const json = await res.json();
            if (!json.success) throw new Error(json.message);
            data = json.data;
        } catch (e) {
            els.inspector.innerHTML = backHtml + '<div class="bke-alert-global">칸 데이터를 불러오지 못했습니다.</div>';
            document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
            return;
        }

        const colIndex = domColumnIndex(target);
        const col = data.columns[colIndex];
        if (!col) {
            els.inspector.innerHTML = backHtml + '<div class="bke-alert-global">칸을 찾을 수 없습니다.</div>';
            document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
            return;
        }

        const parse = (s, fb) => {
            try {
                const v = JSON.parse(s);
                return v && typeof v === 'object' && !Array.isArray(v) ? v : fb;
            } catch (e) { return fb; }
        };
        const parseItems = (s) => {
            try { const v = JSON.parse(s); return Array.isArray(v) ? v : []; } catch (e) { return []; }
        };

        // 콘텐츠 스택 상태 (계획 8.3) — 인스펙터에서 직접 콘텐츠 편집.
        // stackContents 는 draft(적용 시 저장), stackIndex 는 현재 편집 중인
        // 콘텐츠(스택 진입 시 기본 0 / 단일 칸이면 칸 자체 편집)
        let isStackCol = (col.content_mode || 'single') === 'stack';
        let stackContents = isStackCol && Array.isArray(col.contents)
            ? col.contents.map(x => ({ ...x }))
            : null;
        let stackIndex = null;
        if (isStackCol && target.contentId && stackContents) {
            const found = stackContents.findIndex(x => Number(x.content_id) === Number(target.contentId));
            stackIndex = found >= 0 ? found : null;
        }

        /** 현재 편집 대상(콘텐츠 or 칸)의 원본 필드 */
        const contentSource = () => (stackIndex !== null && stackContents ? stackContents[stackIndex] : col);

        // 칸 편집 상태 — 인스펙터의 진실. 적용 시 columns[colIndex](또는
        // contents[stackIndex]) 에 쓴다. 스타일(bg/border/width)은 칸 소유.
        const cs = {
            type: '', kind: 'CORE', skin: '', items: [],
            itemsTouched: false, typeChanged: false,
            title: {}, config: {},
            bg: parse(col.background_config, {}),
            border: parse(col.border_config, {}),
        };

        const loadContentState = () => {
            const src = contentSource();
            cs.type = src.content_type || '';
            cs.kind = src.content_kind || 'CORE';
            cs.skin = src.content_skin || '';
            cs.items = parseItems(src.content_items);
            cs.itemsTouched = false;
            cs.typeChanged = false;
            cs.title = parse(src.title_config, {});
            cs.config = parse(src.content_config, {});
        };
        loadContentState();

        // 전용 선택 UI(업로드·커스텀 셀렉터) 타입은 네이티브 피커로 다루지 않는다.
        const isComplexItems = () => COMPLEX_ITEM_TYPES.includes(cs.type)
            || cs.items.some(v => v !== null && typeof v === 'object');

        const paint = () => {
            const info = typeInfo(cs.type);
            const hasItems = !!(info && info.hasItems);
            const skins = SKIN_LISTS[cs.type] || [];
            const t = cs.title || {};

            const posLabel = stackIndex !== null
                ? `› ${colIndex + 1}번째 칸 · ${stackIndex + 1}번째 콘텐츠`
                : `› ${colIndex + 1}번째 칸`;
            let html = backHtml + `<div class="bke-detail">
                <h6>${esc(meta.admin_title)} <span style="color:var(--bs-secondary-color);font-weight:400;">${posLabel}</span></h6>
                <div class="bke-detail__scope">${esc(meta.scope_label)}</div>
                ${meta.is_global ? `<div class="bke-alert-global"><i class="bi bi-exclamation-triangle"></i>
                    이 행은 <strong>모든 페이지 공통</strong>입니다. 저장하면 다른 페이지에도 함께 반영됩니다.</div>` : ''}

                ${isStackCol && stackContents ? `<div class="bke-form-row">
                    <label class="bke-form-label">이 칸의 콘텐츠 (클릭해서 전환 · 드래그로 정렬)</label>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:stretch;">
                        <div id="cfStkStrip" style="display:flex;gap:4px;flex-wrap:wrap;">${stackContents.map((ct, j) => {
                            const stripInfo = typeInfo(ct.content_type);
                            const stripLabel = ct.content_type ? (stripInfo?.title || ct.content_type) : '미설정';
                            const stripOff = String(ct.is_active) === '0';
                            return `<div class="bke-stk-card ${j === stackIndex ? 'is-active' : ''} ${stripOff ? 'is-off' : ''}" data-cfstk="${j}"${stripOff ? ' title="비활성 콘텐츠"' : ''}>
                                ${stripOff ? '<i class="bi bi-eye-slash"></i> ' : ''}<span>${esc(stripLabel)}</span>
                                <button type="button" class="bke-stk-card__x" data-cfstk-del="${j}" title="이 콘텐츠 삭제"><i class="bi bi-x-lg"></i></button>
                            </div>`;
                        }).join('')}</div>
                        <button type="button" class="bke-btn" id="cfStkAdd" style="flex:0 0 auto;" title="콘텐츠 추가"><i class="bi bi-plus-lg"></i> 추가</button>
                    </div>
                    <div class="d-flex align-items-center gap-1" style="margin-top:6px;font-size:12px;color:var(--bs-secondary-color);" title="콘텐츠 사이 간격 (px)">
                        <i class="bi bi-distribute-vertical"></i><span>간격</span>
                        PC <input type="number" class="form-control form-control-sm" style="width:56px" id="cf_stack_gap_pc" min="0" max="200" value="${parseInt(col.pc_content_gap, 10) || 0}">
                        MO <input type="number" class="form-control form-control-sm" style="width:56px" id="cf_stack_gap_mo" min="0" max="200" value="${parseInt(col.mobile_content_gap, 10) || 0}">
                    </div>
                </div>` : ''}

                <label class="bke-form-label">무엇을 보여줄까요</label>
                <div class="bke-type-card">
                    <i class="bi ${cs.type ? typeIcon(cs.type) : 'bi-plus-square-dotted'}" style="font-size:16px;color:var(--bke-accent);"></i>
                    <span class="bke-type-card__label">${cs.type ? esc(info ? info.label : cs.type) : '빈 칸'}
                        ${cs.type ? `<span class="bke-kind-chip">${esc(KIND_LABELS[cs.kind] || cs.kind)}</span>` : ''}
                        ${cs.type && !info ? '<span class="bke-kind-chip" style="color:var(--bke-global);">설치 필요</span>' : ''}
                    </span>
                    <button type="button" class="bke-btn" style="flex:0 0 auto;padding:5px 10px;" id="cfPickType">${cs.type ? '바꾸기' : '고르기'}</button>
                </div>`;

            if (CONTENT_MODAL_TYPES.includes(cs.type)) {
                html += `<button type="button" class="bke-btn bke-btn--primary" id="cfEditContent" style="width:100%;margin-bottom:10px;">
                    <i class="bi bi-pencil-square"></i> 내용 편집
                </button>`;
            }

            if (hasItems && !CONTENT_MODAL_TYPES.includes(cs.type)) {
                if (isComplexItems()) {
                    // 전용 셀렉터 타입(쇼핑몰 상품 등) — 일반 체크 피커로는 못 고른다.
                    // 눈에 띄는 기본 버튼으로 전용 설정 모달(임베드 칸 모달)을 연다.
                    html += `<div class="bke-items-box">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="flex:1;">표시할 내용 <strong>${cs.items.length}</strong>개 선택됨</span>
                        </div>
                        <button type="button" class="bke-btn bke-btn--primary" id="cfAdvanced2" style="width:100%;margin-top:8px;">
                            <i class="bi bi-ui-checks"></i> 내용 고르기 (전용 선택 화면)
                        </button>
                    </div>`;
                } else {
                    html += `<div class="bke-items-box">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="flex:1;">표시할 내용 <strong>${cs.items.length}</strong>개 선택됨</span>
                            <button type="button" class="bke-btn" style="flex:0 0 auto;padding:5px 10px;" id="cfPickItems">고르기</button>
                        </div>
                        <div class="bke-items-box__selected" id="cfItemsLabels"></div>
                    </div>`;
                }
            }

            if (cs.type && skins.length) {
                html += `<div class="bke-form-row">
                    <label class="bke-form-label">디자인 (스킨)</label>
                    <select class="form-select form-select-sm" id="cf_skin">
                        ${skins.map(s => `<option value="${esc(s.value)}" ${cs.skin === s.value ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}
                    </select>
                    <div id="cfSkinHint" style="font-size:11.5px;color:var(--bs-secondary-color);margin-top:4px;"></div>
                </div>`;
            }

            // 출력 설정 — 행 폼과 동일한 설정을 에디터 안에서 (고급 설정 없이 해결).
            // 출력갯수는 hasItems, 출력 스타일은 hasStyle 타입에만 — FAQ 처럼
            // 갯수만 쓰는 타입이 있다. 콘텐츠 모달 타입(이미지 등)은 모달 쪽에서.
            if (cs.type && (info?.hasStyle || hasItems) && !CONTENT_MODAL_TYPES.includes(cs.type)) {
                const cc = cs.config;
                html += `<details class="bke-acc">
                    <summary><i class="bi bi-grid-3x3-gap"></i> 출력 설정 <i class="bi bi-chevron-down"></i></summary>
                    <div class="bke-acc__body" id="cfOutputStyle">
                        ${hasItems ? `<div class="bke-form-grid">
                            <div class="bke-form-row">
                                <label class="bke-form-label">PC 출력갯수</label>
                                <input type="number" class="form-control form-control-sm" id="cf_pc_count" min="1" max="100" value="${parseInt(cc.pc_count) || 4}">
                            </div>
                            <div class="bke-form-row">
                                <label class="bke-form-label">MO 출력갯수</label>
                                <input type="number" class="form-control form-control-sm" id="cf_mo_count" min="1" max="100" value="${parseInt(cc.mo_count) || 4}">
                            </div>
                        </div>` : ''}
                        ${info?.hasStyle ? outputStyleHtml(cc) : ''}
                    </div>
                </details>`;
            }

            html += `
                <details class="bke-acc">
                    <summary><i class="bi bi-type"></i> 제목 <i class="bi bi-chevron-down"></i></summary>
                    <div class="bke-acc__body">
                        <div class="bke-form-row bke-switch">
                            <input type="checkbox" class="form-check-input" id="cf_title_show" ${t.show ? 'checked' : ''}>
                            <label for="cf_title_show" style="cursor:pointer;">칸 제목 표시</label>
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">제목</label>
                            <input type="text" class="form-control form-control-sm" id="cf_title_text" value="${esc(t.text || '')}">
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">보조 문구</label>
                            <input type="text" class="form-control form-control-sm" id="cf_copytext" value="${esc(t.copytext || '')}">
                        </div>
                        <div class="bke-form-grid">
                            <div class="bke-form-row">
                                <label class="bke-form-label">더보기 링크</label>
                                <input type="text" class="form-control form-control-sm" id="cf_more_url" placeholder="/board/notice" value="${esc(t.more_url || '')}">
                            </div>
                            <div class="bke-form-row">
                                <label class="bke-form-label">더보기 문구</label>
                                <input type="text" class="form-control form-control-sm" id="cf_more_text" placeholder="더보기" value="${esc(t.more_text || '')}">
                            </div>
                        </div>
                    </div>
                </details>

                <details class="bke-acc">
                    <summary><i class="bi bi-palette"></i> 스타일 <i class="bi bi-chevron-down"></i></summary>
                    <div class="bke-acc__body">
                        <div class="bke-form-row">
                            <label class="bke-form-label">칸 너비 <span style="font-weight:400;">(예: 50% · 300px, 비우면 균등)</span></label>
                            <input type="text" class="form-control form-control-sm" id="cf_width" value="${esc(col.width)}">
                        </div>
                        <div class="bke-note" style="margin:0 0 8px;">
                            안쪽 여백은 <strong>위 → 오른쪽 → 아래 → 왼쪽</strong> 순서입니다.
                            하나만 쓰면 사방 동일(<code>15px</code>),
                            두 개면 위아래 · 좌우(<code>15px 10px</code>).
                        </div>
                        <div class="bke-form-grid">
                            <div class="bke-form-row">
                                <label class="bke-form-label">PC 안쪽 여백</label>
                                <input type="text" class="form-control form-control-sm" id="cf_pc_padding" placeholder="예: 15px (사방 동일)" value="${esc(col.pc_padding)}">
                            </div>
                            <div class="bke-form-row">
                                <label class="bke-form-label">모바일 안쪽 여백</label>
                                <input type="text" class="form-control form-control-sm" id="cf_mobile_padding" placeholder="예: 10px 5px" value="${esc(col.mobile_padding)}">
                            </div>
                        </div>
                        <div class="bke-form-row">
                            <label class="bke-form-label">배경 색상</label>
                            <input type="text" class="form-control form-control-sm" id="cf_bg_color" placeholder="#ffffff (비우면 기본)" value="${esc(cs.bg.color || '')}">
                        </div>
                        <div class="bke-form-grid">
                            <div class="bke-form-row">
                                <label class="bke-form-label">테두리 (두께)</label>
                                <input type="text" class="form-control form-control-sm" id="cf_border_width" placeholder="1px" value="${esc(cs.border.width || '')}">
                            </div>
                            <div class="bke-form-row">
                                <label class="bke-form-label">라운드</label>
                                <input type="text" class="form-control form-control-sm" id="cf_border_radius" placeholder="8px" value="${esc(cs.border.radius || '')}">
                            </div>
                        </div>
                    </div>
                </details>

                <div class="bke-apply-bar">
                    <button type="button" class="bke-btn bke-btn--primary" id="cfApply" style="flex:1;">적용</button>
                    <button type="button" class="bke-linkbtn" id="cfAdvanced">고급 설정</button>
                </div>
                ${!isStackCol ? `
                <div class="bke-form-row" style="margin-top:8px;">
                    <button type="button" class="bke-btn" id="cfAddStackContent" style="width:100%;">
                        <i class="bi bi-plus-lg"></i> 콘텐츠 추가 — 한 칸에 여러 콘텐츠 배치
                    </button>
                </div>` : ''}
            </div>`;

            els.inspector.innerHTML = html;

            document.getElementById('bkeBack')?.addEventListener('click', () => select(null));
            els.inspector.querySelectorAll('[data-cfstk]').forEach((chip) => {
                chip.addEventListener('click', (e) => {
                    if (e.target.closest('[data-cfstk-del]')) return; // 카드 안 ×는 전환 아님
                    const j = parseInt(chip.dataset.cfstk, 10);
                    if (j === stackIndex) return;
                    harvestContent(); // 적용 전 편집값을 draft 에 보존하고 전환
                    stackIndex = j;
                    loadContentState();
                    paint();
                });
            });
            document.getElementById('cfStkAdd')?.addEventListener('click', () => {
                if (stackContents.length >= 20) { MubloRequest.showAlert('한 칸에는 콘텐츠를 최대 20개까지 배치할 수 있습니다.', 'warning'); return; }
                harvestContent();
                stackContents.push({ content_type: '', content_kind: 'CORE', content_skin: '', title_config: '{}', content_config: '{}', content_items: '[]', is_active: 1 });
                stackIndex = stackContents.length - 1;
                loadContentState();
                paint();
            });
            els.inspector.querySelectorAll('[data-cfstk-del]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const j = parseInt(btn.dataset.cfstkDel, 10);
                    if (stackContents.length <= 1) {
                        MubloRequest.showAlert('마지막 콘텐츠는 삭제할 수 없습니다.', 'warning');
                        return;
                    }
                    const delInfo = typeInfo(stackContents[j].content_type);
                    const delLabel = stackContents[j].content_type ? (delInfo?.title || stackContents[j].content_type) : '미설정';
                    MubloRequest.showConfirm(`콘텐츠(${delLabel})를 삭제하시겠습니까? [적용]을 눌러야 저장됩니다.`, () => {
                        harvestContent(); // 다른 콘텐츠 삭제 시 현재 편집값 보존
                        stackContents.splice(j, 1);
                        if (j < stackIndex) stackIndex -= 1;
                        if (stackIndex >= stackContents.length) stackIndex = stackContents.length - 1;
                        loadContentState();
                        paint();
                    }, { type: 'warning', confirmText: '삭제' });
                });
            });
            // 스트립 드래그 정렬 — Sortable 은 관리자 프레임에 이미 로드됨.
            // ×(삭제) 버튼에서 시작한 드래그는 잡지 않는다.
            if (isStackCol && typeof Sortable !== 'undefined') {
                const strip = document.getElementById('cfStkStrip');
                if (strip && strip.children.length > 1) {
                    new Sortable(strip, {
                        animation: 150,
                        draggable: '.bke-stk-card',
                        filter: '.bke-stk-card__x',
                        preventOnFilter: false,
                        onEnd: (evt) => {
                            if (evt.oldIndex === evt.newIndex) return;
                            harvestContent();
                            const [moved] = stackContents.splice(evt.oldIndex, 1);
                            stackContents.splice(evt.newIndex, 0, moved);
                            // 편집 중이던 콘텐츠를 따라간다
                            if (stackIndex === evt.oldIndex) stackIndex = evt.newIndex;
                            else if (evt.oldIndex < stackIndex && evt.newIndex >= stackIndex) stackIndex -= 1;
                            else if (evt.oldIndex > stackIndex && evt.newIndex <= stackIndex) stackIndex += 1;
                            loadContentState();
                            paint();
                        },
                    });
                }
            }
            document.getElementById('cfPickType')?.addEventListener('click', () => openTypePicker(cs, paint));
            document.getElementById('cfEditContent')?.addEventListener('click', () => openContentModal(target, meta, cs.type, { stackIndex }));
            document.getElementById('cfAddStackContent')?.addEventListener('click', () => {
                // 단일 → 스택 전환 (draft): 기존 콘텐츠는 첫 항목으로, 새 항목 추가 후 바로 편집
                isStackCol = true;
                stackContents = [];
                if (col.content_type) {
                    stackContents.push({
                        content_type: col.content_type || '',
                        content_kind: col.content_kind || 'CORE',
                        content_skin: col.content_skin || '',
                        title_config: col.title_config || '{}',
                        content_config: col.content_config || '{}',
                        content_items: col.content_items || '[]',
                        is_active: 1,
                    });
                }
                stackContents.push({ content_type: '', content_kind: 'CORE', content_skin: '', title_config: '{}', content_config: '{}', content_items: '[]', is_active: 1 });
                stackIndex = stackContents.length - 1;
                loadContentState();
                paint();
            });
            document.getElementById('cfAdvanced')?.addEventListener('click', () => openRowEditor(target.rowId, colIndex));
            document.getElementById('cfAdvanced2')?.addEventListener('click', () => openRowEditor(target.rowId, colIndex));
            document.getElementById('cfPickItems')?.addEventListener('click', () => openItemPickerModal(cs, paint, () => openRowEditor(target.rowId, colIndex)));
            document.getElementById('cfApply')?.addEventListener('click', () => applyColumnForm(target, data, colIndex, cs, stackState()));

            const osBox = document.getElementById('cfOutputStyle');
            if (osBox) bindOutputStyle(osBox, cs.config);

            // 스킨 권장 1줄 출력갯수 — 힌트+[적용] 버튼 표시, 스킨 '변경' 시 자동 반영
            // (저장된 블록을 다시 열 때는 저장값 유지. 스킨이 하나뿐이면 change 가
            //  발생하지 않으므로 [적용] 버튼이 수동 반영 통로다)
            const skinSel = document.getElementById('cf_skin');
            if (skinSel) {
                const currentRec = () => skins.find(s => s.value === skinSel.value)?.recommended_cols || null;
                const applyRec = () => {
                    const rec = currentRec();
                    if (!rec) return;
                    ['pc', 'mo'].forEach(dev => {
                        if (!rec[dev]) return;
                        cs.config[`${dev}_cols`] = String(rec[dev]);
                        const colSel = osBox?.querySelector(`[data-oscfg="${dev}_cols"]`);
                        if (colSel) colSel.value = String(rec[dev]);
                    });
                };
                const renderHint = () => {
                    const hint = document.getElementById('cfSkinHint');
                    if (!hint) return;
                    const rec = currentRec();
                    const parts = [];
                    if (rec?.pc) parts.push(`PC ${rec.pc}개`);
                    if (rec?.mo) parts.push(`모바일 ${rec.mo}개`);
                    hint.innerHTML = parts.length
                        ? `권장 1줄 출력갯수: ${parts.join(' · ')} <button type="button" class="bke-btn" id="cfSkinApply" style="padding:1px 8px;font-size:11px;margin-left:4px;">적용</button>`
                        : '';
                    document.getElementById('cfSkinApply')?.addEventListener('click', applyRec);
                };
                skinSel.addEventListener('change', () => { applyRec(); renderHint(); });
                renderHint();
            }

            renderSelectedItemLabels(cs);
        };

        /** applyColumnForm 에 넘길 스택 draft 상태 */
        const stackState = () => ({ isStackCol, stackContents, stackIndex });

        /**
         * 콘텐츠 전환·목록 이동 전에 현재 편집 화면의 값을 draft 에 보존한다
         * (적용 전 값 유실 방지 — 입력 요소가 없으면 cs 상태만 반영).
         */
        const harvestContent = () => {
            if (!isStackCol || stackIndex === null || !stackContents || !stackContents[stackIndex]) return;

            const config = { ...cs.config };
            const pcCount = document.getElementById('cf_pc_count');
            const moCount = document.getElementById('cf_mo_count');
            if (pcCount) config.pc_count = parseInt(pcCount.value) || 4;
            if (moCount) config.mo_count = parseInt(moCount.value) || 4;

            const title = { ...cs.title };
            const titleShow = document.getElementById('cf_title_show');
            if (titleShow) {
                title.show = titleShow.checked;
                title.text = document.getElementById('cf_title_text').value.trim();
                title.copytext = document.getElementById('cf_copytext').value.trim();
                title.more_url = document.getElementById('cf_more_url').value.trim();
                title.more_text = document.getElementById('cf_more_text').value.trim();
                title.more_link = !!title.more_url;
            }

            stackContents[stackIndex] = {
                ...stackContents[stackIndex],
                content_type: cs.type,
                content_kind: cs.kind,
                content_skin: document.getElementById('cf_skin')?.value ?? cs.skin,
                content_items: JSON.stringify(cs.items),
                content_config: JSON.stringify(config),
                title_config: JSON.stringify(title),
            };
        };

        // 스택 칸 진입 시 첫 콘텐츠를 바로 연다 — 별도 목록 화면 없이
        // 스트립(전환·드래그 정렬·×삭제)과 스타일 아코디언(간격)으로 해결.
        // 복제·활성/비활성 등 세부 조작은 행 편집 폼(고급 설정)에.
        if (isStackCol && stackIndex === null) {
            stackIndex = 0;
            loadContentState();
        }
        paint();
    }

    async function renderSelectedItemLabels(cs) {
        const box = document.getElementById('cfItemsLabels');
        if (!box || !cs.items.length) return;
        const all = await fetchItems(cs.type);
        const labels = cs.items.map(id => {
            const found = all.find(it => String(it.id) === String(id));
            return found ? found.label : id;
        });
        box.textContent = labels.slice(0, 3).join(', ') + (labels.length > 3 ? ` 외 ${labels.length - 3}개` : '');
    }

    /**
     * 아이템 선택 모달 — 게시판·상품·메뉴 등 모든 목록형 타입 공용.
     * "등록"은 각 관리 화면 소관이다 — 여기서는 고르기만 하고, 비어 있으면 안내한다.
     */
    async function openItemPickerModal(cs, repaint, openAdvanced) {
        const info = typeInfo(cs.type);
        const overlay = document.createElement('div');
        overlay.className = 'bke-typepicker';
        overlay.innerHTML = `<div class="bke-typepicker__backdrop"></div>
            <div class="bke-typepicker__panel" style="width:520px;">
                <div class="bke-typepicker__title">표시할 내용 고르기
                    <span style="font-weight:400;color:var(--bs-secondary-color);font-size:12px;">— ${esc(info?.label || cs.type)}</span>
                </div>
                <div id="bkeIpBody"><div class="text-muted" style="font-size:12px;">목록을 불러오는 중…</div></div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('.bke-typepicker__backdrop').addEventListener('click', () => { overlay.remove(); repaint(); });

        const items = await fetchItems(cs.type);
        const body = overlay.querySelector('#bkeIpBody');

        if (!items.length) {
            // 목록이 비는 두 경우: 정말 등록된 항목이 없거나, 이 타입이 일반
            // 피커를 지원하지 않는 전용 셀렉터 타입(쇼핑몰 상품 등)이거나.
            // 후자를 위해 전용 선택 화면으로 가는 길을 항상 함께 연다.
            body.innerHTML = `<div class="bke-inspector-empty" style="padding:24px 10px;">
                <i class="bi bi-inbox"></i>여기서 고를 수 있는 항목이 없습니다.<br>
                <span style="font-size:11.5px;">이 타입은 전용 선택 화면을 쓰거나, 해당 관리 메뉴(게시판·상품 등)에서 먼저 등록해야 합니다.</span></div>
                ${openAdvanced ? '<button type="button" class="bke-btn bke-btn--primary" style="width:100%;margin-bottom:6px;" id="bkeIpAdvanced"><i class="bi bi-ui-checks"></i> 전용 선택 화면 열기</button>' : ''}
                <button type="button" class="bke-btn" style="width:100%;" id="bkeIpClose">닫기</button>`;
            body.querySelector('#bkeIpAdvanced')?.addEventListener('click', () => { overlay.remove(); openAdvanced(); });
            body.querySelector('#bkeIpClose').addEventListener('click', () => { overlay.remove(); repaint(); });
            return;
        }

        body.innerHTML = `
            <input type="text" class="form-control form-control-sm" id="bkeIpSearch" placeholder="검색…" style="margin-bottom:6px;">
            <div class="bke-itempanel__list" id="bkeIpList" style="max-height:340px;border:1px solid var(--bs-border-color);border-radius:8px;padding:4px 8px;"></div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:10px;">
                <span style="flex:1;font-size:12px;color:var(--bs-secondary-color);"><strong id="bkeIpCount">${cs.items.length}</strong>개 선택됨</span>
                <button type="button" class="bke-btn bke-btn--primary" style="min-width:110px;" id="bkeIpDone">선택 완료</button>
            </div>
            <div class="bke-note" style="margin-top:8px;">항목이 더 필요하면 해당 관리 메뉴에서 등록한 뒤 다시 여세요.</div>`;

        const list = body.querySelector('#bkeIpList');
        const countEl = body.querySelector('#bkeIpCount');
        const drawList = (keyword) => {
            const kw = (keyword || '').toLowerCase();
            list.innerHTML = items
                .filter(it => !kw || String(it.label).toLowerCase().includes(kw))
                .map(it => `<label><input type="checkbox" class="form-check-input" value="${esc(it.id)}"
                    ${cs.items.some(v => String(v) === String(it.id)) ? 'checked' : ''}> ${esc(it.label)}</label>`)
                .join('') || '<div class="text-muted" style="font-size:12px;padding:8px 2px;">검색 결과가 없습니다.</div>';
            list.querySelectorAll('input').forEach(cb => cb.addEventListener('change', () => {
                cs.itemsTouched = true;
                if (cb.checked) {
                    if (!cs.items.some(v => String(v) === String(cb.value))) cs.items.push(cb.value);
                } else {
                    cs.items = cs.items.filter(v => String(v) !== String(cb.value));
                }
                countEl.textContent = cs.items.length;
            }));
        };
        drawList('');
        body.querySelector('#bkeIpSearch').addEventListener('input', e => drawList(e.target.value));
        body.querySelector('#bkeIpDone').addEventListener('click', () => { overlay.remove(); repaint(); });
    }

    function openTypePicker(cs, done) {
        const overlay = document.createElement('div');
        overlay.className = 'bke-typepicker';

        const groups = { CORE: [], PLUGIN: [], PACKAGE: [] };
        CONTENT_TYPES.forEach(t => (groups[t.kind] || (groups[t.kind] = [])).push(t));

        let inner = '<div class="bke-typepicker__backdrop"></div><div class="bke-typepicker__panel">';
        inner += '<div class="bke-typepicker__title">무엇을 보여줄까요?</div>';
        Object.entries(groups).forEach(([kind, list]) => {
            if (!list.length) return;
            inner += `<div class="bke-typepicker__group">${esc(KIND_LABELS[kind] || kind)}</div>`;
            inner += '<div class="bke-typepicker__grid">';
            list.forEach(t => {
                inner += `<button type="button" class="bke-typecard ${cs.type === t.value ? 'current' : ''}"
                    data-type="${esc(t.value)}" data-kind="${esc(t.kind)}">
                    <i class="bi ${typeIcon(t.value)}"></i>${esc(t.label)}
                </button>`;
            });
            inner += '</div>';
        });
        inner += '</div>';
        overlay.innerHTML = inner;
        document.body.appendChild(overlay);

        overlay.querySelector('.bke-typepicker__backdrop').addEventListener('click', () => overlay.remove());
        overlay.querySelectorAll('.bke-typecard').forEach(card => {
            card.addEventListener('click', () => {
                const newType = card.dataset.type;
                if (newType !== cs.type) {
                    cs.type = newType;
                    cs.kind = card.dataset.kind;
                    cs.skin = '';        // 스킨은 타입에 종속된다
                    cs.items = [];       // 아이템도 타입에 종속된다
                    cs.itemsTouched = false;
                    cs.typeChanged = true;
                    cs.config = defaultContentConfig(); // 설정도 타입에 종속된다
                }
                overlay.remove();
                done();
            });
        });
    }

    async function applyColumnForm(target, data, colIndex, cs, stack = null) {
        const columns = data.columns.slice();
        const c = { ...columns[colIndex] };
        const isStack = !!(stack && stack.isStackCol && Array.isArray(stack.stackContents));

        // 콘텐츠 필드 수집 (cs 가 있을 때만 — 스택 목록 화면 적용은 구조·간격만)
        let contentFields = null;
        if (cs) {
            const config = { ...cs.config };
            const pcCount = document.getElementById('cf_pc_count');
            const moCount = document.getElementById('cf_mo_count');
            if (pcCount) config.pc_count = parseInt(pcCount.value) || 4;
            if (moCount) config.mo_count = parseInt(moCount.value) || 4;

            const title = { ...cs.title };
            title.show = document.getElementById('cf_title_show').checked;
            title.text = document.getElementById('cf_title_text').value.trim();
            title.copytext = document.getElementById('cf_copytext').value.trim();
            title.more_url = document.getElementById('cf_more_url').value.trim();
            title.more_text = document.getElementById('cf_more_text').value.trim();
            title.more_link = !!title.more_url;

            contentFields = {
                content_type: cs.type,
                content_kind: cs.kind,
                content_skin: document.getElementById('cf_skin')?.value ?? cs.skin,
                content_items: JSON.stringify(cs.items),
                content_config: JSON.stringify(config),
                title_config: JSON.stringify(title),
            };
        }

        if (isStack) {
            // 콘텐츠 편집이면 해당 draft 에 반영 (계획 8.3 — 콘텐츠 단위 편집)
            if (contentFields && stack.stackIndex !== null && stack.stackContents[stack.stackIndex]) {
                stack.stackContents[stack.stackIndex] = {
                    ...stack.stackContents[stack.stackIndex],
                    ...contentFields,
                };
            }
            c.content_mode = 'stack';
            c.contents = stack.stackContents.map(ct => ({ ...ct }));
            // 콘텐츠 간격 — 스타일 섹션 입력이 있으면(콘텐츠 편집 화면) 우선
            const gapPc = document.getElementById('cf_stack_gap_pc');
            const gapMo = document.getElementById('cf_stack_gap_mo');
            c.pc_content_gap = gapPc ? (parseInt(gapPc.value, 10) || 0) : (parseInt(c.pc_content_gap, 10) || 0);
            c.mobile_content_gap = gapMo ? (parseInt(gapMo.value, 10) || 0) : (parseInt(c.mobile_content_gap, 10) || 0);
        } else if (contentFields) {
            Object.assign(c, contentFields);
        }

        // 칸 소유 스타일 필드 — 콘텐츠 편집 화면에서만 입력이 존재한다
        if (cs) {
            c.width = document.getElementById('cf_width').value.trim();
            c.pc_padding = document.getElementById('cf_pc_padding').value.trim();
            c.mobile_padding = document.getElementById('cf_mobile_padding').value.trim();
            c.background_config = JSON.stringify({ ...cs.bg, color: document.getElementById('cf_bg_color').value.trim() });
            c.border_config = JSON.stringify({
                ...cs.border,
                width: document.getElementById('cf_border_width').value.trim(),
                radius: document.getElementById('cf_border_radius').value.trim(),
            });
        }

        columns[colIndex] = c;

        await submitRow(data.form, columns, document.getElementById('cfApply'),
            { rowId: target.rowId, columnIndex: colIndex });
    }

    /* ---------------- 콘텐츠 모달 — HTML·이미지·동영상 내용을 바로 등록 ---------------- */

    let cm = null; // 열려 있는 콘텐츠 모달의 컨텍스트
    let aiAbort = null;
    let aiUndo = null;
    let aiReady = false;
    let aiAssets = [];
    let aiRecords = [];
    let aiSelectedAssets = new Set();

    async function readAiJson(response) {
        const text = await response.text();
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            throw new Error(`AI 서버가 JSON이 아닌 응답을 반환했습니다. (HTTP ${response.status})`);
        }
        try {
            return JSON.parse(text);
        } catch (error) {
            throw new Error('AI 서버 응답 형식을 확인할 수 없습니다.');
        }
    }

    const cmEls = {
        modal: document.getElementById('bkeContentModal'),
        body: document.getElementById('bkecBody'),
        title: document.getElementById('bkecTitle'),
        html: document.getElementById('bkecHtml'),
        image: document.getElementById('bkecImage'),
        video: document.getElementById('bkecVideo'),
        save: document.getElementById('bkecSave'),
    };

    /**
     * @param {string} [draftType] 칸 편집 패널에서 방금 고른(아직 저장 전) 타입.
     *   저장된 타입과 다르면 그 타입의 편집기를 비운 상태로 열고,
     *   저장 시 content_type 도 함께 기록한다 — "타입 선택 → 내용 편집 → 저장"
     *   이 한 번의 저장으로 끝나게.
     */
    async function openContentModal(target, meta, draftType, opts = {}) {
        let data;
        try {
            const res = await fetch('/admin/block-editor/row-data?id=' + target.rowId);
            const json = await res.json();
            if (!json.success) throw new Error(json.message);
            data = json.data;
        } catch (e) { MubloRequest.showAlert('칸 데이터를 불러오지 못했습니다.', 'error'); return; }

        const colIndex = domColumnIndex(target);
        const col = data.columns[colIndex];
        if (!col) { MubloRequest.showAlert('칸을 찾을 수 없습니다.', 'error'); return; }

        // 스택 콘텐츠 내용 편집 (계획 8.3) — contents[stackIndex] 가 소스·저장 대상
        const stackIndex = Number.isInteger(opts.stackIndex) ? opts.stackIndex : null;
        const src = stackIndex !== null && Array.isArray(col.contents) && col.contents[stackIndex]
            ? col.contents[stackIndex]
            : col;

        // 초안 타입이 저장된 타입과 다르면 이전 타입의 설정/아이템을 끌고 오지
        // 않는다 (이미지 편집기가 게시판 ID 배열을 만나는 식의 오염 방지).
        const typeChanged = draftType && draftType !== src.content_type;

        // 배열이면 폴백 — '[]' 로 저장된 옛 config 가 배열로 파싱되면 속성 대입이
        // stringify 에서 유실된다(items 는 별도 처리라 이 헬퍼를 쓰지 않는다).
        const parse = (s, fb) => {
            try {
                const v = JSON.parse(s);
                return v && typeof v === 'object' && !Array.isArray(v) ? v : fb;
            } catch (e) { return fb; }
        };
        const parseList = (s) => {
            try { const v = JSON.parse(s); return Array.isArray(v) ? v : []; } catch (e) { return []; }
        };

        cm = {
            target, data, colIndex, stackIndex,
            type: draftType || src.content_type,
            config: typeChanged ? {} : parse(src.content_config, {}),
            items: typeChanged ? [] : parseList(src.content_items),
        };

        const typeLabels = { html: 'HTML', image: '이미지', movie: '동영상' };
        const cmPos = stackIndex !== null
            ? `› ${colIndex + 1}번째 칸 · ${stackIndex + 1}번째 콘텐츠 · ${typeLabels[cm.type] || ''} 내용`
            : `› ${colIndex + 1}번째 칸 · ${typeLabels[cm.type] || ''} 내용`;
        cmEls.title.innerHTML = '<i class="bi bi-pencil-square"></i>'
            + esc((meta ? meta.admin_title : '행 #' + target.rowId))
            + ` <span style="color:var(--bs-secondary-color);font-weight:400;">${cmPos}</span>`;

        cmEls.html.style.display = cm.type === 'html' ? '' : 'none';
        cmEls.image.style.display = cm.type === 'image' ? '' : 'none';
        cmEls.video.style.display = cm.type === 'movie' ? '' : 'none';
        cmEls.body.classList.toggle('bke-contentmodal__body--fill', cm.type === 'html');

        cmEls.modal.classList.add('open');

        if (cm.type === 'html') {
            aiUndo = null;
            aiSelectedAssets = new Set();
            document.getElementById('bkecAiPrompt').value = '';
            document.getElementById('bkecAiStatus').textContent = typeChanged ? 'HTML 타입을 먼저 저장한 뒤 AI를 사용할 수 있습니다.' : '';
            document.getElementById('bkecAiUndo').style.display = 'none';
            const qualityPanel = document.getElementById('bkecQuality');
            qualityPanel.style.display = 'none';
            qualityPanel.dataset.status = '';
            qualityPanel.innerHTML = '';
            cm.aiUnavailableUntilSaved = !!typeChanged;
            const aiGenerate = document.getElementById('bkecAiGenerate');
            aiGenerate.innerHTML = '<i class="bi bi-stars"></i> 생성';
            aiGenerate.disabled = true;
            loadAiLibrary();
            // 모달이 열려 크기가 잡힌 뒤에 에디터를 붙인다. 인스턴스는 id 에 묶여 재사용된다.
            const ta = document.getElementById('bke_html_content');
            ta.value = cm.config.html || '';

            // CSS/JS — 행 폼과 같은 저장 위치(content_config.css/js)
            const cssTa = document.getElementById('bke_html_css');
            const jsTa = document.getElementById('bke_html_js');
            cssTa.value = cm.config.css || '';
            jsTa.value = cm.config.js || '';
            document.getElementById('bkecCssBadge').textContent = cm.config.css ? '· 사용 중' : '';
            document.getElementById('bkecJsBadge').textContent = cm.config.js ? '· 사용 중' : '';
            document.getElementById('bkecCssAcc').open = !!cm.config.css;
            document.getElementById('bkecJsAcc').open = false;

            setTimeout(() => {
                const editor = BlockHtmlEditor.createVisual('#bke_html_content', { css: cm.config.css || '' });
                editor.setHTML(cm.config.html || '');
                editor.injectCss?.(cm.config.css || '');
                cm.editor = editor;
                // CSS 입력은 에디터 미리보기에 즉시 반영 (행 폼과 동일 동작)
                cssTa.oninput = () => editor.injectCss?.(cssTa.value);
            }, 60);
        } else if (cm.type === 'image') {
            if (!cm.items.length) cm.items = [{ pc_image: '', mo_image: '', link_url: '', link_win: '0' }];
            // 새로 이미지 타입이 된 칸의 기본은 "1줄 1개" — 렌더러 기본(4열)을
            // 그대로 두면 1장만 등록해도 25% 폭으로 나와 어리둥절해진다.
            if (typeChanged) {
                cm.config.pc_cols = cm.config.pc_cols || '1';
                cm.config.mo_cols = cm.config.mo_cols || '1';
            }
            renderImageItemsEditor();
        } else if (cm.type === 'movie') {
            renderVideoEditor();
        }
    }

    function closeContentModal() {
        if (aiAbort) aiAbort.abort();
        aiAbort = null;
        aiUndo = null;
        document.getElementById('bkecAiGenerate').disabled = true;
        cmEls.modal.classList.remove('open');
        cm = null;
    }

    cmEls.modal.querySelectorAll('[data-bkec-close]').forEach(el =>
        el.addEventListener('click', closeContentModal));

    async function loadAiLibrary() {
        try {
            const response = await fetch('/admin/block-editor/ai-assets');
            const json = await readAiJson(response);
            if (!json.success) throw new Error(json.message || '자료실을 불러오지 못했습니다.');
            aiAssets = json.data?.assets || [];
            aiRecords = json.data?.records || [];
            aiReady = !!json.data?.ai_ready;
            updateAiAvailability();
            renderAiLibrary();
        } catch (error) {
            document.getElementById('bkecAiAssets').innerHTML = '<span class="text-danger small">' + esc(error.message) + '</span>';
        }
    }

    function updateAiAvailability() {
        const button = document.getElementById('bkecAiGenerate');
        const settings = document.getElementById('bkecAiSettings');
        const status = document.getElementById('bkecAiStatus');
        const typeBlocked = !!cm?.aiUnavailableUntilSaved;
        button.disabled = !!aiAbort || typeBlocked || !aiReady;
        settings.textContent = aiReady ? 'AI 설정' : 'AI 설정 필요';
        settings.classList.toggle('text-danger', !aiReady);
        if (typeBlocked) status.textContent = 'HTML 타입을 먼저 저장한 뒤 AI를 사용할 수 있습니다.';
        else if (!aiReady) status.textContent = 'AI API 키를 등록하고 기능을 활성화해주세요.';
        else if (status.textContent === 'AI API 키를 등록하고 기능을 활성화해주세요.') status.textContent = '';
    }

    function renderAiLibrary() {
        const list = document.getElementById('bkecAiAssets');
        list.innerHTML = aiAssets.length ? aiAssets.map(asset => `
            <div class="bkec-asset ${aiSelectedAssets.has(asset.id) ? 'selected' : ''}" data-ai-asset="${asset.id}">
                ${asset.kind === 'image'
                    ? `<img src="${asset.preview_url}" alt="">`
                    : `<span class="bkec-asset__icon"><i class="bi bi-file-earmark-text"></i></span>`}
                <button type="button" class="btn btn-link p-0 text-start text-decoration-none overflow-hidden" data-ai-select="${asset.id}">
                    <span class="bkec-asset__name d-block">${esc(asset.title || asset.name)}</span>
                    <small class="text-muted">${esc(String(asset.extension).toUpperCase())} · ${formatAiBytes(asset.size)}</small>
                </button>
                <span class="dropdown">
                    <button type="button" class="btn btn-sm btn-link text-muted" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                    <span class="dropdown-menu dropdown-menu-end">
                        ${asset.kind === 'image' ? `<button class="dropdown-item" type="button" data-ai-edit="rotate_left" data-id="${asset.id}">왼쪽 회전 복사</button><button class="dropdown-item" type="button" data-ai-edit="rotate_right" data-id="${asset.id}">오른쪽 회전 복사</button><button class="dropdown-item" type="button" data-ai-edit="flip_horizontal" data-id="${asset.id}">좌우 반전 복사</button><button class="dropdown-item" type="button" data-ai-edit="crop_square" data-id="${asset.id}">가운데 정사각형 자르기</button><button class="dropdown-item" type="button" data-ai-edit="crop_16_9" data-id="${asset.id}">가운데 16:9 자르기</button><button class="dropdown-item" type="button" data-ai-edit="resize_half" data-id="${asset.id}">50% 크기 복사</button>` : ''}
                        <button class="dropdown-item text-danger" type="button" data-ai-delete="${asset.id}">삭제</button>
                    </span>
                </span>
            </div>`).join('') : '<span class="text-muted small">저장된 자료가 없습니다.</span>';
        document.getElementById('bkecAiHistory').innerHTML = aiRecords.length ? aiRecords.map(record => `
            <div class="d-flex gap-1 align-items-stretch">
                <button type="button" class="bkec-history text-start bg-transparent flex-grow-1 min-w-0" data-ai-history="${record.record_id}">
                    <span class="d-block text-truncate">${esc(record.prompt)}</span>
                    <small class="text-muted">${esc(record.model)} · ${esc(record.created_at || '')}</small>
                </button>
                <button type="button" class="bkec-history bg-transparent px-2 flex-shrink-0" data-ai-result="${record.record_id}"
                        title="이 이력의 생성 결과를 에디터에 불러와 이어서 수정">결과</button>
            </div>`).join('') : '<span class="text-muted small">생성 기록이 없습니다.</span>';
    }

    function formatAiBytes(bytes) {
        const value = Number(bytes || 0);
        return value >= 1048576 ? (value / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(value / 1024)) + 'KB';
    }

    document.getElementById('bkecAiAssets').addEventListener('click', async event => {
        const select = event.target.closest('[data-ai-select]');
        if (select) {
            const id = Number(select.dataset.aiSelect);
            aiSelectedAssets.has(id) ? aiSelectedAssets.delete(id) : aiSelectedAssets.add(id);
            renderAiLibrary(); return;
        }
        const edit = event.target.closest('[data-ai-edit]');
        if (edit) {
            const fd = new FormData(); fd.append('_token', CSRF); fd.append('asset_id', edit.dataset.id); fd.append('operation', edit.dataset.aiEdit);
            try {
                const response = await fetch('/admin/block-editor/ai-asset-edit', {method:'POST', body:fd}); const json = await readAiJson(response);
                if (!json.success) throw new Error(json.message); MubloRequest.showToast(json.message || '편집본이 저장되었습니다.', 'success'); await loadAiLibrary();
            } catch (error) { MubloRequest.showAlert(error.message || '이미지를 편집하지 못했습니다.', 'error'); }
            return;
        }
        const del = event.target.closest('[data-ai-delete]');
        if (del && confirm('이 AI 자료를 삭제할까요?')) {
            const fd = new FormData(); fd.append('_token', CSRF); fd.append('asset_id', del.dataset.aiDelete);
            try {
                const response = await fetch('/admin/block-editor/ai-asset-delete', {method:'POST', body:fd}); const json = await readAiJson(response);
                if (!json.success) throw new Error(json.message); aiSelectedAssets.delete(Number(del.dataset.aiDelete)); await loadAiLibrary();
            } catch (error) { MubloRequest.showAlert(error.message || '자료를 삭제하지 못했습니다.', 'error'); }
        }
    });

    document.getElementById('bkecAiHistory').addEventListener('click', async event => {
        // "결과" 버튼: 이력의 생성 결과물을 에디터로 복원 (P4 — 이 결과에서 이어서)
        const resultButton = event.target.closest('[data-ai-result]');
        if (resultButton) {
            try {
                const response = await fetch('/admin/block-editor/ai-record?id=' + resultButton.dataset.aiResult);
                const json = await readAiJson(response);
                if (!json.success) throw new Error(json.message);
                const rec = json.data || {};
                if (!(rec.result_html || '').trim()) { MubloRequest.showAlert('이 이력에는 저장된 결과물이 없습니다.', 'info'); return; }

                const editor = BlockHtmlEditor.getVisual('bke_html_content');
                aiUndo = {
                    html: editor ? editor.getHTML() : document.getElementById('bke_html_content').value,
                    css: document.getElementById('bke_html_css').value,
                    js: document.getElementById('bke_html_js').value,
                };
                document.getElementById('bke_html_content').value = rec.result_html || '';
                document.getElementById('bke_html_css').value = rec.result_css || '';
                document.getElementById('bke_html_js').value = rec.result_js || '';
                editor?.setHTML?.(rec.result_html || '');
                editor?.injectCss?.(rec.result_css || '');
                document.getElementById('bkecAiPrompt').value = rec.prompt || '';
                document.getElementById('bkecAiUndo').style.display = '';
                document.getElementById('bkecAiStatus').textContent = '이력 결과를 불러왔습니다 — 이어서 수정하거나 저장하세요.';
            } catch (error) { MubloRequest.showAlert(error.message || '이력 결과를 불러오지 못했습니다.', 'error'); }
            return;
        }

        const button = event.target.closest('[data-ai-history]'); if (!button) return;
        const record = aiRecords.find(item => Number(item.record_id) === Number(button.dataset.aiHistory)); if (!record) return;
        document.getElementById('bkecAiPrompt').value = record.prompt || '';
        document.getElementById('bkecAiMode').value = record.mode === 'modify' ? 'modify' : 'create';
        aiSelectedAssets = new Set((record.asset_ids || []).filter(id => aiAssets.some(asset => asset.id === id)));
        renderAiLibrary(); document.getElementById('bkecAiStatus').textContent = '생성 기록의 프롬프트와 자료 선택을 불러왔습니다.';
    });

    document.getElementById('bkecAiFiles').addEventListener('change', async event => {
        const files = Array.from(event.target.files || []); if (!files.length) return;
        const fd = new FormData(); fd.append('_token', CSRF); files.forEach(file => fd.append('files[]', file));
        document.getElementById('bkecAiStatus').textContent = '자료 저장 중…';
        try {
            const response = await fetch('/admin/block-editor/ai-assets-upload', {method:'POST', body:fd}); const json = await readAiJson(response);
            if (!json.success) throw new Error(json.message); (json.data || []).forEach(asset => aiSelectedAssets.add(asset.id));
            MubloRequest.showToast(json.message || 'AI 자료가 저장되었습니다.', 'success'); await loadAiLibrary();
        } catch (error) { MubloRequest.showAlert(error.message || '자료를 저장하지 못했습니다.', 'error'); }
        finally { event.target.value = ''; updateAiAvailability(); }
    });

    /* 반응형 품질 진단 (§9.2·§9.3) — 현재 편집 중인 HTML/CSS를 검사한다 */
    async function bkecRunQuality(serverAudit) {
        const panel = document.getElementById('bkecQuality');
        if (!panel || !cm || cm.type !== 'html') return;
        const editor = cm.editor || BlockHtmlEditor.getVisual('bke_html_content');
        editor?.sync?.();
        const html = editor ? editor.getHTML() : document.getElementById('bke_html_content').value;
        const css = document.getElementById('bke_html_css').value;
        const findings = await bkeResponsiveProbe({ html, css, frameLike: false });
        bkeRenderQuality(panel, serverAudit, findings, {
            onRecheck: () => bkecRunQuality(serverAudit),
            onFix: (messages) => {
                // 사용자가 보정 버튼을 눌렀을 때만 새 AI 요청을 보낸다 (§9.3)
                document.getElementById('bkecAiMode').value = 'modify';
                document.getElementById('bkecAiPrompt').value =
                    '아래 반응형 진단 문제를 구조와 내용은 유지하면서 수정해줘:\n- ' + messages.slice(0, 8).join('\n- ');
                document.getElementById('bkecAiGenerate').click();
            },
        });
    }

    document.getElementById('bkecAiGenerate').addEventListener('click', async () => {
        if (!cm || cm.type !== 'html' || aiAbort) return;
        if (!aiReady) { MubloRequest.showAlert('AI 설정에서 API 키를 등록하고 기능을 활성화해주세요.', 'warning'); return; }
        const prompt = document.getElementById('bkecAiPrompt').value.trim();
        if (!prompt) { MubloRequest.showAlert('AI에게 요청할 내용을 입력해주세요.', 'warning'); return; }

        const editor = cm.editor || BlockHtmlEditor.getVisual('bke_html_content');
        editor?.sync?.();
        const currentHtml = editor ? editor.getHTML() : document.getElementById('bke_html_content').value;
        const currentCss = document.getElementById('bke_html_css').value;
        const currentJs = document.getElementById('bke_html_js').value;
        const button = document.getElementById('bkecAiGenerate');
        const status = document.getElementById('bkecAiStatus');
        const normalButtonHtml = button.innerHTML;
        let generationSucceeded = false;
        const fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('row_id', cm.target.rowId);
        fd.append('column_index', cm.colIndex);
        fd.append('mode', document.getElementById('bkecAiMode').value);
        fd.append('prompt', prompt);
        fd.append('current_html', currentHtml);
        fd.append('current_css', currentCss);
        fd.append('current_js', currentJs);
        fd.append('asset_ids', JSON.stringify(Array.from(aiSelectedAssets)));

        aiAbort = new AbortController();
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> 생성 중입니다';
        status.textContent = '';
        try {
            const response = await fetch('/admin/block-editor/ai-html', { method: 'POST', body: fd, signal: aiAbort.signal });
            const json = await readAiJson(response);
            if (!json.success) throw new Error(json.message || 'HTML을 생성하지 못했습니다.');

            aiUndo = { html: currentHtml, css: currentCss, js: currentJs };
            const generated = json.data || {};
            document.getElementById('bke_html_content').value = generated.html || '';
            document.getElementById('bke_html_css').value = generated.css || '';
            document.getElementById('bke_html_js').value = generated.js || '';
            editor?.setHTML?.(generated.html || '');
            editor?.injectCss?.(generated.css || '');
            document.getElementById('bkecCssBadge').textContent = generated.css ? '· AI 생성' : '';
            document.getElementById('bkecCssAcc').open = !!generated.css;
            const hasCoreBehavior = String(generated.js || '').includes('mublo-block-behavior:start');
            document.getElementById('bkecJsBadge').textContent = hasCoreBehavior
                ? '· Core 동작'
                : (generated.js ? '· 사용 중' : '');
            if (hasCoreBehavior) document.getElementById('bkecJsAcc').open = true;
            document.getElementById('bkecAiUndo').style.display = '';
            status.textContent = generated.notes || '검토 후 아래 저장 버튼을 눌러주세요.';
            generationSucceeded = true;
            bkecRunQuality(generated.audit || null);
            await loadAiLibrary();
        } catch (error) {
            if (error.name !== 'AbortError') MubloRequest.showAlert(error.message || 'AI 요청에 실패했습니다.', 'error');
            status.textContent = error.name === 'AbortError' ? '취소됨' : '';
        } finally {
            aiAbort = null;
            button.innerHTML = generationSucceeded
                ? '<i class="bi bi-arrow-repeat"></i> 다시 생성'
                : normalButtonHtml;
            updateAiAvailability();
        }
    });

    window.addEventListener('focus', () => {
        if (cm?.type === 'html' && !aiAbort) loadAiLibrary();
    });
    document.getElementById('bkecAiUndo').addEventListener('click', () => {
        if (!cm || !aiUndo) return;
        const editor = cm.editor || BlockHtmlEditor.getVisual('bke_html_content');
        document.getElementById('bke_html_content').value = aiUndo.html;
        document.getElementById('bke_html_css').value = aiUndo.css;
        document.getElementById('bke_html_js').value = aiUndo.js;
        editor?.setHTML?.(aiUndo.html);
        editor?.injectCss?.(aiUndo.css);
        aiUndo = null;
        document.getElementById('bkecAiUndo').style.display = 'none';
        document.getElementById('bkecAiStatus').textContent = 'AI 적용 전 내용으로 되돌렸습니다.';
    });

    /** 이미지 아이템 편집기 — 파일은 아이템 객체에 임시로 붙였다가 저장 시 분리한다 */
    function renderImageItemsEditor() {
        // 출력 스타일 — 행 폼과 동일한 공용 컴포넌트. 값은 cm.config 에 바로 쓴다.
        let html = `<div class="bke-items-box" style="margin-bottom:12px;">
            <div style="font-size:12.5px;font-weight:600;margin-bottom:8px;"><i class="bi bi-grid-3x3-gap"></i> 출력 스타일</div>
            ${outputStyleHtml(cm.config)}
        </div>`;

        html += '<div style="font-size:12px;color:var(--bs-secondary-color);margin-bottom:10px;">'
            + '여러 장을 등록하면 위 출력 스타일대로 함께 표시됩니다. 여백·테두리는 칸 편집의 [스타일]에서.</div>';

        cm.items.forEach((item, i) => {
            const thumb = item._pcPreview || item.pc_image;
            html += `<div class="bke-imgitem" data-idx="${i}">
                <div class="bke-imgitem__thumb">${thumb ? `<img src="${esc(thumb)}" alt="">` : '<i class="bi bi-image"></i>'}</div>
                <div class="bke-imgitem__fields">
                    <div class="bke-imgitem__row">
                        <label class="bke-form-label">PC 이미지</label>
                        <input type="file" class="form-control form-control-sm" data-img="pc" accept="image/*">
                    </div>
                    <div class="bke-imgitem__row">
                        <label class="bke-form-label">모바일 <span style="font-weight:400;">(선택)</span></label>
                        <input type="file" class="form-control form-control-sm" data-img="mo" accept="image/*">
                    </div>
                    <div class="bke-imgitem__row">
                        <label class="bke-form-label">링크</label>
                        <input type="text" class="form-control form-control-sm" data-img="link" placeholder="/p/about-us 또는 https://…" value="${esc(item.link_url || '')}">
                        <label style="display:flex;align-items:center;gap:4px;font-size:11.5px;white-space:nowrap;">
                            <input type="checkbox" class="form-check-input" data-img="win" ${String(item.link_win) === '1' ? 'checked' : ''}> 새창
                        </label>
                    </div>
                </div>
                <button type="button" class="bke-iconbtn" data-img="del" title="이 이미지 삭제"><i class="bi bi-trash"></i></button>
            </div>`;
        });

        html += `<button type="button" class="bke-btn" style="width:100%;" id="bkecAddImage">
            <i class="bi bi-plus-lg"></i> 이미지 추가</button>`;

        cmEls.image.innerHTML = html;

        cmEls.image.querySelectorAll('.bke-imgitem').forEach(row => {
            const idx = parseInt(row.dataset.idx, 10);
            const item = cm.items[idx];

            // 썸네일 박스 클릭으로도 파일 선택 — 빈 칸에서도 직관적으로
            // 이미지 선택을 시작할 수 있도록 한다.
            const thumbEl = row.querySelector('.bke-imgitem__thumb');
            thumbEl.style.cursor = 'pointer';
            thumbEl.title = '클릭해서 이미지 선택';
            thumbEl.addEventListener('click', () => row.querySelector('[data-img="pc"]').click());

            row.querySelector('[data-img="pc"]').addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;
                item._pcFile = file;
                item._pcPreview = URL.createObjectURL(file);
                row.querySelector('.bke-imgitem__thumb').innerHTML = `<img src="${item._pcPreview}" alt="">`;
            });
            row.querySelector('[data-img="mo"]').addEventListener('change', (e) => {
                if (e.target.files[0]) item._moFile = e.target.files[0];
            });
            row.querySelector('[data-img="link"]').addEventListener('input', (e) => { item.link_url = e.target.value; });
            row.querySelector('[data-img="win"]').addEventListener('change', (e) => { item.link_win = e.target.checked ? '1' : '0'; });
            row.querySelector('[data-img="del"]').addEventListener('click', () => {
                cm.items.splice(idx, 1);
                renderImageItemsEditor();
            });
        });

        document.getElementById('bkecAddImage').addEventListener('click', () => {
            cm.items.push({ pc_image: '', mo_image: '', link_url: '', link_win: '0' });
            renderImageItemsEditor();
            // 기대 동작은 "이미지 추가 = 파일 고르기" — 새 행의 파일 선택창을
            // 바로 연다 (버튼 클릭의 사용자 제스처가 살아 있어 허용된다).
            cmEls.image.querySelector(`.bke-imgitem[data-idx="${cm.items.length - 1}"] [data-img="pc"]`)?.click();
        });

        // 출력 스타일 — 변경 즉시 cm.config 에 기록 (저장 시 content_config 로 직렬화)
        bindOutputStyle(cmEls.image, cm.config);
    }

    function renderVideoEditor() {
        const c = cm.config;
        const savedType = c.video_type === 'video' ? 'url' : (c.video_type || 'youtube');
        cmEls.video.innerHTML = `
            <div class="bke-form-row">
                <label class="bke-form-label">동영상 종류</label>
                <select class="form-select form-select-sm" id="bkec_video_type">
                    <option value="youtube" ${savedType === 'youtube' ? 'selected' : ''}>YouTube</option>
                    <option value="vimeo" ${savedType === 'vimeo' ? 'selected' : ''}>Vimeo</option>
                    <option value="url" ${savedType === 'url' ? 'selected' : ''}>동영상 파일 URL (mp4 등)</option>
                </select>
            </div>
            <div class="bke-form-row">
                <label class="bke-form-label">주소</label>
                <input type="text" class="form-control form-control-sm" id="bkec_video_url"
                       placeholder="https://www.youtube.com/watch?v=…" value="${esc(c.video_url || '')}">
            </div>
            <div class="bke-form-row bke-switch">
                <input type="checkbox" class="form-check-input" id="bkec_video_autoplay" ${c.autoplay ? 'checked' : ''}>
                <label for="bkec_video_autoplay" style="cursor:pointer;">자동 재생</label>
            </div>
            <div class="bke-form-row bke-switch">
                <input type="checkbox" class="form-check-input" id="bkec_video_muted" ${c.muted !== false ? 'checked' : ''}>
                <label for="bkec_video_muted" style="cursor:pointer;">음소거 <span style="color:var(--bs-secondary-color);">(자동 재생은 음소거가 필요합니다)</span></label>
            </div>`;
    }

    function extractYouTubeId(url) {
        const m = String(url).match(/(?:youtu\.be\/|[?&]v=|embed\/|shorts\/)([\w-]{6,})/);
        return m ? m[1] : '';
    }
    function extractVimeoId(url) {
        const m = String(url).match(/vimeo\.com\/(?:video\/)?(\d+)/);
        return m ? m[1] : '';
    }

    cmEls.save.addEventListener('click', () => {
        if (!cm) return;
        // 저장 = 즉시 게시 — '수정 필요' 상태면 확인 경고 (§9.3). 게시 자체는 막지 않는다.
        if (cm.type === 'html' && document.getElementById('bkecQuality').dataset.status === 'needs_fix') {
            MubloRequest.showConfirm(
                '반응형 진단이 "수정 필요" 상태입니다. 저장하면 이 상태로 게시됩니다. 계속할까요?',
                bkecDoSaveContent,
                { type: 'warning', confirmText: '저장' }
            );
            return;
        }
        bkecDoSaveContent();
    });

    async function bkecDoSaveContent() {
        if (!cm) return;

        const columns = cm.data.columns.slice();
        const c = { ...columns[cm.colIndex] };
        let appendExtra = null;

        if (cm.type === 'html') {
            const editor = cm.editor || BlockHtmlEditor.getVisual('bke_html_content');
            if (editor) { editor.sync?.(); cm.config.html = editor.getHTML(); }
            else { cm.config.html = document.getElementById('bke_html_content').value; }

            // CSS/JS — 비우면 키를 지운다(행 폼은 비면 키를 싣지 않는 것과 같은 결과)
            const css = document.getElementById('bke_html_css').value.trim();
            const js = document.getElementById('bke_html_js').value.trim();
            if (css) cm.config.css = css; else delete cm.config.css;
            if (js) cm.config.js = js; else delete cm.config.js;

            c.content_config = JSON.stringify(cm.config);
        } else if (cm.type === 'image') {
            // 파일·미리보기 임시 속성은 걷어내고, 서버가 업로드를 처리하도록
            // pc_has_file/mo_has_file 플래그를 세운다 (행 폼과 같은 계약).
            const items = cm.items.map(({ _pcFile, _moFile, _pcPreview, ...rest }) => ({
                ...rest,
                ...(_pcFile ? { pc_has_file: true } : {}),
                ...(_moFile ? { mo_has_file: true } : {}),
            }));
            c.content_items = JSON.stringify(items);
            c.content_config = JSON.stringify(cm.config); // 표시 방식 (pc_style/cols 등)
            // 스택 콘텐츠는 콘텐츠 단위 채널(column_content_images)로 — 칸 채널은
            // 스택 칸에서 서버가 무시한다 (미러는 서버 생성)
            const colIndex = cm.colIndex, srcItems = cm.items;
            const fileBase = Number.isInteger(cm.stackIndex)
                ? `column_content_images[${colIndex}][${cm.stackIndex}]`
                : `column_images[${colIndex}]`;
            appendExtra = (fd) => srcItems.forEach((it, i) => {
                if (it._pcFile) fd.append(`${fileBase}[${i}][pc]`, it._pcFile);
                if (it._moFile) fd.append(`${fileBase}[${i}][mo]`, it._moFile);
            });
        } else if (cm.type === 'movie') {
            const vType = document.getElementById('bkec_video_type').value;
            const vUrl = document.getElementById('bkec_video_url').value.trim();
            let videoId = vUrl;
            if (vType === 'youtube') videoId = extractYouTubeId(vUrl) || vUrl;
            if (vType === 'vimeo') videoId = extractVimeoId(vUrl) || vUrl;
            Object.assign(cm.config, {
                video_type: vType,
                type: vType === 'url' ? 'video' : vType, // 렌더러 호환 키 (행 폼과 같은 규칙)
                video_url: vUrl,
                video_id: videoId,
                autoplay: document.getElementById('bkec_video_autoplay').checked,
                muted: document.getElementById('bkec_video_muted').checked,
            });
            c.content_config = JSON.stringify(cm.config);
        }

        // 초안 타입(타입 선택 직후 '내용 편집'으로 들어온 경우)을 함께 기록 —
        // 저장된 타입과 같을 때는 원래 값 그대로라 무해하다.
        if (Number.isInteger(cm.stackIndex) && Array.isArray(c.contents) && c.contents[cm.stackIndex]) {
            // 스택 콘텐츠 내용 저장 — 칸 scalar 가 아니라 해당 콘텐츠에 반영 (계획 8.3)
            const contents = c.contents.map(x => ({ ...x }));
            const ct = contents[cm.stackIndex];
            ct.content_type = cm.type;
            if (c.content_config !== columns[cm.colIndex].content_config) ct.content_config = c.content_config;
            if (c.content_items !== columns[cm.colIndex].content_items) ct.content_items = c.content_items;
            // 칸 scalar 는 원본 유지 (미러는 서버 소유)
            c.content_config = columns[cm.colIndex].content_config;
            c.content_items = columns[cm.colIndex].content_items;
            c.content_type = columns[cm.colIndex].content_type;
            c.contents = contents;
        } else {
            c.content_type = cm.type;
        }
        columns[cm.colIndex] = c;

        const ok = await submitRow(cm.data.form, columns, cmEls.save,
            { rowId: cm.target.rowId, columnIndex: cm.colIndex }, appendExtra);
        if (ok) closeContentModal();
    }

    /** 저장 후 공통 갱신: 미리보기 리로드(스크롤 복원) + 행 메타 재조회 + 선택 유지 */
    function refreshPreviewAfterSave(keep) {
        state.pendingSelect = typeof keep === 'number' ? { rowId: keep } : (keep || null);
        const scrollY = els.iframe.contentWindow?.scrollY || 0;
        const restore = () => {
            els.iframe.contentWindow?.scrollTo(0, scrollY);
            els.iframe.removeEventListener('load', restore);
        };
        els.iframe.addEventListener('load', restore);
        if (state.context) loadRowsMeta(state.context.id);
        els.iframe.contentWindow?.location.reload();
    }

    /** 행/칸 공통 저장 — 기존 저장 API 에 formData + columns 전체를 제출한다 */
    async function submitRow(form, columns, btn, keepSelection, appendExtra) {
        const fd = new FormData();
        fd.append('_token', CSRF);
        Object.entries(form).forEach(([k, v]) => fd.append(`formData[${k}]`, v ?? ''));
        columns.forEach((c, i) => appendColumnFields(fd, c, i));
        if (appendExtra) appendExtra(fd); // 이미지 업로드 등 파일 필드

        const label = btn.textContent;
        btn.disabled = true; btn.textContent = '저장 중…';

        try {
            const res = await fetch('/admin/block-row/store', { method: 'POST', body: fd });
            const json = await res.json();
            if (!(json.success || json.result === 'success')) {
                MubloRequest.showAlert(json.message || '저장에 실패했습니다.', 'error');
                return false;
            }
            MubloRequest.showToast(json.message || '저장되었습니다.', 'success');
            refreshPreviewAfterSave(keepSelection);
            return true;
        } catch (e) {
            MubloRequest.showAlert('요청에 실패했습니다.', 'error');
            return false;
        } finally {
            // 콘텐츠 모달의 저장 버튼은 상주 요소다 — 성공 시에도 복구하지 않으면
            // 다음 저장이 영구히 막힌다.
            btn.disabled = false; btn.textContent = label;
        }
    }

    /* ---------------- 반응형 품질 진단 (개선 계획 §9.2·§9.3) ----------------
     * 격리된 오프스크린 iframe에 결과물만 넣고 360·768·1280·1440px에서 실제
     * 렌더를 검사한다. 표·코드·슬라이더 내부의 의도된 수평 스크롤은 전체
     * 페이지 overflow와 구분하며, 진단은 게시를 막지 않는다(§8.3). */
    const BKE_AUDIT_WIDTHS = [360, 768, 1280, 1440];

    function bkeDescribeEl(el) {
        return el.tagName.toLowerCase() + (el.classList && el.classList[0] ? '.' + el.classList[0] : '');
    }

    async function bkeResponsiveProbe(opts) {
        const findings = [];
        const iframe = document.createElement('iframe');
        iframe.setAttribute('aria-hidden', 'true');
        iframe.tabIndex = -1;
        iframe.style.cssText = 'position:absolute;left:-10000px;top:0;height:900px;border:0;visibility:hidden;';
        document.body.appendChild(iframe);
        try {
            for (const width of BKE_AUDIT_WIDTHS) {
                iframe.style.width = width + 'px';
                const body = opts.frameLike
                    ? '<div class="' + opts.scopeClass + '" style="display:contents">' + opts.html + '</div>'
                    : opts.html;
                const doc = iframe.contentDocument;
                doc.open();
                doc.write('<!doctype html><html><head><meta charset="utf-8">'
                    + '<style>html,body{margin:0;padding:0}</style>'
                    + '<style>' + (opts.css || '') + '</style></head><body>' + body + '</body></html>');
                doc.close();
                await new Promise(r => setTimeout(r, 60)); // 렌더 안정화
                const images = Array.from(doc.images || []);
                if (images.some(img => !img.complete)) {
                    await Promise.race([
                        Promise.all(images.map(img => img.complete
                            ? Promise.resolve()
                            : new Promise(res => { img.onload = img.onerror = res; }))),
                        new Promise(r => setTimeout(r, 400)),
                    ]);
                }
                bkeProbeViewport(doc, width, findings, opts);
                if (findings.length >= 24) break;
            }
        } catch (e) {
            /* 진단은 보조 기능 — 실패해도 편집·게시를 막지 않는다 */
        } finally {
            iframe.remove();
        }
        return findings;
    }

    function bkeProbeViewport(doc, width, findings, opts) {
        const win = doc.defaultView;
        const intended = el => !!el.closest('table, pre, .mublo-slider, .mublo-slider-track');
        const all = Array.from(doc.body.querySelectorAll('*'));

        // 1. 의도하지 않은 전체 가로 스크롤 (§12 완료 기준: 4개 폭에서 탐지)
        const docWidth = doc.documentElement.scrollWidth;
        if (docWidth > width + 1) {
            const offenders = [...new Set(all
                .filter(el => {
                    const r = el.getBoundingClientRect();
                    return r.width > 0 && r.right > width + 1 && !intended(el);
                })
                .map(bkeDescribeEl))].slice(0, 3);
            findings.push({ width, level: 'error',
                message: '[' + width + 'px] 전체 가로 스크롤 발생 (내용 폭 ' + Math.round(docWidth) + 'px)'
                    + (offenders.length ? ' — 원인 추정: ' + offenders.join(', ') : '') });
        }

        // 2. 고정 높이 때문에 잘린 텍스트
        const clipped = [...new Set(all.filter(el => {
            if (intended(el) || !el.textContent || el.textContent.trim() === '') return false;
            const cs = win.getComputedStyle(el);
            return (cs.overflow === 'hidden' || cs.overflowY === 'hidden')
                && el.scrollHeight > el.clientHeight + 4;
        }).map(bkeDescribeEl))].slice(0, 3);
        if (clipped.length) {
            findings.push({ width, level: 'warning',
                message: '[' + width + 'px] 내용이 잘리는 요소: ' + clipped.join(', ') + ' — min-height 또는 overflow를 확인하세요' });
        }

        // 3. 겹치는 주요 버튼·링크
        const clickables = Array.from(doc.body.querySelectorAll('a, button'))
            .map(el => ({ el, r: el.getBoundingClientRect() }))
            .filter(t => t.r.width > 4 && t.r.height > 4);
        outer:
        for (let i = 0; i < clickables.length; i++) {
            for (let j = i + 1; j < clickables.length; j++) {
                const a = clickables[i], b = clickables[j];
                if (a.el.contains(b.el) || b.el.contains(a.el)) continue;
                const x = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
                const y = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
                if (x > 0 && y > 0 && x * y > 0.35 * Math.min(a.r.width * a.r.height, b.r.width * b.r.height)) {
                    findings.push({ width, level: 'warning',
                        message: '[' + width + 'px] ' + bkeDescribeEl(a.el) + '와 ' + bkeDescribeEl(b.el) + '가 겹칩니다' });
                    break outer;
                }
            }
        }

        // 4. 이미지 비율 왜곡
        for (const img of Array.from(doc.images || [])) {
            if (!img.naturalWidth || !img.naturalHeight) continue;
            const r = img.getBoundingClientRect();
            if (r.width < 8 || r.height < 8) continue;
            const cs = win.getComputedStyle(img);
            if (cs.objectFit && cs.objectFit !== 'fill') continue;
            const natural = img.naturalWidth / img.naturalHeight;
            const rendered = r.width / r.height;
            if (Math.abs(natural - rendered) / natural > 0.18) {
                findings.push({ width, level: 'warning',
                    message: '[' + width + 'px] ' + bkeDescribeEl(img) + ' 이미지 비율 왜곡 — height:auto 또는 aspect-ratio + object-fit을 권장합니다' });
                break;
            }
        }

        // 5. Header 모바일 토글 노출 (프레임 header 전용, 360px — §9.2)
        if (opts.headerPart && width === 360) {
            const toggle = doc.getElementById('mubloPanelToggle');
            if (!toggle) {
                findings.push({ width, level: 'warning', message: '[360px] 모바일 토글 버튼(#mubloPanelToggle)을 찾을 수 없습니다' });
            } else {
                const cs = win.getComputedStyle(toggle);
                if (cs.display === 'none' || cs.visibility === 'hidden') {
                    findings.push({ width, level: 'warning',
                        message: '[360px] 생성 CSS가 모바일 토글을 감춥니다 — 토글 노출은 스킨 CSS가 제어하므로 display 지정을 제거하세요' });
                }
            }
        }
    }

    /* 품질 상태 렌더 — 서버 정적 검사(audit)와 브라우저 진단을 합쳐
     * 통과/경고/수정 필요를 표시한다 (§9.3). AI 보정은 명시 선택시에만. */
    function bkeRenderQuality(panel, serverAudit, browserFindings, actions) {
        if (!panel) return 'pass';
        const errors = [
            ...((serverAudit && serverAudit.errors) || []).map(f => f.message),
            ...browserFindings.filter(f => f.level === 'error').map(f => f.message),
        ];
        const warnings = [
            ...((serverAudit && serverAudit.warnings) || []).map(f => f.message),
            ...browserFindings.filter(f => f.level === 'warning').map(f => f.message),
        ];
        const status = errors.length ? 'needs_fix' : (warnings.length ? 'warning' : 'pass');
        panel.dataset.status = status;

        const chip = status === 'needs_fix'
            ? '<span class="bke-quality__chip bke-quality__chip--bad"><i class="bi bi-exclamation-triangle"></i> 수정 필요</span>'
            : status === 'warning'
                ? '<span class="bke-quality__chip bke-quality__chip--warn"><i class="bi bi-exclamation-circle"></i> 경고</span>'
                : '<span class="bke-quality__chip bke-quality__chip--ok"><i class="bi bi-check-circle"></i> 통과</span>';

        const escq = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const items = errors.map(m => '<li class="is-error">' + escq(m) + '</li>').join('')
            + warnings.map(m => '<li>' + escq(m) + '</li>').join('');

        panel.innerHTML = '<div class="bke-quality__head">반응형 진단 ' + chip
            + '<button type="button" class="bke-quality__btn" data-quality-recheck><i class="bi bi-arrow-clockwise"></i> 다시 검사</button>'
            + (status !== 'pass' && actions && actions.onFix
                ? '<button type="button" class="bke-quality__btn" data-quality-fix><i class="bi bi-stars"></i> AI로 반응형 보정</button>'
                : '')
            + '</div>'
            + (items
                ? '<details class="bke-quality__list"' + (status === 'needs_fix' ? ' open' : '') + '>'
                    + '<summary>진단 ' + (errors.length + warnings.length) + '건 (360·768·1280·1440px)</summary>'
                    + '<ul>' + items + '</ul></details>'
                : '<div class="bke-quality__ok-note">360·768·1280·1440px 검사에서 문제가 발견되지 않았습니다.</div>');
        panel.style.display = '';

        const recheckBtn = panel.querySelector('[data-quality-recheck]');
        if (recheckBtn && actions && actions.onRecheck) recheckBtn.onclick = actions.onRecheck;
        const fixBtn = panel.querySelector('[data-quality-fix]');
        if (fixBtn && actions && actions.onFix) fixBtn.onclick = () => actions.onFix(errors.concat(warnings));
        return status;
    }

    /* ---------------- 디바이스 폭 · 새로고침 ---------------- */

    const BKE_DEVICE_CLASSES = { wide: 'w-wide', desktop: 'w-desktop', tablet: 'w-tablet', mobile: 'w-mobile' };
    document.querySelectorAll('.bke-devices button').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.bke-devices button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            els.frame.classList.remove('w-wide', 'w-desktop', 'w-tablet', 'w-mobile');
            const widthClass = BKE_DEVICE_CLASSES[btn.dataset.device];
            if (widthClass) els.frame.classList.add(widthClass);
            // 폭 전환 애니메이션이 끝난 뒤 좌표 재계산
            setTimeout(() => { refreshTargets(); repositionBoxes(); }, 300);
        });
    });

    els.refresh.addEventListener('click', () => {
        if (state.context?.preview_url) {
            hideOverlayBoxes();
            els.iframe.contentWindow?.location.reload();
        }
    });

    /* ---------------- 미리보기 조작 모드 (설계 5.2) ----------------
     * 오버레이가 포인터를 놓아 슬라이더·버튼을 직접 확인할 수 있다.
     * 링크로 컨텍스트 화면을 벗어나면 자동으로 되돌린다. */

    const interactBtn = document.getElementById('bkeInteract');
    interactBtn.addEventListener('click', () => {
        state.interact = !state.interact;
        interactBtn.classList.toggle('active', state.interact);
        els.overlay.style.pointerEvents = state.interact ? 'none' : '';
        hideOverlayBoxes();
        MubloRequest.showToast(
            state.interact
                ? '조작 모드 — 미리보기를 직접 사용할 수 있습니다. 편집하려면 다시 끄세요.'
                : '편집 모드로 돌아왔습니다.',
            'info'
        );
    });

    els.iframe.addEventListener('load', () => {
        // 조작 모드에서 링크로 컨텍스트 화면을 벗어나면 자동 복귀 (설계 5.2)
        const previewPath = (state.context?.preview_url || '').split('?')[0];
        const currentPath = (() => {
            try { return els.iframe.contentWindow.location.pathname; } catch (e) { return previewPath; }
        })();
        if (state.interact && previewPath && currentPath !== previewPath) {
            MubloRequest.showToast('편집 중인 화면으로 되돌렸습니다.', 'info');
            els.iframe.src = state.context.preview_url
                + (state.context.preview_url.includes('?') ? '&' : '?') + '_editor=1';
            return;
        }

        state.selected = null;
        hideOverlayBoxes();
        refreshTargets();

        // 저장 직후 리로드라면 방금 편집하던 행/칸을 다시 선택해 흐름을 잇는다.
        if (state.pendingSelect) {
            const keep = state.pendingSelect;
            state.pendingSelect = null;
            setTimeout(() => {
                let t = null;
                if (keep.columnIndex != null) {
                    // 저장 시 칸이 재생성되어 column_id 가 바뀐다 — 인덱스로 찾는다.
                    const cols = state.targets.filter(x => x.kind === 'column' && !x.contentId && x.rowId === keep.rowId);
                    t = cols[keep.columnIndex] || null;
                }
                if (!t) t = state.targets.find(x => x.kind === 'row' && x.rowId === keep.rowId);
                if (t) select(t);
            }, 150);
        }
    });

    /* ---------------- 시작 ---------------- */

    renderTree();

    const first = INITIAL && findContext(INITIAL) ? INITIAL
        : (CONTEXTS.screen?.[0]?.id || CONTEXTS.pages?.[0]?.id || null);
    if (first) selectContext(first);
})();
</script>
