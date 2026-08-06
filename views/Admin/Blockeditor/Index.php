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
 * @var string $returnUrl      에디터 진입 직전의 안전한 관리자 내부 경로
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
    <a href="<?= htmlspecialchars($returnUrl ?? '/admin/block-page?activeCode=004_002', ENT_QUOTES) ?>" class="bke-back" title="방금 보던 관리자 화면으로 돌아가기">
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
<script src="<?= asset('/assets/js/admin/block-content-capabilities.js') ?>"></script>

<?php /* 블록 에디터 본체(3,763줄)는 blockeditor.js 로 분리돼 있다. 여기서는
   서버 데이터만 심어 준다 — 위 MubloFrontPreviewTokens 와 같은 방식.

   JSON_HEX_TAG 는 값 안의 '<' 를 \u003C 로 바꾼다. 스킨 이름이나 콘텐츠 타입
   라벨에 '</script' 가 섞여 들어와도 이 인라인 블록이 조기 종료되지 않는다. */ ?>
<script>
window.MubloBlockEditorConfig = {
    contexts: <?= json_encode($initialContexts ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    initialContext: <?= json_encode($initialContext ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    csrfToken: <?= json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES) ?>,
    contentTypes: <?= json_encode($contentTypes ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    skinLists: <?= json_encode($skinLists ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    canEditInclude: <?= !empty($canEditInclude) ? 'true' : 'false' ?>,
};
</script>
<?php /* 동기 로드 유지 — 이 스크립트는 즉시 document.getElementById 로 위 마크업을
   찾고, MubloRequest·BlockHtmlEditor·MubloBlockCapabilities 가 이미 실려 있다고
   가정한다. defer/async 를 붙이면 둘 다 깨진다. */ ?>
<script src="<?= asset('/assets/js/admin/blockeditor.js') ?>"></script>
