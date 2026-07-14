/**
 * 콘텐츠 스택 공통 상태 모듈 + 전통 블록행 폼 UI 글루 (계획 8.2)
 *
 * 상태의 단일 진실은 #columns-data 의 hidden input 이다:
 *   columns[i][content_mode] / columns[i][pc_content_gap] / columns[i][mobile_content_gap]
 *   columns[i][contents][j][field]
 * 모듈은 그 위의 얇은 API 로, 별도 사본 상태를 두지 않아 재정렬·칸 수 변경
 * (blockrow-form.js 의 히든 인풋 재구축)과 어긋나지 않는다.
 *
 * 책임 (계획 8.2 목록 그대로):
 * - 콘텐츠 draft 생성·복제·삭제·정렬 / single↔stack 직렬화 / 최대 개수·중복 방어
 * - PC/Mobile gap 정규화 / 레거시 single ↔ stack payload 전환
 * 두 편집기는 목록 DOM 과 모달 연결만 각자 담당한다.
 */
(function () {
    'use strict';

    const MAX_CONTENTS = 20;
    const MAX_GAP = 200;
    const CONTENT_FIELDS = [
        'content_id', 'content_type', 'content_kind', 'content_skin',
        'title_config', 'content_config', 'content_items', 'is_active',
    ];
    const LEGACY_CONTENT_FIELDS = [
        'content_type', 'content_kind', 'content_skin',
        'title_config', 'content_config', 'content_items',
    ];

    // =========================================================================
    // 상태 계약 (hidden input 위의 얇은 API)
    // =========================================================================
    const BlockContentStack = {
        MAX_CONTENTS,

        container() {
            return document.getElementById('columns-data');
        },

        _input(name) {
            const container = this.container();
            return container ? container.querySelector(`input[name="${CSS.escape(name)}"]`) : null;
        },

        _setInput(name, value) {
            const container = this.container();
            if (!container) return;
            let input = this._input(name);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                container.appendChild(input);
            }
            input.value = value;
        },

        _removeInputs(prefix) {
            const container = this.container();
            if (!container) return;
            Array.from(container.querySelectorAll('input[type="hidden"]')).forEach((inp) => {
                if ((inp.name || '').startsWith(prefix)) inp.remove();
            });
        },

        isStack(i) {
            const input = this._input(`columns[${i}][content_mode]`);
            return !!input && input.value === 'stack';
        },

        getGaps(i) {
            const pc = this._input(`columns[${i}][pc_content_gap]`);
            const mo = this._input(`columns[${i}][mobile_content_gap]`);
            return {
                pc: pc ? Math.max(0, Math.min(MAX_GAP, parseInt(pc.value, 10) || 0)) : 0,
                mo: mo ? Math.max(0, Math.min(MAX_GAP, parseInt(mo.value, 10) || 0)) : 0,
            };
        },

        setGaps(i, pc, mo) {
            this._setInput(`columns[${i}][pc_content_gap]`, String(Math.max(0, Math.min(MAX_GAP, parseInt(pc, 10) || 0))));
            this._setInput(`columns[${i}][mobile_content_gap]`, String(Math.max(0, Math.min(MAX_GAP, parseInt(mo, 10) || 0))));
        },

        /** 레거시(단일) 콘텐츠 필드 읽기 */
        readLegacy(i) {
            const draft = {};
            LEGACY_CONTENT_FIELDS.forEach((field) => {
                const input = this._input(`columns[${i}][${field}]`);
                draft[field] = input ? input.value : '';
            });
            return draft;
        },

        /** 레거시(단일) 콘텐츠 필드 쓰기 — 모달 브리지·단일 축소용 */
        writeLegacy(i, draft) {
            LEGACY_CONTENT_FIELDS.forEach((field) => {
                this._setInput(`columns[${i}][${field}]`, draft[field] !== undefined && draft[field] !== null ? String(draft[field]) : '');
            });
        },

        /** @return {Array<Object>} 스택 콘텐츠 draft 목록 (j 순서) */
        readContents(i) {
            const container = this.container();
            if (!container) return [];
            const byIndex = {};
            const re = new RegExp(`^columns\\[${i}\\]\\[contents\\]\\[(\\d+)\\]\\[([a-z_]+)\\]$`);
            container.querySelectorAll('input[type="hidden"]').forEach((inp) => {
                const m = (inp.name || '').match(re);
                if (!m) return;
                const j = parseInt(m[1], 10);
                (byIndex[j] = byIndex[j] || {})[m[2]] = inp.value;
            });
            return Object.keys(byIndex)
                .map(Number)
                .sort((a, b) => a - b)
                .map((j) => byIndex[j]);
        },

        /** 스택 콘텐츠 전체 재직렬화 — 배열 순서가 sort_order 의 진실 */
        writeContents(i, drafts) {
            this._removeInputs(`columns[${i}][contents]`);
            drafts.forEach((draft, j) => {
                CONTENT_FIELDS.forEach((field) => {
                    if (field === 'content_id' && !(parseInt(draft.content_id, 10) > 0)) return; // 신규는 생략
                    const value = draft[field] !== undefined && draft[field] !== null ? String(draft[field]) : '';
                    this._setInput(`columns[${i}][contents][${j}][${field}]`, field === 'is_active' && value === '' ? '1' : value);
                });
            });
        },

        /** 단일 칸 → 스택 전환: 기존 콘텐츠가 있으면 첫 콘텐츠로 이관 (계획 5.2 UX) */
        convertToStack(i) {
            if (this.isStack(i)) return;
            const legacy = this.readLegacy(i);
            const drafts = [];
            if ((legacy.content_type || '') !== '') {
                drafts.push(Object.assign({ is_active: '1' }, legacy));
            }
            this._setInput(`columns[${i}][content_mode]`, 'stack');
            this.setGaps(i, 0, 0);
            this.writeContents(i, drafts);
        },

        /** 스택 → 단일 축소: 유지할 콘텐츠를 레거시 필드로 (계획 5.3 — 명시적 선택) */
        reduceToSingle(i, keepIndex) {
            const drafts = this.readContents(i);
            const keep = drafts[keepIndex];
            if (!keep) return false;
            this.writeLegacy(i, keep);
            this._removeInputs(`columns[${i}][contents]`);
            const modeInput = this._input(`columns[${i}][content_mode]`);
            if (modeInput) modeInput.value = 'single';
            return true;
        },

        addContent(i, draft) {
            const drafts = this.readContents(i);
            if (drafts.length >= MAX_CONTENTS) return -1;
            drafts.push(Object.assign({ content_kind: 'CORE', is_active: '1' }, draft || {}));
            this.writeContents(i, drafts);
            return drafts.length - 1;
        },

        duplicateContent(i, j) {
            const drafts = this.readContents(i);
            if (!drafts[j] || drafts.length >= MAX_CONTENTS) return -1;
            const copy = Object.assign({}, drafts[j]);
            delete copy.content_id; // 복제본은 신규 — stable ID 는 서버가 발급
            drafts.splice(j + 1, 0, copy);
            this.writeContents(i, drafts);
            return j + 1;
        },

        /** 마지막 콘텐츠 삭제 금지 (계획 6.2.3) — false 반환 시 호출자가 안내 */
        removeContent(i, j) {
            const drafts = this.readContents(i);
            if (drafts.length <= 1) return false;
            drafts.splice(j, 1);
            this.writeContents(i, drafts);
            return true;
        },

        moveContent(i, j, delta) {
            const drafts = this.readContents(i);
            const target = j + delta;
            if (!drafts[j] || target < 0 || target >= drafts.length) return false;
            const [item] = drafts.splice(j, 1);
            drafts.splice(target, 0, item);
            this.writeContents(i, drafts);
            return true;
        },

        toggleActive(i, j) {
            const drafts = this.readContents(i);
            if (!drafts[j]) return;
            drafts[j].is_active = drafts[j].is_active === '0' ? '1' : '0';
            this.writeContents(i, drafts);
        },

        updateContent(i, j, fields) {
            const drafts = this.readContents(i);
            if (!drafts[j]) return;
            Object.assign(drafts[j], fields);
            this.writeContents(i, drafts);
        },
    };

    window.BlockContentStack = BlockContentStack;

    // =========================================================================
    // 전통 블록행 폼 UI 글루 — 폼 페이지에서만 활성화
    // =========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        const preview = document.getElementById('columns-preview');
        if (!preview || !BlockContentStack.container()) return;

        // 설정 모달이 현재 편집 중인 스택 콘텐츠 (null = 칸 자체/단일 콘텐츠 편집)
        let modalTarget = null;

        const typeLabel = (type) => {
            const instance = window.BlockRowForm && window.BlockRowForm.getInstance && window.BlockRowForm.getInstance();
            const label = instance ? instance.getContentTypeLabel(type) : '';
            return label || type || '미설정';
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

        // ---------------------------------------------------------------------
        // 카드 목록 렌더
        // ---------------------------------------------------------------------
        /**
         * 스택 칸 카드를 2단 배치로 재구성 — 좌: 칸 정보(제목·요약·칸 스타일),
         * 우: 콘텐츠 세로 목록(실제 세로 스택 순서와 시각적으로 일치).
         * 카드 재구축(칸 수 변경·재정렬) 후에도 다시 적용되도록 멱등이다.
         */
        function ensureStackLayout(card, host) {
            const body = card.querySelector('.card-body');
            if (!body || body.querySelector('.stack-card-left')) return;
            const left = document.createElement('div');
            left.className = 'stack-card-left text-center flex-shrink-0';
            left.style.minWidth = '150px';
            Array.from(body.children).forEach((child) => {
                if (child !== host) left.appendChild(child);
            });
            // flex-wrap: 좁은 칸(예: 25% 칸 4개)에서는 목록이 칸 정보 아래로
            // 내려가 화면이 깨지지 않는다.
            body.classList.add('d-flex', 'align-items-start', 'gap-3', 'flex-wrap');
            body.classList.remove('text-center');
            body.appendChild(left);
            body.appendChild(host);
            host.classList.remove('mt-2');
            // 목록은 고정 폭 — 칸이 넓어도 같이 늘어나지 않는다.
            // 좁은 칸에서는 flex-wrap + maxWidth 100% 로 아래 줄로 내려간다.
            host.style.flex = '0 0 auto';
            host.style.width = '280px';
            host.style.maxWidth = '100%';
        }

        /** 스택 → 단일 축소 시 원래 중앙 배치로 복원 */
        function restoreSingleLayout(card, host) {
            const body = card.querySelector('.card-body');
            const left = body?.querySelector('.stack-card-left');
            if (!left) return;
            Array.from(left.children).forEach((child) => body.insertBefore(child, left));
            left.remove();
            body.classList.remove('d-flex', 'align-items-start', 'gap-3', 'flex-wrap');
            body.classList.add('text-center');
            host.classList.remove('flex-grow-1');
            host.classList.add('mt-2');
            host.style.flex = '';
            host.style.width = '';
            host.style.maxWidth = '';
            body.appendChild(host);
        }

        function renderCard(card) {
            const i = parseInt(card.dataset.index, 10);
            if (Number.isNaN(i)) return;

            let host = card.querySelector('.stack-content-list');
            if (!host) {
                host = document.createElement('div');
                host.className = 'stack-content-list mt-2 text-start';
                card.querySelector('.card-body')?.appendChild(host);
            }

            if (!BlockContentStack.isStack(i)) {
                restoreSingleLayout(card, host);
                // 카드의 [설정/수정] 버튼(btn btn-sm btn-outline-*)과 같은 크기
                host.innerHTML = `
                    <div class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-stack-act="convert" title="한 칸에 여러 콘텐츠 배치">
                            <i class="bi bi-plus-lg"></i> 콘텐츠 추가
                        </button>
                    </div>`;
                return;
            }

            ensureStackLayout(card, host);

            const drafts = BlockContentStack.readContents(i);
            const gaps = BlockContentStack.getGaps(i);

            // 좌측 칸 정보: badge 는 요약으로, [수정]은 칸 스타일 전용으로
            const typeBadge = card.querySelector('.column-type-badge');
            const editButton = card.querySelector('.stack-card-left button');
            if (typeBadge) {
                typeBadge.textContent = `콘텐츠 ${drafts.length}개`;
                typeBadge.classList.remove('bg-secondary');
                typeBadge.classList.add('bg-primary');
            }
            if (editButton) {
                editButton.innerHTML = '칸 스타일';
                editButton.title = '너비·여백·배경·테두리 — 콘텐츠 설정은 오른쪽 목록의 톱니 버튼';
            }

            // 우측: 콘텐츠 세로 목록 (위→아래 = 실제 출력 순서)
            const rows = drafts.map((draft, j) => {
                const inactive = draft.is_active === '0';
                return `
                <div class="d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 ${inactive ? 'opacity-50' : ''}" data-stack-row="${j}" style="cursor:grab;" title="드래그로 순서 변경">
                    <span class="stack-drag text-muted"><i class="bi bi-grip-vertical"></i></span>
                    ${inactive ? '<i class="bi bi-eye-slash text-muted" title="비활성 콘텐츠"></i>' : ''}
                    <span class="fw-semibold small flex-grow-1 text-truncate">${escapeHtml(typeLabel(draft.content_type))}</span>
                    <span class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary" title="설정" data-stack-act="edit"><i class="bi bi-gear-fill"></i></button>
                        <button type="button" class="btn btn-outline-danger" title="삭제" data-stack-act="del"><i class="bi bi-trash"></i></button>
                    </span>
                </div>`;
            }).join('');

            host.innerHTML = `
                <div class="stack-rows">${rows}</div>
                <button type="button" class="btn btn-sm w-100 text-muted mb-1" style="border:1px dashed var(--bs-border-color);" data-stack-act="add" title="콘텐츠 추가">
                    <i class="bi bi-plus-lg"></i> 콘텐츠 추가
                </button>
                <div class="d-flex align-items-center gap-1 small text-muted" title="콘텐츠 간격 (px)">
                    <i class="bi bi-distribute-vertical"></i><span>간격</span>
                    PC <input type="number" class="form-control form-control-sm" style="width:56px" min="0" max="${MAX_GAP}" value="${gaps.pc}" data-stack-gap="pc">
                    MO <input type="number" class="form-control form-control-sm" style="width:56px" min="0" max="${MAX_GAP}" value="${gaps.mo}" data-stack-gap="mo">
                </div>`;

            // 드래그 정렬 — Sortable 은 칸 카드 정렬용으로 이미 로드되어 있다.
            // 행 전체가 드래그 대상(버튼·입력만 제외) — 그립은 시각적 안내.
            // 카드 Sortable 쪽에서 .stack-rows 를 filter 로 제외하므로 행 드래그가
            // 카드 드래그로 새지 않는다. (↑↓ 버튼은 비드래그 대체 수단으로 유지, 계획 8.4)
            if (typeof Sortable !== 'undefined') {
                const rowsBox = host.querySelector('.stack-rows');
                if (rowsBox) {
                    Sortable.create(rowsBox, {
                        animation: 150,
                        filter: 'button, input',
                        preventOnFilter: false,
                        onEnd: (evt) => {
                            if (evt.oldIndex === evt.newIndex) return;
                            const current = BlockContentStack.readContents(i);
                            const [moved] = current.splice(evt.oldIndex, 1);
                            current.splice(evt.newIndex, 0, moved);
                            BlockContentStack.writeContents(i, current);
                            // 미저장 파일 인풋도 새 인덱스를 따라간다
                            const o = evt.oldIndex, n = evt.newIndex;
                            remapContentFileInputs(i, (x) => {
                                if (x === o) return n;
                                if (o < n && x > o && x <= n) return x - 1;
                                if (n < o && x >= n && x < o) return x + 1;
                                return x;
                            });
                            renderAll();
                        },
                    });
                }
            }
        }

        function renderAll() {
            preview.querySelectorAll('.column-preview-item').forEach(renderCard);
        }

        // ---------------------------------------------------------------------
        // 모달 브리지 — 하나의 칸 설정 모달을 콘텐츠 단위로 재사용 (계획 8.2)
        // ---------------------------------------------------------------------
        function openStackContent(i, j) {
            const drafts = BlockContentStack.readContents(i);
            const draft = drafts[j];
            if (!draft) return;
            // 선택한 콘텐츠 draft 를 레거시 필드에 스테이징 → 기존 모달 로딩 경로 재사용.
            // 스택 칸의 레거시 필드 제출값은 서버가 무시(미러는 서버 생성)하므로 안전.
            BlockContentStack.writeLegacy(i, draft);
            modalTarget = { i, j };
            originalOpen(i);
            setModalStackMode(i, false);
        }

        /**
         * 모달 탭 제어 — 스택 칸의 [칸 스타일] 모달에서는 제목·콘텐츠 탭을 숨긴다.
         * 스택 칸의 레거시 콘텐츠 제출값은 서버가 무시하므로, 거기서 출력
         * 설정(슬라이드 등)을 바꾸면 조용히 버려지는 함정이 된다.
         */
        function setModalStackMode(i, isColumnStyleOnly) {
            const modal = document.getElementById('columnModal');
            if (!modal) return;
            const hide = isColumnStyleOnly && BlockContentStack.isStack(i);
            ['#tab-title', '#tab-content'].forEach((sel) => {
                const tabButton = modal.querySelector(`[data-bs-target="${sel}"]`);
                if (tabButton) tabButton.closest('.nav-item, li')
                    ? (tabButton.closest('.nav-item, li').style.display = hide ? 'none' : '')
                    : (tabButton.style.display = hide ? 'none' : '');
            });
            if (hide) {
                // 스타일 탭 강제 활성
                const styleTab = modal.querySelector('[data-bs-target="#tab-style"]');
                if (styleTab && window.bootstrap?.Tab) window.bootstrap.Tab.getOrCreateInstance(styleTab).show();
                const title = modal.querySelector('.modal-title');
                if (title && !title.dataset.stackNote) {
                    title.dataset.stackNote = '1';
                    title.insertAdjacentHTML('beforeend', ' <small class="text-muted stack-style-note">— 칸 스타일 (콘텐츠 설정은 카드 목록의 톱니 버튼)</small>');
                }
            } else {
                modal.querySelector('.modal-title .stack-style-note')?.remove();
                const title = modal.querySelector('.modal-title');
                if (title) delete title.dataset.stackNote;
            }
        }

        const originalOpen = window.openColumnModal;
        const originalSave = window.saveColumnSettings;

        window.openColumnModal = function (index) {
            modalTarget = null; // 칸 자체(단일 콘텐츠 또는 스택 칸 스타일) 편집
            originalOpen(index);
            setModalStackMode(index, true);
        };

        window.saveColumnSettings = function () {
            originalSave(); // 모달 → 레거시 hidden input 반영 (기존 경로)
            if (modalTarget) {
                const { i, j } = modalTarget;
                const legacy = BlockContentStack.readLegacy(i);
                BlockContentStack.updateContent(i, j, legacy);
                claimContentFileInputs(i, j);
            }
            renderAll();
        };

        // 칸 채널 → 콘텐츠 채널 파일 인풋 이름 쌍 (콘텐츠 이미지 / 제목 이미지)
        const FILE_CHANNELS = [
            ['column_images', 'column_content_images'],
            ['column_title_image', 'column_content_title_images'],
        ];

        /**
         * 파일 인풋 탐색 루트 = 실제 <form>. '.mublo-submit' 은 제출 버튼이라
         * 그 기준으로 찾으면 버튼 안에 붙는 콘텐츠 이미지 인풋만 보이고, 폼에
         * 직접 붙는 제목 이미지 인풋(attachTitleImageFilesToForm)을 놓친다.
         */
        function fileInputRoot() {
            const btn = document.querySelector('.mublo-submit');
            return btn ? btn.closest('form') : document.querySelector('form');
        }

        /**
         * 모달이 메인 폼에 붙인 칸 채널 파일 인풋(column_images[i]... 등)을 콘텐츠
         * 채널(column_content_images[i][j]... 등)로 옮긴다. 스택 칸의 칸 채널은
         * 서버가 무시하므로 옮기지 않으면 업로드가 조용히 유실된다.
         */
        function claimContentFileInputs(i, j) {
            const form = fileInputRoot();
            if (!form) return;
            FILE_CHANNELS.forEach(([columnChannel, contentChannel]) => {
                // 같은 콘텐츠의 이전 파일 인풋은 이번 저장 내용으로 대체
                form.querySelectorAll(`input[type="file"][name^="${contentChannel}[${i}][${j}]"]`)
                    .forEach((el) => el.remove());
                form.querySelectorAll(`input[type="file"][name^="${columnChannel}[${i}]"]`).forEach((el) => {
                    const suffix = el.name.slice(`${columnChannel}[${i}]`.length); // "[img][pc]"·"[pc]" 등
                    el.name = `${contentChannel}[${i}][${j}]${suffix}`;
                });
            });
        }

        /**
         * 콘텐츠 순서 변경·삭제 시 미저장 파일 인풋의 콘텐츠 인덱스를 따라
         * 옮긴다. mapFn(oldJ) → newJ (null 이면 인풋 제거).
         */
        function remapContentFileInputs(i, mapFn) {
            const form = fileInputRoot();
            if (!form) return;
            FILE_CHANNELS.forEach(([, contentChannel]) => {
                const prefix = `${contentChannel}[${i}][`;
                form.querySelectorAll('input[type="file"]').forEach((el) => {
                    if (!el.name.startsWith(prefix)) return;
                    const rest = el.name.slice(prefix.length);
                    const end = rest.indexOf(']');
                    const oldJ = parseInt(rest.slice(0, end), 10);
                    const suffix = rest.slice(end + 1);
                    const newJ = mapFn(oldJ);
                    if (newJ === null) { el.remove(); return; }
                    if (newJ !== oldJ) el.name = `${contentChannel}[${i}][${newJ}]${suffix}`;
                });
            });
        }

        document.getElementById('columnModal')?.addEventListener('hidden.bs.modal', () => {
            modalTarget = null;
        });

        // ---------------------------------------------------------------------
        // 목록 액션
        // ---------------------------------------------------------------------
        preview.addEventListener('click', (event) => {
            const button = event.target.closest('[data-stack-act]');
            if (!button) return;
            const card = button.closest('.column-preview-item');
            if (!card) return;
            const i = parseInt(card.dataset.index, 10);
            const rowEl = button.closest('[data-stack-row]');
            const j = rowEl ? parseInt(rowEl.dataset.stackRow, 10) : -1;

            switch (button.dataset.stackAct) {
                case 'convert': {
                    BlockContentStack.convertToStack(i);
                    // 단일 칸 시절 첨부한 미저장 파일은 첫 콘텐츠(기존 칸) 몫으로 인계
                    claimContentFileInputs(i, 0);
                    const newIndex = BlockContentStack.addContent(i);
                    renderAll();
                    if (newIndex >= 0) openStackContent(i, newIndex);
                    break;
                }
                case 'add': {
                    const newIndex = BlockContentStack.addContent(i);
                    if (newIndex < 0) {
                        alert(`한 칸에는 콘텐츠를 최대 ${MAX_CONTENTS}개까지 배치할 수 있습니다.`);
                        break;
                    }
                    renderAll();
                    openStackContent(i, newIndex);
                    break;
                }
                case 'edit':
                    openStackContent(i, j);
                    break;
                case 'del': {
                    const drafts = BlockContentStack.readContents(i);
                    const label = typeLabel(drafts[j] ? drafts[j].content_type : '');
                    if (!confirm(`콘텐츠(${label})를 삭제하시겠습니까?`)) break;
                    if (!BlockContentStack.removeContent(i, j)) {
                        alert('마지막 콘텐츠는 삭제할 수 없습니다.');
                        break;
                    }
                    remapContentFileInputs(i, (x) => (x === j ? null : (x > j ? x - 1 : x)));
                    renderAll();
                    break;
                }
            }
        });

        preview.addEventListener('change', (event) => {
            const gapInput = event.target.closest('[data-stack-gap]');
            if (!gapInput) return;
            const card = gapInput.closest('.column-preview-item');
            if (!card) return;
            const i = parseInt(card.dataset.index, 10);
            const gaps = BlockContentStack.getGaps(i);
            if (gapInput.dataset.stackGap === 'pc') {
                BlockContentStack.setGaps(i, gapInput.value, gaps.mo);
            } else {
                BlockContentStack.setGaps(i, gaps.pc, gapInput.value);
            }
        });

        // 칸 수 변경·재정렬로 카드가 재구축되면 목록도 다시 그린다
        new MutationObserver(() => renderAll()).observe(preview, { childList: true });

        renderAll();
    });
})();
