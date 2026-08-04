/**
 * BlockRow Form - 블록 행 설정 폼 관리
 *
 * 블록 시스템의 핵심 컴포넌트로, 행(Row)과 칸(Column) 설정을 담당합니다.
 *
 * @requires MubloRequest
 * @requires Bootstrap 5
 */
(function() {
    'use strict';

    /**
     * BlockRowForm 클래스
     * 블록 행 설정 폼의 전체 로직을 관리
     */
    class BlockRowForm {
        constructor(config) {
            this.config = config || {};
            this.contentTypes = config.contentTypes || [];
            this.contentTypeGroups = config.contentTypeGroups || {};
            this.skinLists = config.skinLists || {};
            this.domainId = config.domainId || 1;
            this.rowId = Number(config.rowId || 0);
            this.csrfToken = config.csrfToken || '';
            this.htmlAiAbort = null;
            this.htmlAiUndo = null;
            this.htmlAiAssets = [];
            this.htmlAiRecords = [];
            this.htmlAiSelectedAssets = new Set();
            this.htmlAiReady = false;

            this.elements = {
                columnCount: document.getElementById('column_count'),
                columnsPreview: document.getElementById('columns-preview'),
                columnsData: document.getElementById('columns-data'),
                columnModal: document.getElementById('columnModal'),
                previewModal: document.getElementById('previewModal'),
                contentTypeSelect: document.getElementById('modal_content_type'),
                skinSelect: document.getElementById('modal_content_skin'),
                contentItemsContainer: document.getElementById('content_items_container')
            };

            // DualListbox 인스턴스
            this.dualListbox = null;
            // Plugin Custom UI 모드 인스턴스
            this.pluginSelector = null;
            // HTML 블록 전용 WYSIWYG 에디터 인스턴스
            this.blockHtmlVisualEditor = null;

            this.init();
        }

        /**
         * 초기화
         */
        init() {
            this.bindEvents();
            this.initColumnSortable();
            this.bindBlockHtmlLandingEditorEvents();
            this.bindHtmlAiEvents();
        }

        bindHtmlAiEvents() {
            const generate = document.getElementById('row_html_ai_generate');
            const undo = document.getElementById('row_html_ai_undo');
            if (!generate) return;
            const assetList = document.getElementById('row_html_ai_assets');
            const history = document.getElementById('row_html_ai_history');
            const fileInput = document.getElementById('row_html_ai_files');

            generate.addEventListener('click', async () => {
                if (this.htmlAiAbort) return;
                if (!this.rowId) {
                    MubloRequest.showAlert('행을 먼저 저장한 뒤 AI를 사용할 수 있습니다.', 'warning');
                    return;
                }
                if (!this.htmlAiReady) {
                    MubloRequest.showAlert('AI 설정에서 API 키를 등록하고 기능을 활성화해주세요.', 'warning');
                    return;
                }
                const prompt = document.getElementById('row_html_ai_prompt').value.trim();
                if (!prompt) {
                    MubloRequest.showAlert('AI에게 요청할 내용을 입력해주세요.', 'warning');
                    return;
                }
                const columnIndex = Number(document.getElementById('modalColumnIndex').value);
                const editor = this.blockHtmlVisualEditor
                    || (typeof BlockHtmlEditor !== 'undefined' ? BlockHtmlEditor.getVisual?.('modal_html_content') : null);
                editor?.sync?.();
                const currentHtml = editor ? editor.getHTML() : (document.getElementById('modal_html_content')?.value || '');
                const currentCss = document.getElementById('modal_html_css')?.value || '';
                const currentJs = document.getElementById('modal_html_js')?.value || '';
                const status = document.getElementById('row_html_ai_status');
                const fd = new FormData();
                fd.append('_token', this.csrfToken);
                fd.append('row_id', this.rowId);
                fd.append('column_index', columnIndex);
                fd.append('mode', document.getElementById('row_html_ai_mode').value);
                fd.append('prompt', prompt);
                fd.append('current_html', currentHtml);
                fd.append('current_css', currentCss);
                fd.append('current_js', currentJs);
                fd.append('asset_ids', JSON.stringify(Array.from(this.htmlAiSelectedAssets)));

                this.htmlAiAbort = new AbortController();
                const normalButtonHtml = generate.innerHTML;
                let generationSucceeded = false;
                generate.disabled = true;
                generate.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> 생성 중입니다';
                status.textContent = '';
                try {
                    const response = await fetch('/admin/block-editor/ai-html', {
                        method: 'POST', body: fd, signal: this.htmlAiAbort.signal
                    });
                    const json = await this.readHtmlAiJson(response);
                    if (!json.success) throw new Error(json.message || 'HTML을 생성하지 못했습니다.');
                    this.htmlAiUndo = { html: currentHtml, css: currentCss, js: currentJs };
                    const result = json.data || {};
                    document.getElementById('modal_html_content').value = result.html || '';
                    document.getElementById('modal_html_css').value = result.css || '';
                    document.getElementById('modal_html_js').value = result.js || '';
                    editor?.setHTML?.(result.html || '');
                    editor?.injectCss?.(result.css || '');
                    undo.style.display = '';
                    status.textContent = result.notes || '검토 후 모달의 적용 버튼을 눌러주세요.';
                    generationSucceeded = true;
                    await this.loadHtmlAiLibrary();
                } catch (error) {
                    if (error.name !== 'AbortError') MubloRequest.showAlert(error.message || 'AI 요청에 실패했습니다.', 'error');
                    status.textContent = error.name === 'AbortError' ? '취소됨' : '';
                } finally {
                    this.htmlAiAbort = null;
                    generate.innerHTML = generationSucceeded
                        ? '<i class="bi bi-arrow-repeat"></i> 다시 생성'
                        : normalButtonHtml;
                    this.updateHtmlAiAvailability();
                }
            });

            undo.addEventListener('click', () => {
                if (!this.htmlAiUndo) return;
                const editor = this.blockHtmlVisualEditor
                    || (typeof BlockHtmlEditor !== 'undefined' ? BlockHtmlEditor.getVisual?.('modal_html_content') : null);
                document.getElementById('modal_html_content').value = this.htmlAiUndo.html;
                document.getElementById('modal_html_css').value = this.htmlAiUndo.css;
                document.getElementById('modal_html_js').value = this.htmlAiUndo.js;
                editor?.setHTML?.(this.htmlAiUndo.html);
                editor?.injectCss?.(this.htmlAiUndo.css);
                this.htmlAiUndo = null;
                undo.style.display = 'none';
                document.getElementById('row_html_ai_status').textContent = 'AI 적용 전 내용으로 되돌렸습니다.';
            });

            assetList?.addEventListener('click', async (event) => {
                const select = event.target.closest('[data-row-ai-select]');
                if (select) {
                    const id = Number(select.dataset.rowAiSelect);
                    this.htmlAiSelectedAssets.has(id) ? this.htmlAiSelectedAssets.delete(id) : this.htmlAiSelectedAssets.add(id);
                    this.renderHtmlAiLibrary(); return;
                }
                const edit = event.target.closest('[data-row-ai-edit]');
                if (edit) {
                    await this.postHtmlAiAsset('/admin/block-editor/ai-asset-edit', {asset_id: edit.dataset.id, operation: edit.dataset.rowAiEdit});
                    await this.loadHtmlAiLibrary(); return;
                }
                const del = event.target.closest('[data-row-ai-delete]');
                if (del && window.confirm('이 AI 자료를 삭제할까요?')) {
                    await this.postHtmlAiAsset('/admin/block-editor/ai-asset-delete', {asset_id: del.dataset.rowAiDelete});
                    this.htmlAiSelectedAssets.delete(Number(del.dataset.rowAiDelete)); await this.loadHtmlAiLibrary();
                }
            });
            history?.addEventListener('click', (event) => {
                const button = event.target.closest('[data-row-ai-history]'); if (!button) return;
                const record = this.htmlAiRecords.find(item => Number(item.record_id) === Number(button.dataset.rowAiHistory)); if (!record) return;
                document.getElementById('row_html_ai_prompt').value = record.prompt || '';
                document.getElementById('row_html_ai_mode').value = record.mode === 'modify' ? 'modify' : 'create';
                this.htmlAiSelectedAssets = new Set((record.asset_ids || []).filter(id => this.htmlAiAssets.some(asset => asset.id === id)));
                this.renderHtmlAiLibrary(); document.getElementById('row_html_ai_status').textContent = '생성 기록의 프롬프트와 자료 선택을 불러왔습니다.';
            });
            fileInput?.addEventListener('change', async (event) => {
                const files = Array.from(event.target.files || []); if (!files.length) return;
                const fd = new FormData(); fd.append('_token', this.csrfToken); files.forEach(file => fd.append('files[]', file));
                try {
                    const response = await fetch('/admin/block-editor/ai-assets-upload', {method:'POST', body:fd}); const json = await this.readHtmlAiJson(response);
                    if (!json.success) throw new Error(json.message); (json.data || []).forEach(asset => this.htmlAiSelectedAssets.add(asset.id));
                    MubloRequest.showToast(json.message || 'AI 자료가 저장되었습니다.', 'success'); await this.loadHtmlAiLibrary();
                } catch (error) { MubloRequest.showAlert(error.message || '자료를 저장하지 못했습니다.', 'error'); }
                finally { event.target.value = ''; }
            });

            window.addEventListener('focus', () => {
                const modal = document.getElementById('columnModal');
                if (modal?.classList.contains('show') && !this.htmlAiAbort) this.loadHtmlAiLibrary();
            });
        }

        async loadHtmlAiLibrary() {
            const target = document.getElementById('row_html_ai_assets'); if (!target) return;
            try {
                const response = await fetch('/admin/block-editor/ai-assets'); const json = await this.readHtmlAiJson(response);
                if (!json.success) throw new Error(json.message); this.htmlAiAssets = json.data?.assets || []; this.htmlAiRecords = json.data?.records || [];
                this.htmlAiReady = !!json.data?.ai_ready;
                this.updateHtmlAiAvailability();
                this.renderHtmlAiLibrary();
            } catch (error) { target.innerHTML = `<span class="text-danger small">${this.escapeHtmlAi(error.message)}</span>`; }
        }

        updateHtmlAiAvailability() {
            const generate = document.getElementById('row_html_ai_generate');
            const settings = document.getElementById('row_html_ai_settings');
            const status = document.getElementById('row_html_ai_status');
            if (!generate || !settings || !status) return;
            generate.disabled = !!this.htmlAiAbort || !this.rowId || !this.htmlAiReady;
            settings.textContent = this.htmlAiReady ? 'AI 설정' : 'AI 설정 필요';
            settings.classList.toggle('text-danger', !this.htmlAiReady);
            if (!this.rowId) status.textContent = '행을 먼저 저장한 뒤 사용할 수 있습니다.';
            else if (!this.htmlAiReady) status.textContent = 'AI API 키를 등록하고 기능을 활성화해주세요.';
            else if (status.textContent === 'AI API 키를 등록하고 기능을 활성화해주세요.') status.textContent = '';
        }

        renderHtmlAiLibrary() {
            const target = document.getElementById('row_html_ai_assets'); if (!target) return;
            target.innerHTML = this.htmlAiAssets.length ? this.htmlAiAssets.map(asset => `
                <div class="d-flex align-items-center gap-2 border rounded p-1 ${this.htmlAiSelectedAssets.has(asset.id) ? 'border-primary bg-primary-subtle' : ''}">
                    ${asset.kind === 'image' ? `<img src="${this.escapeHtmlAi(asset.preview_url || '')}" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:5px;">` : '<span class="d-grid place-items-center bg-body-tertiary rounded" style="width:38px;height:38px;"><i class="bi bi-file-earmark-text"></i></span>'}
                    <button type="button" class="btn btn-link p-0 text-start text-decoration-none flex-grow-1 overflow-hidden" data-row-ai-select="${asset.id}"><span class="d-block text-truncate small">${this.escapeHtmlAi(asset.title || asset.name)}</span><small class="text-muted">${this.escapeHtmlAi(String(asset.extension).toUpperCase())}</small></button>
                    <span class="dropdown"><button class="btn btn-sm btn-link text-muted" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button><span class="dropdown-menu dropdown-menu-end">${asset.kind === 'image' ? `<button class="dropdown-item" data-row-ai-edit="rotate_left" data-id="${asset.id}">왼쪽 회전 복사</button><button class="dropdown-item" data-row-ai-edit="rotate_right" data-id="${asset.id}">오른쪽 회전 복사</button><button class="dropdown-item" data-row-ai-edit="flip_horizontal" data-id="${asset.id}">좌우 반전 복사</button><button class="dropdown-item" data-row-ai-edit="crop_square" data-id="${asset.id}">가운데 정사각형 자르기</button><button class="dropdown-item" data-row-ai-edit="crop_16_9" data-id="${asset.id}">가운데 16:9 자르기</button><button class="dropdown-item" data-row-ai-edit="resize_half" data-id="${asset.id}">50% 크기 복사</button>` : ''}<button class="dropdown-item text-danger" data-row-ai-delete="${asset.id}">삭제</button></span></span>
                </div>`).join('') : '<span class="text-muted small">저장된 자료가 없습니다.</span>';
            const history = document.getElementById('row_html_ai_history');
            if (history) history.innerHTML = this.htmlAiRecords.length ? this.htmlAiRecords.map(record => `<button type="button" class="btn btn-sm btn-outline-secondary text-start" data-row-ai-history="${record.record_id}"><span class="d-block text-truncate">${this.escapeHtmlAi(record.prompt)}</span><small>${this.escapeHtmlAi(record.model)} · ${this.escapeHtmlAi(record.created_at || '')}</small></button>`).join('') : '<span class="text-muted small">생성 기록이 없습니다.</span>';
        }

        async postHtmlAiAsset(url, values) {
            const fd = new FormData(); fd.append('_token', this.csrfToken); Object.entries(values).forEach(([key,value]) => fd.append(key, value));
            try { const response = await fetch(url, {method:'POST', body:fd}); const json = await this.readHtmlAiJson(response); if (!json.success) throw new Error(json.message); MubloRequest.showToast(json.message || '처리되었습니다.', 'success'); return json.data; }
            catch (error) { MubloRequest.showAlert(error.message || 'AI 자료를 처리하지 못했습니다.', 'error'); throw error; }
        }

        escapeHtmlAi(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        }

        async readHtmlAiJson(response) {
            const text = await response.text();
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(`AI 서버가 JSON이 아닌 응답을 반환했습니다. (HTTP ${response.status})`);
            }
            try { return JSON.parse(text); }
            catch (error) { throw new Error('AI 서버 응답 형식을 확인할 수 없습니다.'); }
        }

        /**
         * 이벤트 바인딩
         */
        bindEvents() {
            // 칸 수 변경
            if (this.elements.columnCount) {
                this.elements.columnCount.addEventListener('change', (e) => this.onColumnCountChange(e));
            }

            // 칸 간격 변경 시 프리뷰 gap 업데이트
            const columnMarginInput = document.querySelector('input[name="formData[column_margin]"]');
            if (columnMarginInput) {
                columnMarginInput.addEventListener('input', () => this.updatePreviewGap());
            }

            // 콘텐츠 타입 변경
            if (this.elements.contentTypeSelect) {
                this.elements.contentTypeSelect.addEventListener('change', async (e) => {
                    const contentType = e.target.value;
                    await this.ensureEditorAdapter(contentType);
                    this.toggleHtmlEditor(contentType);
                    this.updateSkinList(contentType);
                    this.loadContentItems(contentType);
                });
            }

            // 스킨 변경 — 스킨이 권장하는 1줄 출력갯수(skin.json)를 자동 반영
            if (this.elements.skinSelect) {
                this.elements.skinSelect.addEventListener('change', () => {
                    this.applySkinRecommendedCols();
                    this.updateSkinRecommendHint();
                });
            }

            // 제목 표시 체크박스 변경
            const titleShowCheckbox = document.getElementById('modal_title_show');
            if (titleShowCheckbox) {
                titleShowCheckbox.addEventListener('change', (e) => {
                    this.toggleTitleDetailWrapper(e.target.checked);
                });
            }

            // 출력갯수 변경 → DualListbox/Plugin maxItems 동기화
            const countPcInput = document.getElementById('modal_content_count_pc');
            const countMoInput = document.getElementById('modal_content_count_mo');
            const syncMaxItems = () => {
                const max = this.getMaxItemCount();
                if (this.dualListbox && typeof this.dualListbox.setMaxItems === 'function') {
                    this.dualListbox.setMaxItems(max);
                }
                if (this.pluginSelector && this.pluginSelector._dualListbox
                    && typeof this.pluginSelector._dualListbox.setMaxItems === 'function') {
                    this.pluginSelector._dualListbox.setMaxItems(max);
                }
            };
            if (countPcInput) countPcInput.addEventListener('change', syncMaxItems);
            if (countMoInput) countMoInput.addEventListener('change', syncMaxItems);

            // 이미지 추가 버튼
            const addImageBtn = document.getElementById('btn_add_image');
            if (addImageBtn) {
                addImageBtn.addEventListener('click', () => this.addImageItem());
            }

            // 칸 배경: 컬러 픽커 ↔ 텍스트 동기화
            const modalBgPicker = document.getElementById('modal_bg_color_picker');
            const modalBgText = document.getElementById('modal_bg_color');
            if (modalBgPicker && modalBgText) {
                modalBgPicker.addEventListener('input', () => {
                    modalBgText.value = modalBgPicker.value;
                });
                modalBgText.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(modalBgText.value)) {
                        modalBgPicker.value = modalBgText.value;
                    }
                });
            }

            // 제목 색상: 컬러 픽커 ↔ 텍스트 동기화
            this.initColorSync('modal_title_color_picker', 'modal_title_color');
            // 문구 색상: 컬러 픽커 ↔ 텍스트 동기화
            this.initColorSync('modal_copytext_color_picker', 'modal_copytext_color');

            // 칸 배경: 이미지 파일 선택 시 미리보기 + pendingFiles 저장
            const modalBgImageFile = document.getElementById('modal_bg_image_file');
            if (modalBgImageFile) {
                modalBgImageFile.addEventListener('change', (e) => this.handleModalBgImageChange(e));
            }

            // 칸 배경: 이미지 삭제 체크박스
            const modalBgImageDel = document.getElementById('modal_bg_image_del');
            if (modalBgImageDel) {
                modalBgImageDel.addEventListener('change', (e) => {
                    if (e.target.checked) {
                        this._modalBgImageDeleted = true;
                        document.getElementById('modal_bg_image').value = '';
                        this.toggleModalBgImageOptions();
                    } else {
                        this._modalBgImageDeleted = false;
                        // 기존 URL 복원
                        document.getElementById('modal_bg_image').value = this._modalBgImageOriginal || '';
                        this.toggleModalBgImageOptions();
                    }
                });
            }

            // 제목 이미지: 파일 선택 시 미리보기
            ['pc', 'mo'].forEach(type => {
                const fileInput = document.getElementById(`modal_title_${type}_image_file`);
                if (fileInput) {
                    fileInput.addEventListener('change', (e) => {
                        const file = e.target.files[0];
                        if (!file) return;
                        if (type === 'pc') this._titlePcPendingFile = file;
                        else this._titleMoPendingFile = file;
                        const previewDiv = document.getElementById(`modal_title_${type}_image_preview`);
                        const previewImg = previewDiv?.querySelector('img');
                        if (previewImg) previewImg.src = URL.createObjectURL(file);
                        if (previewDiv) previewDiv.style.display = '';
                        const delCheck = document.getElementById(`modal_title_${type}_image_del`);
                        if (delCheck) delCheck.checked = false;
                    });
                }
                const delCheck = document.getElementById(`modal_title_${type}_image_del`);
                if (delCheck) {
                    delCheck.addEventListener('change', (e) => {
                        if (e.target.checked) {
                            document.getElementById(`modal_title_${type}_image`).value = '';
                            if (type === 'pc') this._titlePcPendingFile = null;
                            else this._titleMoPendingFile = null;
                        }
                    });
                }
            });

            // 슬라이드 옵션 토글: 스타일 변경 시
            const pcStyleSelect = document.getElementById('modal_pc_style');
            const moStyleSelect = document.getElementById('modal_mo_style');
            if (pcStyleSelect) pcStyleSelect.addEventListener('change', () => this.toggleSlideOptions());
            if (moStyleSelect) moStyleSelect.addEventListener('change', () => this.toggleSlideOptions());

            // autoplay 체크박스 → delay input 활성/비활성
            const pcAutoCheck = document.getElementById('modal_pc_autoplay_check');
            const moAutoCheck = document.getElementById('modal_mo_autoplay_check');
            if (pcAutoCheck) pcAutoCheck.addEventListener('change', (e) => {
                document.getElementById('modal_pc_autoplay_delay').disabled = !e.target.checked;
            });
            if (moAutoCheck) moAutoCheck.addEventListener('change', (e) => {
                document.getElementById('modal_mo_autoplay_delay').disabled = !e.target.checked;
            });
        }

        /**
         * 슬라이드 옵션 표시/숨김 토글
         */
        toggleSlideOptions() {
            const pcStyle = document.getElementById('modal_pc_style')?.value || 'list';
            const moStyle = document.getElementById('modal_mo_style')?.value || 'list';

            const pcOpts = document.getElementById('pc_slide_options');
            if (pcOpts) pcOpts.style.display = pcStyle === 'slide' ? '' : 'none';

            const moOpts = document.getElementById('mo_slide_options');
            if (moOpts) moOpts.style.display = moStyle === 'slide' ? '' : 'none';
        }

        /**
         * 컬러 픽커 ↔ 텍스트 입력 동기화 헬퍼
         */
        initColorSync(pickerId, textId) {
            const picker = document.getElementById(pickerId);
            const text = document.getElementById(textId);
            if (!picker || !text) return;
            picker.addEventListener('input', () => { text.value = picker.value; });
            text.addEventListener('input', () => {
                if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) picker.value = text.value;
            });
        }

        /**
         * 칸 배경 이미지 파일 선택 핸들러
         */
        handleModalBgImageChange(e) {
            const input = e.target;
            if (!input.files || !input.files[0]) return;

            const file = input.files[0];
            const previewDiv = document.getElementById('modal_bg_image_preview');
            const previewImg = previewDiv?.querySelector('img');

            // FileReader로 미리보기
            const reader = new FileReader();
            reader.onload = (event) => {
                if (previewImg) {
                    previewImg.src = event.target.result;
                }
                if (previewDiv) {
                    previewDiv.classList.remove('d-none');
                }
            };
            reader.readAsDataURL(file);

            // pendingFiles에 저장
            if (!this.pendingFiles) this.pendingFiles = {};
            this._modalBgPendingFile = file;

            // 삭제 체크박스 해제
            const delCheckbox = document.getElementById('modal_bg_image_del');
            if (delCheckbox) delCheckbox.checked = false;
            this._modalBgImageDeleted = false;

            // 이미지 옵션 표시
            this.toggleModalBgImageOptions(true);
        }

        /**
         * 칸 배경 이미지 옵션 표시/숨김
         */
        toggleModalBgImageOptions(forceShow) {
            const hasImage = forceShow || document.getElementById('modal_bg_image')?.value || this._modalBgPendingFile;
            const isDeleted = this._modalBgImageDeleted;
            const optionsEl = document.getElementById('modal_bg_image_options');
            if (optionsEl) {
                optionsEl.classList.toggle('d-none', !(hasImage && !isDeleted));
            }
        }

        /**
         * 칸 배경 이미지 파일을 메인 폼에 동적 file input으로 첨부
         */
        attachBgFileToForm(columnIndex) {
            const btn = document.querySelector('.mublo-submit');
            const form = btn ? btn.closest('form') : document.querySelector('form');
            if (!form) return;

            // 기존 동적 file input 제거
            const existingInput = form.querySelector(`input[name="column_bg_image[${columnIndex}]"]`);
            if (existingInput) existingInput.remove();

            if (this._modalBgPendingFile) {
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.name = `column_bg_image[${columnIndex}]`;
                fileInput.style.display = 'none';

                const dt = new DataTransfer();
                dt.items.add(this._modalBgPendingFile);
                fileInput.files = dt.files;

                form.appendChild(fileInput);
            }
        }

        /**
         * 제목 이미지 미리보기 로드
         */
        loadTitleImagePreview(type, imageUrl) {
            const fileInput = document.getElementById(`modal_title_${type}_image_file`);
            const hiddenInput = document.getElementById(`modal_title_${type}_image`);
            const previewDiv = document.getElementById(`modal_title_${type}_image_preview`);
            const previewImg = previewDiv?.querySelector('img');
            const delCheck = document.getElementById(`modal_title_${type}_image_del`);

            if (fileInput) fileInput.value = '';
            if (hiddenInput) hiddenInput.value = imageUrl;
            if (delCheck) delCheck.checked = false;

            if (imageUrl) {
                if (previewImg) previewImg.src = imageUrl;
                if (previewDiv) previewDiv.style.display = '';
            } else {
                if (previewDiv) previewDiv.style.display = 'none';
            }
        }

        /**
         * 제목 이미지 파일을 메인 폼에 동적 file input으로 첨부
         */
        attachTitleImageFilesToForm(columnIndex) {
            const btn = document.querySelector('.mublo-submit');
            const form = btn ? btn.closest('form') : document.querySelector('form');
            if (!form) return;

            ['pc', 'mo'].forEach(type => {
                const inputName = `column_title_image[${columnIndex}][${type}]`;
                const existing = form.querySelector(`input[name="${inputName}"]`);
                if (existing) existing.remove();

                const file = type === 'pc' ? this._titlePcPendingFile : this._titleMoPendingFile;
                if (file) {
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.name = inputName;
                    fileInput.style.display = 'none';
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    fileInput.files = dt.files;
                    form.appendChild(fileInput);
                }
            });
        }

        /**
         * 제목 상세 설정 영역 표시/숨김
         */
        toggleTitleDetailWrapper(show) {
            const wrapper = document.getElementById('title_detail_wrapper');
            if (wrapper) {
                wrapper.style.display = show ? 'block' : 'none';
            }
        }

        /**
         * 콘텐츠 타입별 아이템 목록 로드
         *
         * 2-모드 시스템:
         * 1. DualListbox 모드 (기본) — AJAX로 아이템 목록 조회 → Core DualListbox UI
         * 2. Custom UI 모드 (고급) — Plugin JS 로드 → Plugin이 UI 전체 소유
         */
        async loadContentItems(contentType, selectedItems = []) {
            const container = this.elements.contentItemsContainer;
            if (!container) return;

            const typeInfo = this.contentTypes.find(ct => ct.value === contentType);
            const capabilities = MubloBlockCapabilities.forType(typeInfo);

            // 아이템 선택 불필요
            if (!capabilities.items && !capabilities.customConfig) {
                container.style.display = 'none';
                this.destroyCurrentSelector();
                return;
            }

            container.style.display = 'block';
            this.updateItemLimitHint();

            // Custom UI 모드: Plugin JS 로드
            if (typeInfo.adminScript) {
                await this.loadPluginItemSelector(typeInfo, selectedItems);
                return;
            }

            // DualListbox 모드 (기존 흐름)
            const listContainer = container.querySelector('.dual-listbox-wrapper');
            if (listContainer) {
                listContainer.innerHTML = '<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> 목록 로딩 중...</div>';
            }

            try {
                const response = await MubloRequest.requestJson(
                    `/admin/block-row/get-content-items?content_type=${contentType}`
                );

                if (response.success && response.data && response.data.items) {
                    this.destroyCurrentSelector();
                    this.initDualListbox(response.data.items, selectedItems);
                } else {
                    if (listContainer) {
                        listContainer.innerHTML = '<p class="text-muted">선택 가능한 아이템이 없습니다.</p>';
                    }
                }
            } catch (error) {
                console.error('아이템 로드 실패:', error);
                if (listContainer) {
                    listContainer.innerHTML = '<p class="text-danger">아이템을 불러오는데 실패했습니다.</p>';
                }
            }
        }

        /**
         * DualListbox 초기화
         */
        initDualListbox(items, selectedIds = []) {
            const container = this.elements.contentItemsContainer?.querySelector('.dual-listbox-wrapper');
            if (!container) return;

            this.dualListbox = new DualListbox(container, {
                available: items,
                selected: selectedIds,
                maxItems: this.getMaxItemCount(),
                leftTitle: '사용 가능',
                rightTitle: '선택됨',
                onChanged: (selected) => {
                    // 선택 변경 시 hidden input 업데이트 (선택사항)
                    console.log('선택된 아이템:', selected);
                }
            });
        }

        /**
         * Plugin Custom UI 모드 — Plugin JS 로드 후 init() 호출
         */
        async loadPluginItemSelector(typeInfo, selectedItems) {
            const container = this.elements.contentItemsContainer;
            const listContainer = container?.querySelector('.dual-listbox-wrapper');
            if (!listContainer) return;

            listContainer.innerHTML = '<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> 플러그인 UI 로딩 중...</div>';

            try {
                await this.ensureScript(typeInfo.adminScript);

                const initName = typeInfo.adminScriptInit;
                const pluginModule = initName ? window[initName] : null;

                if (!pluginModule || typeof pluginModule.init !== 'function') {
                    listContainer.innerHTML = '<p class="text-danger">플러그인 아이템 선택기를 초기화할 수 없습니다.</p>';
                    return;
                }

                this.destroyCurrentSelector();
                listContainer.innerHTML = '';

                pluginModule.init(listContainer, {
                    selectedItems: selectedItems,
                    domainId: this.domainId,
                    maxItems: this.getMaxItemCount(),
                    config: this._currentContentConfig || {}
                });

                this.pluginSelector = pluginModule;
            } catch (error) {
                console.error('플러그인 아이템 선택기 로드 실패:', error);
                if (listContainer) {
                    listContainer.innerHTML = '<p class="text-danger">플러그인 UI를 불러오는데 실패했습니다.</p>';
                }
            }
        }

        /**
         * 현재 선택기 정리 (DualListbox / Plugin 모두)
         */
        destroyCurrentSelector() {
            if (this.pluginSelector && typeof this.pluginSelector.destroy === 'function') {
                this.pluginSelector.destroy();
            }
            if (this.dualListbox && typeof this.dualListbox.destroy === 'function') {
                this.dualListbox.destroy();
            }
            this.pluginSelector = null;
            this.dualListbox = null;
        }

        /**
         * 최대 선택 가능 아이템 수 산출
         *
         * 콘텐츠 타입이 maxItems 를 선언했으면 그 값이 우선한다(출력갯수와 무관).
         * 아이템이 곧 데이터 소스인 타입(게시판 등)은 "출력갯수 = 글 개수"라 축이 다르므로,
         * 출력갯수를 선택 수 상한으로 쓰면 게시판을 3개 고를 수 있는 것처럼 보이는 거짓 신호가 된다.
         *
         * 미선언 타입은 기존대로 출력갯수(pc/mo 중 큰 값)를 따른다 — 아이템이 곧 출력 단위인
         * 진열형 타입(이미지 등)에는 그게 맞다.
         */
        getMaxItemCount() {
            const declared = this.getDeclaredMaxItems();
            if (declared !== null) return declared;

            const pc = parseInt(document.getElementById('modal_content_count_pc')?.value) || 0;
            const mo = parseInt(document.getElementById('modal_content_count_mo')?.value) || 0;
            return Math.max(pc, mo);
        }

        /**
         * 선택 수 제한 안내문 갱신
         *
         * 출력갯수와 무관하게 고정된 제한은 화면만 봐서는 알 수 없으므로 명시한다.
         */
        updateItemLimitHint() {
            const hint = document.getElementById('content_items_limit_hint');
            if (!hint) return;

            const declared = this.getDeclaredMaxItems();
            hint.textContent = declared && declared > 0
                ? ` 이 콘텐츠 타입은 ${declared}개만 선택할 수 있습니다 (출력갯수와 무관).`
                : '';
        }

        /**
         * 현재 콘텐츠 타입이 선언한 maxItems (미선언이면 null)
         */
        getDeclaredMaxItems() {
            const contentType = this.elements.contentTypeSelect?.value;
            if (!contentType) return null;

            const typeInfo = this.contentTypes.find(ct => ct.value === contentType);
            return typeof typeInfo?.maxItems === 'number' ? typeInfo.maxItems : null;
        }

        /**
         * 스크립트 동적 로드 (Promise)
         */
        ensureScript(src) {
            return new Promise((resolve, reject) => {
                if (document.querySelector(`script[src="${src}"]`)) {
                    resolve();
                    return;
                }

                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = () => reject(new Error(`스크립트 로드 실패: ${src}`));
                document.head.appendChild(script);
            });
        }

        /**
         * 스킨 목록 업데이트
         */
        updateSkinList(contentType) {
            const skinSelect = this.elements.skinSelect;
            if (!skinSelect) return;

            const skinWrapper = document.getElementById('content_skin_wrapper');
            const typeInfo = this.contentTypes.find(ct => ct.value === contentType);
            const capabilities = MubloBlockCapabilities.forType(typeInfo);

            if (!capabilities.skin) {
                if (skinWrapper) skinWrapper.style.display = 'none';
                skinSelect.innerHTML = '<option value="">스킨 없음</option>';
                this.currentSkinListType = '';
                this.updateSkinRecommendHint();
                return;
            }

            // 스킨 선택 표시
            if (skinWrapper) skinWrapper.style.display = '';

            // 해당 콘텐츠 타입의 스킨 목록 가져오기
            const skins = this.skinLists[contentType] || [];

            // 옵션 생성
            skinSelect.innerHTML = '';

            if (skins.length === 0) {
                skinSelect.innerHTML = '<option value="">스킨 없음</option>';
            } else {
                skins.forEach(skin => {
                    const option = document.createElement('option');
                    option.value = skin.value;
                    option.textContent = skin.label;
                    skinSelect.appendChild(option);
                });
            }

            this.currentSkinListType = contentType;
            this.updateSkinRecommendHint();
        }

        /**
         * 현재 선택된 스킨의 메타(skin.json) 조회
         */
        getSelectedSkinMeta() {
            const type = this.currentSkinListType || this.elements.contentTypeSelect?.value || '';
            const list = this.skinLists[type] || [];
            const value = this.elements.skinSelect?.value || '';
            return list.find(skin => skin.value === value) || null;
        }

        /**
         * 스킨 셀렉트 아래 권장 1줄 출력갯수 힌트 + [적용] 버튼 표시
         * (스킨이 하나뿐이면 change 가 발생하지 않으므로 버튼이 수동 반영 통로)
         */
        updateSkinRecommendHint() {
            const skinSelect = this.elements.skinSelect;
            if (!skinSelect) return;

            let hint = document.getElementById('modal_skin_hint');
            if (!hint) {
                hint = document.createElement('div');
                hint.id = 'modal_skin_hint';
                hint.style.cssText = 'font-size:11.5px;color:var(--bs-secondary-color);margin-top:4px;';
                skinSelect.insertAdjacentElement('afterend', hint);
            }

            const rec = this.getSelectedSkinMeta()?.recommended_cols || null;
            const parts = [];
            if (rec?.pc) parts.push('PC ' + rec.pc + '개');
            if (rec?.mo) parts.push('모바일 ' + rec.mo + '개');

            if (!parts.length) {
                hint.textContent = '';
                return;
            }

            hint.innerHTML = '권장 1줄 출력갯수: ' + parts.join(' · ') +
                ' <button type="button" class="btn btn-outline-primary btn-sm" id="modal_skin_apply"' +
                ' style="padding:0 8px;font-size:11px;margin-left:4px;line-height:1.6;">적용</button>';
            document.getElementById('modal_skin_apply')?.addEventListener('click', () => this.applySkinRecommendedCols());
        }

        /**
         * 스킨 권장 1줄 출력갯수 자동 반영 — 스킨 '변경' 시에만 호출
         * (저장된 블록을 다시 열 때는 저장값 유지. 열수 필드가 없는 타입은 무시)
         */
        applySkinRecommendedCols() {
            const rec = this.getSelectedSkinMeta()?.recommended_cols || null;
            if (!rec) return;

            const pcCols = document.getElementById('modal_pc_cols');
            const moCols = document.getElementById('modal_mo_cols');
            if (pcCols && rec.pc) pcCols.value = String(rec.pc);
            if (moCols && rec.mo) moCols.value = String(rec.mo);
        }

        /**
         * 칸 간격 변경 시 프리뷰 gap 및 칸 너비 재계산
         */
        updatePreviewGap() {
            const preview = this.elements.columnsPreview;
            const margin = parseInt(document.querySelector('input[name="formData[column_margin]"]')?.value) || 0;
            const count = parseInt(this.elements.columnCount?.value) || 1;
            const gapTotal = margin * (count - 1);

            preview.style.gap = margin + 'px';

            preview.querySelectorAll('.column-preview-item').forEach((card, i) => {
                const widthInput = this.elements.columnsData.querySelector(`input[name="columns[${i}][width]"]`);
                const colWidth = widthInput?.value || '';
                card.style.cssText = colWidth
                    ? `flex: 0 0 calc(${colWidth} - ${gapTotal}px / ${count}); min-width: 150px;`
                    : 'flex: 1; min-width: 200px;';
            });
        }

        /**
         * 칸 수 변경 핸들러
         */
        onColumnCountChange(e) {
            const count = parseInt(e.target.value);
            const preview = this.elements.columnsPreview;
            const dataContainer = this.elements.columnsData;

            // 기존 칸 데이터 저장
            const existingData = [];
            for (let i = 0; i < 4; i++) {
                const input = dataContainer.querySelector(`input[name="columns[${i}][content_type]"]`);
                if (input) {
                    existingData[i] = this.getColumnData(i);
                }
            }

            // 프리뷰 재생성 - gap 반영
            const columnMargin = parseInt(document.querySelector('input[name="formData[column_margin]"]')?.value) || 0;
            preview.style.gap = columnMargin + 'px';
            preview.innerHTML = '';
            dataContainer.innerHTML = '';

            for (let i = 0; i < count; i++) {
                const data = existingData[i] || {};
                preview.appendChild(this.buildColumnCard(i, data, count, columnMargin));
                this.setColumnHiddenInputs(dataContainer, i, data);
            }
        }

        /**
         * 칸 프리뷰 카드 1개 생성 (onColumnCountChange / reorderColumns 공용)
         */
        buildColumnCard(i, data, count, columnMargin) {
            data = data || {};
            const card = document.createElement('div');
            card.className = 'column-preview-item card';
            const colWidth = data.width || '';
            const gapTotal = columnMargin * (count - 1);
            card.style.cssText = colWidth
                ? `flex: 0 0 calc(${colWidth} - ${gapTotal}px / ${count}); min-width: 150px;`
                : 'flex: 1; min-width: 200px;';
            card.dataset.index = i;

            const contentLabel = this.getContentTypeLabel(data.content_type);
            const widthBadge = colWidth
                ? `<span class="column-width-badge badge bg-info ms-1">${colWidth}</span>`
                : '';

            card.innerHTML = `
                    <div class="card-body text-center">
                        <h6 class="card-title">${i + 1}번째 칸${widthBadge}</h6>
                        <p class="card-text">
                            <span class="column-type-badge badge ${data.content_type ? 'bg-primary' : 'bg-secondary'}">${contentLabel || '미설정'}</span>
                        </p>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-column-index="${i}">
                            ${data.content_type ? '수정' : '설정'}
                        </button>
                    </div>
                `;

            card.querySelector('button').addEventListener('click', () => this.openColumnModal(i));
            return card;
        }

        /**
         * 칸 프리뷰 드래그 정렬 초기화 (Sortable)
         */
        initColumnSortable() {
            const preview = this.elements.columnsPreview;
            if (!preview || typeof Sortable === 'undefined') return;
            Sortable.create(preview, {
                animation: 150,
                draggable: '.column-preview-item',
                // 버튼 클릭·입력·스택 콘텐츠 목록(내부 Sortable 영역)은 카드 드래그로 잡지 않음
                filter: 'button, input, .stack-rows',
                preventOnFilter: false,
                onEnd: () => setTimeout(() => this.reorderColumns(), 0)
            });
        }

        /**
         * 드래그로 바뀐 카드 순서를 소스(히든 인풋)에 반영 + 0..n-1 재인덱싱.
         * 미저장 이미지 파일 인풋(column_bg_image 등)도 새 인덱스로 매핑.
         */
        reorderColumns() {
            const preview = this.elements.columnsPreview;
            const dataContainer = this.elements.columnsData;

            // Sortable은 카드(.column-preview-item)만 이동 → data-index는 아직 기존값.
            // 현재 DOM 순서가 곧 새 순서다.
            const cards = Array.from(preview.querySelectorAll('.column-preview-item'));
            const oldToNew = {};
            cards.forEach((card, newIdx) => { oldToNew[parseInt(card.dataset.index, 10)] = newIdx; });

            // 새 순서로 칸 데이터 수집(기존 인덱스 기준) → 히든 인풋만 0..n-1로 재구축
            const ordered = cards.map(card => this.getColumnData(parseInt(card.dataset.index, 10)));
            dataContainer.innerHTML = '';
            ordered.forEach((data, i) => this.setColumnHiddenInputs(dataContainer, i, data));

            // 카드는 이미 이동됨(배지·너비·스타일 그대로) → 인덱스 의존분만 갱신:
            // data-index / "N번째 칸" 번호 / 버튼 onclick 인덱스
            cards.forEach((card, i) => {
                card.dataset.index = i;
                const title = card.querySelector('.card-title');
                if (title && title.firstChild) title.firstChild.nodeValue = (i + 1) + '번째 칸';
                const oldBtn = card.querySelector('button');
                if (oldBtn) {
                    const btn = oldBtn.cloneNode(true);   // 기존 리스너 제거
                    btn.dataset.columnIndex = i;
                    btn.addEventListener('click', () => this.openColumnModal(i));
                    oldBtn.parentNode.replaceChild(btn, oldBtn);
                }
            });

            // 미저장 이미지 파일 인풋도 새 인덱스로 이동
            this.remapColumnFileInputs(oldToNew);
        }

        /**
         * 인덱스 키를 가진 미저장 파일 인풋을 old→new 로 재매핑 (2-패스로 키 충돌 회피)
         */
        remapColumnFileInputs(oldToNew) {
            const form = this.elements.columnsData?.closest('form') || document.querySelector('form');
            if (!form) return;
            const re = /^(column_bg_image|column_images|column_title_image|column_content_images|column_content_title_images)\[(\d+)\](.*)$/;
            const targets = Array.from(form.querySelectorAll('input')).filter(inp => re.test(inp.name || ''));
            targets.forEach(inp => {
                const m = inp.name.match(re);
                const old = parseInt(m[2], 10);
                if (Object.prototype.hasOwnProperty.call(oldToNew, old)) {
                    inp.dataset.reidxName = `${m[1]}[${oldToNew[old]}]${m[3]}`;
                }
            });
            targets.forEach(inp => {
                if (inp.dataset.reidxName) { inp.name = inp.dataset.reidxName; delete inp.dataset.reidxName; }
            });
        }

        /**
         * 칸 데이터 가져오기
         */
        getColumnData(index) {
            const container = this.elements.columnsData;
            const get = (field) => {
                const input = container.querySelector(`input[name="columns[${index}][${field}]"]`);
                return input ? input.value : '';
            };

            const data = {
                width: get('width'),
                pc_padding: get('pc_padding'),
                mobile_padding: get('mobile_padding'),
                content_type: get('content_type'),
                content_kind: get('content_kind'),
                content_skin: get('content_skin'),
                background_config: get('background_config'),
                border_config: get('border_config'),
                title_config: get('title_config'),
                content_config: get('content_config'),
                content_items: get('content_items'),
                is_active: get('is_active')
            };

            // 콘텐츠 스택 필드 — 재정렬·칸 수 변경의 히든 인풋 재구축에서 소실되지 않게 함께 나른다
            ['column_id', 'content_mode', 'pc_content_gap', 'mobile_content_gap'].forEach((field) => {
                const value = get(field);
                if (value !== '') data[field] = value;
            });

            // 스택 하위 콘텐츠 인풋(columns[i][contents][j][field]) — 이름 접미사 그대로 보존
            data._stackInputs = [];
            const contentsPrefix = `columns[${index}][contents]`;
            container.querySelectorAll('input[type="hidden"]').forEach((inp) => {
                if ((inp.name || '').startsWith(contentsPrefix)) {
                    data._stackInputs.push({ suffix: inp.name.slice(contentsPrefix.length), value: inp.value });
                }
            });

            // 미리보기 등 payload 소비자용 — 서버 normalizer 형식(contents 배열)으로도 제공
            if (data.content_mode === 'stack' && window.BlockContentStack) {
                data.contents = window.BlockContentStack.readContents(index);
            }

            return data;
        }

        /**
         * Hidden input 설정
         *
         * DB 필드: width, pc_padding, mobile_padding, content_type, content_kind,
         *          content_skin, background_config, border_config, title_config,
         *          content_config, content_items, is_active
         */
        setColumnHiddenInputs(container, index, data) {
            const fields = [
                'width', 'pc_padding', 'mobile_padding', 'content_type', 'content_kind',
                'content_skin', 'background_config', 'border_config', 'title_config',
                'content_config', 'content_items', 'is_active'
            ];

            fields.forEach(field => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `columns[${index}][${field}]`;
                input.value = data[field] || (field === 'is_active' ? '1' : '');
                container.appendChild(input);
            });

            // 콘텐츠 스택 필드 복원 (getColumnData 가 나른 값)
            ['column_id', 'content_mode', 'pc_content_gap', 'mobile_content_gap'].forEach((field) => {
                if (data[field] === undefined || data[field] === '') return;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `columns[${index}][${field}]`;
                input.value = data[field];
                container.appendChild(input);
            });

            (data._stackInputs || []).forEach(({ suffix, value }) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `columns[${index}][contents]${suffix}`;
                input.value = value;
                container.appendChild(input);
            });
        }

        /**
         * 콘텐츠 타입 레이블 가져오기
         */
        getContentTypeLabel(type) {
            if (!type) return '';
            const found = this.contentTypes.find(ct => ct.value === type);
            return found ? found.label : type;
        }

        getContentTypeInfo(type) {
            return this.contentTypes.find(contentType => contentType.value === type) || null;
        }

        getEditorAdapter(type) {
            return this.getContentTypeInfo(type)?.editor?.adapter || '';
        }

        async ensureEditorAdapter(contentType) {
            const typeInfo = this.getContentTypeInfo(contentType);
            const adapterName = typeInfo?.editor?.adapter || '';
            if (!adapterName || MubloBlockEditorAdapters.get(adapterName) || !typeInfo.adminScript) return;
            await this.ensureScript(typeInfo.adminScript);
        }

        /**
         * 콘텐츠 타입별 UI 표시/숨김 토글
         */
        toggleHtmlEditor(contentType) {
            const htmlWrapper = document.getElementById('html_editor_wrapper');
            const includeWrapper = document.getElementById('include_path_wrapper');
            const imageWrapper = document.getElementById('image_config_wrapper');
            const movieWrapper = document.getElementById('movie_config_wrapper');
            const outloginWrapper = document.getElementById('outlogin_config_wrapper');
            const menuConfigWrapper = document.getElementById('menu_config_wrapper');
            const styleWrapper = document.getElementById('content_style_wrapper');
            const countWrapper = document.getElementById('content_count_wrapper');
            const countMoWrapper = document.getElementById('content_count_mo_wrapper');
            const skinWrapper = document.getElementById('content_skin_wrapper');
            const aosWrapper = document.getElementById('content_aos_wrapper');

            // 기본적으로 모두 숨김
            if (htmlWrapper) htmlWrapper.style.display = 'none';
            if (includeWrapper) includeWrapper.style.display = 'none';
            if (outloginWrapper) outloginWrapper.style.display = 'none';
            if (imageWrapper) imageWrapper.style.display = 'none';
            if (movieWrapper) movieWrapper.style.display = 'none';
            if (menuConfigWrapper) menuConfigWrapper.style.display = 'none';
            if (styleWrapper) styleWrapper.style.display = 'none';
            if (aosWrapper) aosWrapper.style.display = 'none';

            // Registry가 정규화한 capability만 공통 설정 영역의 노출 기준으로 사용한다.
            const typeInfo = this.getContentTypeInfo(contentType);
            const capabilities = MubloBlockCapabilities.forType(typeInfo);
            const editorAdapter = typeInfo?.editor?.adapter || '';
            const adapter = MubloBlockEditorAdapters.get(editorAdapter);

            if (countWrapper) countWrapper.style.display = capabilities.count ? '' : 'none';
            if (countMoWrapper) countMoWrapper.style.display = capabilities.count ? '' : 'none';
            if (skinWrapper) skinWrapper.style.display = capabilities.skin ? '' : 'none';
            if (styleWrapper) styleWrapper.style.display = capabilities.style ? 'block' : 'none';
            if (aosWrapper) aosWrapper.style.display = capabilities.aos ? '' : 'none';

            adapter?.activate?.(this);
        }

        /**
         * HTML 블록은 게시판용 MubloEditor 호출부와 분리된 전용 에디터를 사용한다.
         */
        initBlockHtmlLandingEditor() {
            if (typeof BlockHtmlEditor === 'undefined') return;

            setTimeout(() => {
                this.blockHtmlVisualEditor = BlockHtmlEditor.createVisual('#modal_html_content', {
                    css: document.getElementById('modal_html_css')?.value || ''
                });
            }, 80);
        }

        bindBlockHtmlLandingEditorEvents() {
            const cssField = document.getElementById('modal_html_css');
            if (cssField) {
                cssField.addEventListener('input', () => this.injectBlockHtmlEditorCss());
            }
        }

        injectBlockHtmlEditorCss() {
            this.blockHtmlVisualEditor?.injectCss?.(document.getElementById('modal_html_css')?.value || '');
        }

        /**
         * 이미지 아이템 관리 초기화
         */
        initImageItems(items = null) {
            const container = document.getElementById('image_items_container');
            if (!container) return;

            // 기존 아이템이 없으면 하나 추가
            if (!items || items.length === 0) {
                items = [{ pc_image: '', mo_image: '', link_url: '', link_win: '0' }];
            }

            // 모달을 닫았다 다시 열면 content_items 엔 저장 전 pending 이미지가 빈 URL(+ *_has_file
            // 플래그)로만 남아 미리보기·업로드 연결이 끊긴다. 아직 살아 있는 this.pendingFiles 로부터
            // 복원한다. (선택 순서대로 col_img_{pc|mo}_{index} 인덱스 매칭)
            items.forEach((item, i) => {
                [['pc_has_file', 'pc_file_key', 'pc_image', '_pcPreview', `col_img_pc_${i}`],
                 ['mo_has_file', 'mo_file_key', 'mo_image', '_moPreview', `col_img_mo_${i}`]]
                .forEach(([hasKey, fileKeyProp, imgProp, previewProp, fileKey]) => {
                    if (item[hasKey] && this.pendingFiles && this.pendingFiles[fileKey]) {
                        item[fileKeyProp] = fileKey;
                        item[imgProp] = '__pending__';
                        item[previewProp] = URL.createObjectURL(this.pendingFiles[fileKey]);
                    }
                });
            });

            this.imageItems = items;
            this.renderImageItems();
        }

        /**
         * 파일 용량 표시 유틸 — 선택한 파일 크기를 라벨에 적는다. 허용 크기는 서버(php.ini,
         * 업로드 정책)가 정하고 클라이언트는 그 값을 모르므로, 초과 여부는 판단하지 않고
         * 크기만 보여준다. 실제 제한은 업로드 결과 메시지로 알려준다.
         */
        formatFileSize(bytes) {
            if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + 'MB';
            if (bytes >= 1024) return Math.round(bytes / 1024) + 'KB';
            return bytes + 'B';
        }

        setFileSizeLabel(labelEl, sizeBytes) {
            if (!labelEl) return;
            labelEl.classList.remove('text-danger', 'text-muted', 'fw-bold');
            if (!sizeBytes) { labelEl.textContent = ''; return; }
            labelEl.textContent = this.formatFileSize(sizeBytes);
            labelEl.classList.add('text-muted');
        }

        /**
         * 이미지 아이템 렌더링
         */
        renderImageItems() {
            const container = document.getElementById('image_items_container');
            if (!container || !this.imageItems) return;

            const noImage = '/assets/images/no-image.svg';
            // 저장 전 선택한 파일의 미리보기(_pcPreview/_moPreview)를 우선 사용한다.
            // 없으면 저장된 URL, '__pending__' 센티널이나 빈 값이면 no-image 로 폴백.
            const previewUrl = (preview, url) =>
                preview || (url && url !== '__pending__' ? url : noImage);

            container.innerHTML = this.imageItems.map((item, index) => `
                <div class="col-12 col-md-6 image-item-card" data-index="${index}">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="small fw-bold">${index + 1}번 이미지</span>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-image ${this.imageItems.length > 1 ? '' : 'invisible'}" data-index="${index}">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <!-- PC 이미지 -->
                                <div class="col-6">
                                    <label class="form-label small">PC 이미지</label>
                                    <div class="image-preview-box mb-2" data-target="pc" data-index="${index}">
                                        <div class="ratio ratio-16x9 border rounded overflow-hidden bg-light">
                                            <div class="img-preview-inner" style="background-image: url('${previewUrl(item._pcPreview, item.pc_image)}'); background-size: cover; background-position: center;"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" class="img-pc-url" value="${item.pc_image || ''}">
                                    <input type="file" class="form-control img-pc-file" accept="image/jpeg,image/png,image/gif,image/webp" data-index="${index}">
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <div class="img-filesize small" data-target="pc" data-index="${index}"></div>
                                        <div class="form-check mb-0">
                                            ${item.pc_image ? `
                                            <input type="checkbox" class="form-check-input img-pc-del" data-index="${index}">
                                            <label class="form-check-label small text-muted">삭제</label>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                                <!-- MO 이미지 -->
                                <div class="col-6">
                                    <label class="form-label small">MO 이미지 <span class="text-muted fw-normal">(비워두면 PC 사용)</span></label>
                                    <div class="image-preview-box mb-2" data-target="mo" data-index="${index}">
                                        <div class="ratio ratio-16x9 border rounded overflow-hidden bg-light">
                                            <div class="img-preview-inner" style="background-image: url('${previewUrl(item._moPreview, item.mo_image)}'); background-size: cover; background-position: center;"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" class="img-mo-url" value="${item.mo_image || ''}">
                                    <input type="file" class="form-control img-mo-file" accept="image/jpeg,image/png,image/gif,image/webp" data-index="${index}">
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <div class="img-filesize small" data-target="mo" data-index="${index}"></div>
                                        <div class="form-check mb-0">
                                            ${item.mo_image ? `
                                            <input type="checkbox" class="form-check-input img-mo-del" data-index="${index}">
                                            <label class="form-check-label small text-muted">삭제</label>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                                <!-- 링크 설정 -->
                                <div class="col-8 mt-2">
                                    <label class="form-label small">연결 URL</label>
                                    <input type="text" class="form-control img-link-url"
                                           value="${this.escapeHtmlAi(item.link_url || '')}" placeholder="https://">
                                </div>
                                <div class="col-4 mt-2">
                                    <label class="form-label small">새창</label>
                                    <select class="form-select img-link-win">
                                        <option value="0" ${item.link_win != '1' ? 'selected' : ''}>아니오</option>
                                        <option value="1" ${item.link_win == '1' ? 'selected' : ''}>예</option>
                                    </select>
                                </div>
                                <!-- 제목·설명 (선택) — 비워 두면 이미지만 나온다 -->
                                <div class="col-12 mt-2">
                                    <label class="form-label small">제목 <span class="text-muted fw-normal">(선택)</span></label>
                                    <input type="text" class="form-control img-title"
                                           value="${this.escapeHtmlAi(item.title || '')}" placeholder="이미지 아래에 표시할 한 줄">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">설명 <span class="text-muted fw-normal">(선택)</span></label>
                                    <input type="text" class="form-control img-desc"
                                           value="${this.escapeHtmlAi(item.desc || '')}" placeholder="제목 아래 보조 설명">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');

            // 삭제 버튼 이벤트 바인딩
            container.querySelectorAll('.btn-remove-image').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const idx = parseInt(e.currentTarget.dataset.index);
                    this.removeImageItem(idx);
                });
            });

            // 파일 업로드 이벤트 바인딩
            container.querySelectorAll('.img-pc-file, .img-mo-file').forEach(input => {
                input.addEventListener('change', (e) => this.handleImageFileChange(e));
            });

            // 이미지 삭제 체크박스 이벤트 바인딩
            container.querySelectorAll('.img-pc-del, .img-mo-del').forEach(checkbox => {
                checkbox.addEventListener('change', (e) => this.handleImageDelete(e));
            });

            // 글자 입력은 imageItems 에 즉시 되돌려 쓴다.
            // '이미지 추가'/'삭제'는 이 배열로 카드를 다시 그리므로, 여기에
            // 반영해 두지 않으면 방금 친 제목·링크가 지워진 채 다시 그려진다.
            const fieldMap = {
                '.img-title': 'title',
                '.img-desc': 'desc',
                '.img-link-url': 'link_url',
                '.img-link-win': 'link_win',
            };
            container.querySelectorAll('.image-item-card').forEach(card => {
                const idx = parseInt(card.dataset.index, 10);
                Object.entries(fieldMap).forEach(([selector, key]) => {
                    const input = card.querySelector(selector);
                    if (!input) return;
                    input.addEventListener('input', () => {
                        if (this.imageItems[idx]) this.imageItems[idx][key] = input.value;
                    });
                });
            });

            // 선택된 pending 파일의 용량 라벨 복원 (재오픈/재렌더 시)
            container.querySelectorAll('.image-item-card').forEach(card => {
                const idx = parseInt(card.dataset.index, 10);
                ['pc', 'mo'].forEach(dev => {
                    const file = this.pendingFiles && this.pendingFiles[`col_img_${dev}_${idx}`];
                    if (file) {
                        this.setFileSizeLabel(card.querySelector(`.img-filesize[data-target="${dev}"]`), file.size);
                    }
                });
            });
        }

        /**
         * 이미지 파일 변경 핸들러 (미리보기 + pendingFiles에 저장)
         */
        handleImageFileChange(e) {
            const input = e.target;
            const index = parseInt(input.dataset.index);
            const isPc = input.classList.contains('img-pc-file');
            const card = input.closest('.image-item-card');
            const previewBox = card.querySelector(`.image-preview-box[data-target="${isPc ? 'pc' : 'mo'}"] .img-preview-inner`);

            if (!input.files || !input.files[0]) return;

            const file = input.files[0];

            // FileReader로 미리보기 표시
            const reader = new FileReader();
            reader.onload = (event) => {
                if (previewBox) {
                    previewBox.style.backgroundImage = `url('${event.target.result}')`;
                }
                // 재렌더(예: '이미지 추가')에도 미리보기가 유지되도록 아이템에 저장.
                // pc_image 는 '__pending__' 센티널만 담아 재생성 시 미리보기가 사라지던 문제 해결.
                if (this.imageItems[index]) {
                    this.imageItems[index][isPc ? '_pcPreview' : '_moPreview'] = event.target.result;
                }
            };
            reader.readAsDataURL(file);

            // pendingFiles에 파일 저장 (Form 전송 시 사용)
            if (!this.pendingFiles) this.pendingFiles = {};
            const fileKey = `col_img_${isPc ? 'pc' : 'mo'}_${index}`;
            this.pendingFiles[fileKey] = file;

            // 용량 라벨 즉시 갱신 (제출 한도 초과 시 빨간색으로 그 자리에서 표시)
            this.setFileSizeLabel(card.querySelector(`.img-filesize[data-target="${isPc ? 'pc' : 'mo'}"]`), file.size);

            // imageItems 배열에 새 파일 표시 (실제 URL은 서버 업로드 후 설정됨)
            if (this.imageItems[index]) {
                if (isPc) {
                    this.imageItems[index].pc_image = '__pending__';
                    this.imageItems[index].pc_file_key = fileKey;
                } else {
                    this.imageItems[index].mo_image = '__pending__';
                    this.imageItems[index].mo_file_key = fileKey;
                }
            }
        }

        /**
         * 이미지 삭제 체크박스 핸들러
         */
        handleImageDelete(e) {
            const checkbox = e.target;
            const index = parseInt(checkbox.dataset.index);
            const isPc = checkbox.classList.contains('img-pc-del');
            const card = checkbox.closest('.image-item-card');
            const previewBox = card.querySelector(`.image-preview-box[data-target="${isPc ? 'pc' : 'mo'}"] .img-preview-inner`);
            const hiddenInput = card.querySelector(isPc ? '.img-pc-url' : '.img-mo-url');
            const noImage = '/assets/images/no-image.svg';

            if (checkbox.checked) {
                // 이미지 삭제
                if (previewBox) {
                    previewBox.style.backgroundImage = `url('${noImage}')`;
                }
                if (hiddenInput) {
                    hiddenInput.value = '';
                }
                if (this.imageItems[index]) {
                    if (isPc) {
                        this.imageItems[index].pc_image = '';
                        this.imageItems[index].pc_del = true;
                        // pendingFiles에서도 제거
                        if (this.pendingFiles) {
                            delete this.pendingFiles[`col_img_pc_${index}`];
                        }
                    } else {
                        this.imageItems[index].mo_image = '';
                        this.imageItems[index].mo_del = true;
                        if (this.pendingFiles) {
                            delete this.pendingFiles[`col_img_mo_${index}`];
                        }
                    }
                }
            }
        }

        /**
         * 칸 이미지 파일을 메인 폼에 동적 file input으로 추가
         * (saveColumnSettings 호출 시 실행)
         */
        attachPendingFilesToForm(columnIndex) {
            if (!this.pendingFiles || !this.imageItems) return;

            const form = document.querySelector('.mublo-submit');
            if (!form) return;

            // 기존 동적 file input 제거 (해당 칸의 것만)
            form.querySelectorAll(`input[name^="column_images[${columnIndex}]"]`).forEach(el => el.remove());

            // 현재 칸의 이미지 아이템들에 대해 file input 생성
            this.imageItems.forEach((item, imgIndex) => {
                // PC 이미지
                if (item.pc_file_key && this.pendingFiles[item.pc_file_key]) {
                    const pcInput = document.createElement('input');
                    pcInput.type = 'file';
                    pcInput.name = `column_images[${columnIndex}][${imgIndex}][pc]`;
                    pcInput.style.display = 'none';

                    // DataTransfer API로 파일 설정
                    const dt = new DataTransfer();
                    dt.items.add(this.pendingFiles[item.pc_file_key]);
                    pcInput.files = dt.files;

                    form.appendChild(pcInput);
                }

                // MO 이미지
                if (item.mo_file_key && this.pendingFiles[item.mo_file_key]) {
                    const moInput = document.createElement('input');
                    moInput.type = 'file';
                    moInput.name = `column_images[${columnIndex}][${imgIndex}][mo]`;
                    moInput.style.display = 'none';

                    const dt = new DataTransfer();
                    dt.items.add(this.pendingFiles[item.mo_file_key]);
                    moInput.files = dt.files;

                    form.appendChild(moInput);
                }
            });
        }

        /**
         * 이미지 아이템 추가
         */
        addImageItem() {
            if (!this.imageItems) this.imageItems = [];
            this.imageItems.push({ pc_image: '', mo_image: '', link_url: '', link_win: '0', title: '', desc: '' });
            this.renderImageItems();
        }

        /**
         * 이미지 아이템 제거
         */
        removeImageItem(index) {
            if (this.imageItems && this.imageItems.length > 1) {
                this.imageItems.splice(index, 1);
                this.renderImageItems();
            }
        }

        /**
         * 이미지 아이템 데이터 수집 (서버 전송용)
         * - file_key 등 내부 정보 제외
         * - __pending__ 값은 서버에서 파일 업로드 후 URL로 대체됨
         */
        getImageItems() {
            const container = document.getElementById('image_items_container');
            if (!container) return [];

            const items = [];
            container.querySelectorAll('.image-item-card').forEach((card, idx) => {
                const pcUrl = card.querySelector('.img-pc-url')?.value || '';
                const moUrl = card.querySelector('.img-mo-url')?.value || '';

                // imageItems 배열에서 추가 정보 가져오기
                const itemData = this.imageItems?.[idx] || {};

                items.push({
                    // 기존 URL (pending이 아닌 경우)
                    pc_image: pcUrl !== '__pending__' ? pcUrl : '',
                    mo_image: moUrl !== '__pending__' ? moUrl : '',
                    // 새 파일이 있는 경우 표시 (서버에서 파일 처리 시 참조)
                    pc_has_file: !!itemData.pc_file_key,
                    mo_has_file: !!itemData.mo_file_key,
                    // 삭제 여부
                    pc_del: !!itemData.pc_del,
                    mo_del: !!itemData.mo_del,
                    // 링크 설정
                    link_url: card.querySelector('.img-link-url')?.value || '',
                    link_win: card.querySelector('.img-link-win')?.value || '0',
                    // 제목·설명 (선택) — 빈 문자열이면 스킨이 아무것도 출력하지 않는다
                    title: card.querySelector('.img-title')?.value || '',
                    desc: card.querySelector('.img-desc')?.value || ''
                });
            });
            return items;
        }

        /**
         * 동영상 타입 변경 핸들러 초기화
         */
        initVideoTypeChange() {
            const typeSelect = document.getElementById('modal_video_type');
            const urlInput = document.getElementById('modal_video_url');
            const inputLabel = document.getElementById('video_input_label');
            const inputHint = document.getElementById('video_input_hint');
            const previewArea = document.getElementById('video_preview_area');
            const previewFrame = document.getElementById('modal_video_preview');

            if (!typeSelect || !urlInput) return;

            // 이미 바인딩된 경우 스킵
            if (typeSelect._typeBound) return;
            typeSelect._typeBound = true;

            const updateVideoUI = () => {
                const type = typeSelect.value;
                switch (type) {
                    case 'youtube':
                        inputLabel.textContent = 'YouTube URL 또는 영상 ID';
                        urlInput.placeholder = 'https://www.youtube.com/watch?v=... 또는 영상 ID';
                        inputHint.textContent = 'YouTube 링크를 붙여넣거나 영상 ID만 입력하세요.';
                        break;
                    case 'vimeo':
                        inputLabel.textContent = 'Vimeo URL 또는 영상 ID';
                        urlInput.placeholder = 'https://vimeo.com/123456789 또는 영상 ID';
                        inputHint.textContent = 'Vimeo 링크를 붙여넣거나 영상 ID만 입력하세요.';
                        break;
                    case 'url':
                        inputLabel.textContent = '동영상 URL';
                        urlInput.placeholder = 'https://example.com/video.mp4';
                        inputHint.textContent = 'MP4, WebM 등 동영상 파일 URL을 입력하세요.';
                        break;
                }
                this.updateVideoPreview();
            };

            typeSelect.addEventListener('change', updateVideoUI);
            urlInput.addEventListener('change', () => this.updateVideoPreview());
            urlInput.addEventListener('blur', () => this.updateVideoPreview());

            // 초기 UI 설정
            updateVideoUI();
        }

        /**
         * 동영상 미리보기 업데이트
         */
        updateVideoPreview() {
            const typeSelect = document.getElementById('modal_video_type');
            const urlInput = document.getElementById('modal_video_url');
            const previewArea = document.getElementById('video_preview_area');
            const previewFrame = document.getElementById('modal_video_preview');

            if (!typeSelect || !urlInput || !previewFrame) return;

            const type = typeSelect.value;
            const url = urlInput.value.trim();

            if (!url) {
                previewArea.style.display = 'none';
                return;
            }

            let embedUrl = '';

            if (type === 'youtube') {
                const videoId = this.extractYouTubeId(url);
                if (videoId) {
                    embedUrl = `https://www.youtube.com/embed/${videoId}`;
                }
            } else if (type === 'vimeo') {
                const videoId = this.extractVimeoId(url);
                if (videoId) {
                    embedUrl = `https://player.vimeo.com/video/${videoId}`;
                }
            } else if (type === 'url') {
                // 직접 URL은 iframe으로 미리보기 불가, 표시하지 않음
                previewArea.style.display = 'none';
                return;
            }

            if (embedUrl) {
                previewFrame.src = embedUrl;
                previewArea.style.display = 'block';
            } else {
                previewArea.style.display = 'none';
            }
        }

        /**
         * YouTube 영상 ID 추출
         */
        extractYouTubeId(url) {
            if (!url) return null;

            // 이미 ID만 있는 경우 (11자리 영숫자)
            if (/^[a-zA-Z0-9_-]{11}$/.test(url)) {
                return url;
            }

            // URL에서 추출
            const patterns = [
                /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/,
                /youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]{11})/
            ];

            for (const pattern of patterns) {
                const match = url.match(pattern);
                if (match) return match[1];
            }

            return null;
        }

        /**
         * Vimeo 영상 ID 추출
         */
        extractVimeoId(url) {
            if (!url) return null;

            // 이미 ID만 있는 경우 (숫자)
            if (/^\d+$/.test(url)) {
                return url;
            }

            // URL에서 추출
            const match = url.match(/vimeo\.com\/(\d+)/);
            return match ? match[1] : null;
        }

        /**
         * 칸 설정 모달 열기
         */
        async openColumnModal(index) {
            if (this.htmlAiAbort) this.htmlAiAbort.abort();
            this.htmlAiAbort = null;
            this.htmlAiUndo = null;
            this.htmlAiSelectedAssets = new Set();
            const aiPrompt = document.getElementById('row_html_ai_prompt');
            const aiStatus = document.getElementById('row_html_ai_status');
            const aiUndo = document.getElementById('row_html_ai_undo');
            if (aiPrompt) aiPrompt.value = '';
            if (aiStatus) aiStatus.textContent = this.rowId ? '' : '행을 먼저 저장한 뒤 사용할 수 있습니다.';
            if (aiUndo) aiUndo.style.display = 'none';
            const aiGenerate = document.getElementById('row_html_ai_generate');
            if (aiGenerate) aiGenerate.innerHTML = '<i class="bi bi-stars"></i> 생성';
            this.loadHtmlAiLibrary();
            document.getElementById('modalColumnIndex').value = index;
            document.getElementById('modalColumnNumber').textContent = index + 1;

            const data = this.getColumnData(index);

            // JSON 설정 파싱
            let bgConfig = {}, borderConfig = {}, titleConfig = {}, contentConfig = {};
            try {
                bgConfig = data.background_config ? JSON.parse(data.background_config) : {};
                borderConfig = data.border_config ? JSON.parse(data.border_config) : {};
                titleConfig = data.title_config ? JSON.parse(data.title_config) : {};
                contentConfig = data.content_config ? JSON.parse(data.content_config) : {};
            } catch (e) {
                console.warn('Config parsing error:', e);
            }

            // Plugin/Package getConfig() 훅용 — 편집 시 기존값 복원
            this._currentContentConfig = contentConfig;

            let contentItems = [];
            try {
                contentItems = data.content_items ? JSON.parse(data.content_items) : [];
            } catch (e) {
                console.warn('Content items parsing error:', e);
            }

            // 스타일 탭 - 칸 너비
            const widthValue = data.width || '';
            if (widthValue) {
                const widthMatch = widthValue.match(/^([\d.]+)(px|%)$/);
                if (widthMatch) {
                    document.getElementById('modal_column_width').value = widthMatch[1];
                    document.getElementById('modal_column_width_unit').value = widthMatch[2];
                } else {
                    document.getElementById('modal_column_width').value = widthValue;
                    document.getElementById('modal_column_width_unit').value = '%';
                }
            } else {
                document.getElementById('modal_column_width').value = '';
                document.getElementById('modal_column_width_unit').value = '%';
            }

            // 스타일 탭 - 내부여백
            document.getElementById('modal_pc_padding').value = data.pc_padding || '';
            document.getElementById('modal_mobile_padding').value = data.mobile_padding || '';

            // 스타일 탭 - 배경
            const bgColor = bgConfig.color || '';
            document.getElementById('modal_bg_color').value = bgColor;
            document.getElementById('modal_bg_color_picker').value = bgColor || '#ffffff';

            // 배경 이미지
            const bgImage = bgConfig.image || '';
            document.getElementById('modal_bg_image').value = bgImage;
            this._modalBgImageOriginal = bgImage;
            this._modalBgPendingFile = null;
            this._modalBgImageDeleted = false;

            // 파일 input 초기화
            const bgFileInput = document.getElementById('modal_bg_image_file');
            if (bgFileInput) bgFileInput.value = '';

            // 기존 이미지 미리보기
            const bgPreviewDiv = document.getElementById('modal_bg_image_preview');
            const bgPreviewImg = bgPreviewDiv?.querySelector('img');
            const bgDelCheckbox = document.getElementById('modal_bg_image_del');
            if (bgImage) {
                if (bgPreviewImg) bgPreviewImg.src = bgImage;
                if (bgPreviewDiv) bgPreviewDiv.classList.remove('d-none');
                if (bgDelCheckbox) bgDelCheckbox.checked = false;
            } else {
                if (bgPreviewDiv) bgPreviewDiv.classList.add('d-none');
                if (bgDelCheckbox) bgDelCheckbox.checked = false;
            }

            document.getElementById('modal_bg_size').value = bgConfig.size || 'cover';
            document.getElementById('modal_bg_position').value = bgConfig.position || 'center center';
            document.getElementById('modal_bg_repeat').value = bgConfig.repeat || 'no-repeat';
            document.getElementById('modal_bg_attachment').value = bgConfig.attachment || 'scroll';
            this.toggleModalBgImageOptions();

            // 스타일 탭 - 테두리
            document.getElementById('modal_border_width').value = borderConfig.width || '';
            document.getElementById('modal_border_color').value = borderConfig.color || '';
            document.getElementById('modal_border_radius').value = borderConfig.radius || '';

            // 제목 탭
            const titleShow = titleConfig.show || false;
            document.getElementById('modal_title_show').checked = titleShow;
            this.toggleTitleDetailWrapper(titleShow);
            document.getElementById('modal_title_text').value = titleConfig.text || '';
            document.getElementById('modal_title_color').value = titleConfig.color || '';
            document.getElementById('modal_title_color_picker').value = titleConfig.color || '#000000';
            document.getElementById('modal_title_position').value = titleConfig.position || 'left';
            document.getElementById('modal_title_size_pc').value = titleConfig.size_pc || 16;
            document.getElementById('modal_title_size_mo').value = titleConfig.size_mo || 14;

            // 제목 이미지
            this._titlePcPendingFile = null;
            this._titleMoPendingFile = null;
            this.loadTitleImagePreview('pc', titleConfig.pc_image || '');
            this.loadTitleImagePreview('mo', titleConfig.mo_image || '');

            document.getElementById('modal_copytext').value = titleConfig.copytext || '';
            document.getElementById('modal_copytext_color').value = titleConfig.copytext_color || '';
            document.getElementById('modal_copytext_color_picker').value = titleConfig.copytext_color || '#666666';
            document.getElementById('modal_copytext_position').value = titleConfig.copytext_position || '';
            document.getElementById('modal_copytext_size_pc').value = titleConfig.copytext_size_pc || 14;
            document.getElementById('modal_copytext_size_mo').value = titleConfig.copytext_size_mo || 12;
            document.getElementById('modal_more_link').checked = titleConfig.more_link || false;
            document.getElementById('modal_more_url').value = titleConfig.more_url || '';
            document.getElementById('modal_more_text').value = titleConfig.more_text || '';

            // 콘텐츠 탭 - content_config에서 값 읽기
            const contentType = data.content_type || '';
            await this.ensureEditorAdapter(contentType);
            const editorAdapter = this.getEditorAdapter(contentType);
            const editor = MubloBlockEditorAdapters.get(editorAdapter);
            document.getElementById('modal_content_type').value = contentType;
            document.getElementById('modal_content_count_pc').value = contentConfig.pc_count || 4;
            document.getElementById('modal_content_count_mo').value = contentConfig.mo_count || 4;
            document.getElementById('modal_aos_effect').value = contentConfig.aos || '';
            document.getElementById('modal_aos_duration').value = contentConfig.aos_duration || 600;

            // 스킨 목록 업데이트 후 저장된 값 선택
            this.updateSkinList(contentType);
            const savedSkin = data.content_skin || '';
            if (savedSkin) {
                document.getElementById('modal_content_skin').value = savedSkin;
            }
            this.updateSkinRecommendHint();

            // 출력 스타일 설정 (content_config에서 읽기)
            document.getElementById('modal_pc_style').value = contentConfig.pc_style || 'list';
            document.getElementById('modal_mo_style').value = contentConfig.mo_style || 'list';
            document.getElementById('modal_pc_cols').value = contentConfig.pc_cols || '4';
            document.getElementById('modal_mo_cols').value = contentConfig.mo_cols || '2';

            // 슬라이드 옵션 (autoplay / loop)
            const pcAutoplay = contentConfig.pc_autoplay || 0;
            const moAutoplay = contentConfig.mo_autoplay || 0;
            document.getElementById('modal_pc_autoplay_check').checked = pcAutoplay > 0;
            document.getElementById('modal_pc_autoplay_delay').value = pcAutoplay || 5000;
            document.getElementById('modal_pc_autoplay_delay').disabled = pcAutoplay <= 0;
            document.getElementById('modal_mo_autoplay_check').checked = moAutoplay > 0;
            document.getElementById('modal_mo_autoplay_delay').value = moAutoplay || 3000;
            document.getElementById('modal_mo_autoplay_delay').disabled = moAutoplay <= 0;
            document.getElementById('modal_pc_loop').checked = contentConfig.pc_loop || false;
            document.getElementById('modal_mo_loop').checked = contentConfig.mo_loop || false;
            document.getElementById('modal_pc_slide_cover').checked = contentConfig.pc_slide_cover || false;
            document.getElementById('modal_mo_slide_cover').checked = contentConfig.mo_slide_cover || false;
            this.toggleSlideOptions();

            // 메타데이터가 가리키는 adapter에 전용 UI 생명주기를 위임한다.
            this.toggleHtmlEditor(contentType);
            editor?.loadConfig?.(this, contentConfig);
            editor?.loadItems?.(this, contentItems, contentConfig);

            // adapter가 아이템을 직접 소유하지 않으면 공통 selector를 사용한다.
            if (!editor?.ownsItems) {
                this.loadContentItems(contentType, contentItems);
            }

            // 모달 열기
            const modal = new bootstrap.Modal(this.elements.columnModal);
            modal.show();
        }

        /**
         * 칸 설정 저장
         */
        saveColumnSettings() {
            const index = parseInt(document.getElementById('modalColumnIndex').value);
            const container = this.elements.columnsData;

            const set = (field, value) => {
                let input = container.querySelector(`input[name="columns[${index}][${field}]"]`);
                // hidden input이 없으면 생성
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `columns[${index}][${field}]`;
                    container.appendChild(input);
                }
                input.value = value;
            };

            // 칸 너비
            const widthNum = document.getElementById('modal_column_width').value.trim();
            const widthUnit = document.getElementById('modal_column_width_unit').value;
            set('width', widthNum ? widthNum + widthUnit : '');

            // 내부여백
            set('pc_padding', document.getElementById('modal_pc_padding').value);
            set('mobile_padding', document.getElementById('modal_mobile_padding').value);

            // 배경
            const bgConfig = {
                color: document.getElementById('modal_bg_color').value
            };

            // 배경 이미지 처리
            const existingBgImage = document.getElementById('modal_bg_image').value;
            const hasPendingFile = !!this._modalBgPendingFile;
            const isDeleted = this._modalBgImageDeleted;

            if (isDeleted) {
                bgConfig.image = '';
                bgConfig.image_del = true;
            } else if (hasPendingFile) {
                bgConfig.image = existingBgImage || '__pending__';
                bgConfig.image_has_file = true;
            } else if (existingBgImage) {
                bgConfig.image = existingBgImage;
            }

            // 이미지가 있으면 (기존 유지 또는 새 파일) 옵션 저장
            if ((bgConfig.image && bgConfig.image !== '') || hasPendingFile) {
                bgConfig.size = document.getElementById('modal_bg_size').value;
                bgConfig.position = document.getElementById('modal_bg_position').value;
                bgConfig.repeat = document.getElementById('modal_bg_repeat').value;
                bgConfig.attachment = document.getElementById('modal_bg_attachment').value;
            }
            set('background_config', JSON.stringify(bgConfig));

            // 배경 이미지 파일을 메인 폼에 첨부
            this.attachBgFileToForm(index);

            // 테두리
            const borderConfig = {
                width: document.getElementById('modal_border_width').value,
                color: document.getElementById('modal_border_color').value,
                radius: document.getElementById('modal_border_radius').value
            };
            set('border_config', JSON.stringify(borderConfig));

            // 제목
            const titleConfig = {
                show: document.getElementById('modal_title_show').checked,
                text: document.getElementById('modal_title_text').value,
                color: document.getElementById('modal_title_color').value,
                position: document.getElementById('modal_title_position').value,
                size_pc: parseInt(document.getElementById('modal_title_size_pc').value) || 16,
                size_mo: parseInt(document.getElementById('modal_title_size_mo').value) || 14,
                copytext: document.getElementById('modal_copytext').value,
                copytext_color: document.getElementById('modal_copytext_color').value,
                copytext_position: document.getElementById('modal_copytext_position').value,
                copytext_size_pc: parseInt(document.getElementById('modal_copytext_size_pc').value) || 14,
                copytext_size_mo: parseInt(document.getElementById('modal_copytext_size_mo').value) || 12,
                more_link: document.getElementById('modal_more_link').checked,
                more_url: document.getElementById('modal_more_url').value,
                more_text: document.getElementById('modal_more_text').value.trim()
            };

            // 제목 이미지
            const pcImgDel = document.getElementById('modal_title_pc_image_del')?.checked;
            const moImgDel = document.getElementById('modal_title_mo_image_del')?.checked;
            const existingPcImage = document.getElementById('modal_title_pc_image').value;
            const existingMoImage = document.getElementById('modal_title_mo_image').value;

            if (pcImgDel) {
                titleConfig.pc_image = '';
                titleConfig.pc_image_del = true;
            } else if (this._titlePcPendingFile) {
                titleConfig.pc_image = existingPcImage || '__pending__';
                titleConfig.pc_image_has_file = true;
            } else if (existingPcImage) {
                titleConfig.pc_image = existingPcImage;
            }

            if (moImgDel) {
                titleConfig.mo_image = '';
                titleConfig.mo_image_del = true;
            } else if (this._titleMoPendingFile) {
                titleConfig.mo_image = existingMoImage || '__pending__';
                titleConfig.mo_image_has_file = true;
            } else if (existingMoImage) {
                titleConfig.mo_image = existingMoImage;
            }

            set('title_config', JSON.stringify(titleConfig));

            // 제목 이미지 파일을 메인 폼에 첨부
            this.attachTitleImageFilesToForm(index);

            // 콘텐츠
            const contentType = document.getElementById('modal_content_type').value;
            const editorAdapter = this.getEditorAdapter(contentType);
            const editor = MubloBlockEditorAdapters.get(editorAdapter);
            const selectedOption = document.getElementById('modal_content_type').selectedOptions[0];
            const contentKind = selectedOption ? (selectedOption.dataset.kind || 'CORE') : 'CORE';

            set('content_type', contentType);
            set('content_kind', contentKind);
            set('content_skin', document.getElementById('modal_content_skin').value);

            // content_items 결정 (타입에 따라 다름)
            let items = [];
            if (editor?.collectItems) {
                items = editor.collectItems(this, index);
            } else if (this.pluginSelector && typeof this.pluginSelector.getSelectedItems === 'function') {
                // Plugin Custom UI 모드
                items = this.pluginSelector.getSelectedItems();
            } else if (this.dualListbox) {
                // DualListbox 모드: board/boardgroup/menu 등
                items = this.dualListbox.getSelected();
            }
            set('content_items', JSON.stringify(items));

            // content_config 통합 (공통 설정 + 타입별 설정)
            let contentConfig = {
                // 공통 설정
                pc_count: parseInt(document.getElementById('modal_content_count_pc').value) || 4,
                mo_count: parseInt(document.getElementById('modal_content_count_mo').value) || 4,
                aos: document.getElementById('modal_aos_effect').value || null,
                aos_duration: parseInt(document.getElementById('modal_aos_duration').value) || 600,
                // 출력 스타일
                pc_style: document.getElementById('modal_pc_style').value,
                mo_style: document.getElementById('modal_mo_style').value,
                pc_cols: document.getElementById('modal_pc_cols').value,
                mo_cols: document.getElementById('modal_mo_cols').value,
                // 슬라이드 옵션
                pc_autoplay: document.getElementById('modal_pc_autoplay_check').checked
                    ? (parseInt(document.getElementById('modal_pc_autoplay_delay').value) || 5000) : 0,
                mo_autoplay: document.getElementById('modal_mo_autoplay_check').checked
                    ? (parseInt(document.getElementById('modal_mo_autoplay_delay').value) || 3000) : 0,
                pc_loop: document.getElementById('modal_pc_loop').checked,
                mo_loop: document.getElementById('modal_mo_loop').checked,
                pc_slide_cover: document.getElementById('modal_pc_slide_cover').checked,
                mo_slide_cover: document.getElementById('modal_mo_slide_cover').checked
            };

            editor?.collectConfig?.(this, contentConfig);

            // Plugin/Package 추가 설정 병합
            if (this.pluginSelector && typeof this.pluginSelector.getConfig === 'function') {
                Object.assign(contentConfig, this.pluginSelector.getConfig());
            }

            set('content_config', JSON.stringify(contentConfig));

            // 프리뷰 업데이트
            this.updateColumnPreview(index);

            // 모달 닫기
            bootstrap.Modal.getInstance(this.elements.columnModal).hide();
        }

        /**
         * 칸 프리뷰 업데이트
         */
        updateColumnPreview(index) {
            const preview = document.querySelector(`.column-preview-item[data-index="${index}"]`);
            if (!preview) return;

            const data = this.getColumnData(index);
            const contentLabel = this.getContentTypeLabel(data.content_type);

            // 콘텐츠 타입 배지
            const typeBadge = preview.querySelector('.column-type-badge');
            if (typeBadge) {
                typeBadge.className = 'column-type-badge badge ' + (data.content_type ? 'bg-primary' : 'bg-secondary');
                typeBadge.textContent = contentLabel || '미설정';
            }

            // 너비 배지
            const colWidth = data.width || '';
            let widthBadge = preview.querySelector('.column-width-badge');
            const title = preview.querySelector('.card-title');
            if (colWidth) {
                if (!widthBadge && title) {
                    widthBadge = document.createElement('span');
                    widthBadge.className = 'column-width-badge badge bg-info ms-1';
                    title.appendChild(widthBadge);
                }
                if (widthBadge) widthBadge.textContent = colWidth;
                const count = parseInt(this.elements.columnCount?.value) || 1;
                const margin = parseInt(document.querySelector('input[name="formData[column_margin]"]')?.value) || 0;
                const gapTotal = margin * (count - 1);
                preview.style.cssText = `flex: 0 0 calc(${colWidth} - ${gapTotal}px / ${count}); min-width: 150px;`;
            } else {
                if (widthBadge) widthBadge.remove();
                preview.style.cssText = 'flex: 1; min-width: 200px;';
            }

            // 버튼 텍스트
            const btn = preview.querySelector('button');
            if (btn) {
                btn.textContent = data.content_type ? '수정' : '설정';
            }
        }

        /**
         * 미리보기 표시
         */
        showPreview() {
            const previewModal = new bootstrap.Modal(this.elements.previewModal);
            const previewLoading = document.getElementById('previewLoading');
            const previewError = document.getElementById('previewError');
            const previewFrame = document.getElementById('previewFrame');

            // 초기화
            previewLoading.style.display = '';
            previewError.style.display = 'none';
            previewFrame.style.display = 'none';

            previewModal.show();

            // 폼 데이터 수집
            const formData = this.collectFormData();
            const columnsData = this.collectColumnsData();

            // AJAX 요청
            MubloRequest.requestJson('/admin/block-row/preview', {
                formData: formData,
                columns: columnsData
            })
            .then(response => {
                if (response.data && response.data.html) {
                    renderBlockPreviewIframe(response.data.html, response.data.skinCss || [], response.data.skinJs || []);
                } else {
                    previewLoading.style.display = 'none';
                    previewError.style.display = '';
                    previewError.innerHTML = `
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            ${this.escapeHtmlAi(response.message || '미리보기를 생성할 수 없습니다.')}
                        </div>
                    `;
                }
            })
            .catch(error => {
                previewLoading.style.display = 'none';
                previewError.style.display = '';
                previewError.innerHTML = `
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-x-circle me-2"></i>
                        미리보기 생성 중 오류가 발생했습니다.
                    </div>
                `;
            });
        }

        /**
         * 폼 데이터 수집
         */
        collectFormData() {
            const btn = document.querySelector('.mublo-submit');
            const form = btn ? btn.closest('form') : document.querySelector('form');
            if (!form) return {};
            const data = {};

            form.querySelectorAll('input[name^="formData["], select[name^="formData["], textarea[name^="formData["]').forEach(el => {
                const match = el.name.match(/formData\[([^\]]+)\]/);
                if (match) {
                    if (el.type === 'checkbox') {
                        data[match[1]] = el.checked ? 1 : 0;
                    } else {
                        data[match[1]] = el.value;
                    }
                }
            });

            return data;
        }

        /**
         * 칸 데이터 수집
         */
        collectColumnsData() {
            const columns = [];
            const columnCount = parseInt(this.elements.columnCount.value) || 1;

            for (let i = 0; i < columnCount; i++) {
                columns.push(this.getColumnData(i));
            }

            return columns;
        }
    }

    // =========================================================================
    // DualListbox 컴포넌트
    // =========================================================================
    // TODO: 다른 곳에서도 필요해지면 별도 파일(dual-listbox.js)로 분리 가능
    // 현재는 BlockRow 폼 전용으로 사용
    // =========================================================================

    /**
     * DualListbox 클래스
     * 왼쪽(사용 가능)에서 오른쪽(선택됨)으로 드래그하여 아이템을 선택하는 UI 컴포넌트
     *
     * @example
     * const listbox = new DualListbox('#container', {
     *     available: [{ id: 'free', label: '자유게시판' }],
     *     selected: ['free'],
     *     onChanged: (selectedIds) => console.log(selectedIds)
     * });
     */
    class DualListbox {
        constructor(container, options = {}) {
            this.container = typeof container === 'string' ? document.querySelector(container) : container;
            this.options = {
                available: [],
                selected: [],
                leftTitle: '사용 가능',
                rightTitle: '선택됨',
                maxItems: 0,
                onChanged: null,
                ...options
            };

            // Map으로 __proto__ 키 충돌을 막고 ID는 DOM dataset 계약에 맞게 문자열로 통일한다.
            this.itemMap = new Map();
            this.options.available = this.normalizeItems(this.options.available);
            this.options.available.forEach(item => this.itemMap.set(item.id, item));
            this.selectedIds = new Set((this.options.selected || []).map(id => String(id)));
            this.init();
        }

        normalizeItems(items) {
            return (Array.isArray(items) ? items : []).map(item => ({
                ...item,
                id: String(item?.id ?? ''),
                label: String(item?.label ?? '')
            })).filter(item => item.id !== '');
        }

        init() {
            this.render();
            this.bindEvents();
            this.bindDragEvents();
        }

        render() {
            this.container.innerHTML = `
                <div class="dual-listbox">
                    <div class="dual-listbox-panel dual-listbox-available">
                        <div class="dual-listbox-header dual-listbox-available-header"></div>
                        <div class="dual-listbox-list" data-side="available" role="listbox" aria-label="사용 가능" aria-multiselectable="true"></div>
                    </div>
                    <div class="dual-listbox-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-action="add-all" title="모두 추가" aria-label="모두 추가">
                            <i class="bi bi-chevron-double-right"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-action="add" title="선택 추가" aria-label="선택 추가">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-action="remove" title="선택 제거" aria-label="선택 제거">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-action="remove-all" title="모두 제거" aria-label="모두 제거">
                            <i class="bi bi-chevron-double-left"></i>
                        </button>
                    </div>
                    <div class="dual-listbox-panel dual-listbox-selected">
                        <div class="dual-listbox-header dual-listbox-selected-header"></div>
                        <div class="dual-listbox-list" data-side="selected" role="listbox" aria-label="선택됨" aria-multiselectable="true"></div>
                    </div>
                </div>
            `;

            this.availableList = this.container.querySelector('[data-side="available"]');
            this.selectedList = this.container.querySelector('[data-side="selected"]');
            this.availableHeader = this.container.querySelector('.dual-listbox-available-header');
            this.selectedHeader = this.container.querySelector('.dual-listbox-selected-header');
            this.availableHeader.textContent = String(this.options.leftTitle || '사용 가능');

            this.updateLists();
        }

        updateLists() {
            // 사용 가능 목록 (선택되지 않은 것들)
            const availableItems = this.options.available.filter(item => !this.selectedIds.has(item.id));
            this.renderItems(this.availableList, availableItems);

            // 선택된 목록: itemMap에서 조회 (페이지/필터 변경 후에도 유지)
            //
            // itemMap 에 없는 id 는 원본이 삭제됐거나 중지 등으로 목록에서 빠진 것이다.
            // 예전에는 조용히 걸러내서, 헤더에는 "선택됨 (8)" 인데 목록은 비어 있었다.
            // 게다가 그 8칸이 maxItems 를 계속 차지해 새 항목을 하나도 추가할 수 없었고,
            // 저장하면 죽은 id 가 그대로 다시 기록됐다. 원인이 화면에 드러나지 않으니
            // 운영자는 빠져나올 방법이 없었다.
            //
            // 자동으로 지우지는 않는다. "중지" 는 되돌릴 수 있는 상태라, 잠시 내린
            // 상품이 다시 올라오면 선택도 살아나야 한다. 보이게 두고 판단은 운영자에게
            // 맡긴다 — 제거는 기존 ← 버튼·더블클릭·드래그로 그대로 된다.
            const selectedItems = Array.from(this.selectedIds).map(id => (
                this.itemMap.get(id) || { id: id, label: '연결 끊김 (#' + id + ')', missing: true }
            ));
            this.renderItems(this.selectedList, selectedItems);

            this.updateSelectedHeader();
        }

        /** 라벨을 innerHTML로 삽입하지 않아 게시판명·메뉴명의 저장형 XSS를 막는다. */
        renderItems(list, items) {
            list.replaceChildren(...items.map(item => {
                const element = document.createElement('div');
                element.className = 'dual-listbox-item' + (item.missing ? ' dual-listbox-item--missing' : '');
                element.dataset.id = item.id;
                element.draggable = true;
                element.tabIndex = 0;
                element.setAttribute('role', 'option');
                element.setAttribute('aria-selected', 'false');
                element.textContent = item.label;
                if (item.missing) {
                    element.title = '원본이 삭제됐거나 사용할 수 없는 상태입니다. 되살아나지 않는다면 선택에서 제거하세요.';
                }
                return element;
            }));
        }

        toggleItemSelection(item) {
            const selected = item.classList.toggle('selected');
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
        }

        updateSelectedHeader() {
            if (!this.selectedHeader) return;
            const count = this.selectedIds.size;
            const max = this.options.maxItems;
            if (max > 0) {
                this.selectedHeader.textContent = this.options.rightTitle + ' (' + count + '/' + max + ')';
            } else {
                this.selectedHeader.textContent = this.options.rightTitle + (count > 0 ? ' (' + count + ')' : '');
            }
        }

        /**
         * 추가 가능 여부 확인
         */
        canAdd(count) {
            const max = this.options.maxItems;
            if (!max || max <= 0) return true;
            return (this.selectedIds.size + count) <= max;
        }

        /**
         * 추가 가능 잔여 수
         */
        remainingSlots() {
            const max = this.options.maxItems;
            if (!max || max <= 0) return Infinity;
            return Math.max(0, max - this.selectedIds.size);
        }

        bindEvents() {
            // 아이템 클릭 (선택) — 핸들러를 인스턴스에 저장해 destroy() 시 제거 가능하게 함
            this._onClick = (e) => {
                const item = e.target.closest('.dual-listbox-item');
                if (item) {
                    this.toggleItemSelection(item);
                }
            };
            this.container.addEventListener('click', this._onClick);

            // 드래그·더블클릭을 쓰지 못하는 사용자도 키보드로 선택할 수 있다.
            this._onKeydown = (e) => {
                const item = e.target.closest('.dual-listbox-item');
                if (!item) return;
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    this.toggleItemSelection(item);
                    return;
                }
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const sibling = e.key === 'ArrowDown' ? item.nextElementSibling : item.previousElementSibling;
                    sibling?.focus();
                }
            };
            this.container.addEventListener('keydown', this._onKeydown);

            // 아이템 더블클릭 (이동)
            this._onDblClick = (e) => {
                const item = e.target.closest('.dual-listbox-item');
                if (item) {
                    const id = item.dataset.id;
                    const side = item.closest('.dual-listbox-list').dataset.side;

                    if (side === 'available') {
                        if (!this.canAdd(1)) {
                            MubloRequest.showAlert('최대 ' + this.options.maxItems + '개까지 선택할 수 있습니다.', 'warning');
                            return;
                        }
                        this.selectedIds.add(id);
                    } else {
                        this.selectedIds.delete(id);
                    }

                    this.updateLists();
                    this.triggerChange();
                }
            };
            this.container.addEventListener('dblclick', this._onDblClick);

            // 버튼 클릭 (버튼은 render()마다 새로 생성되므로 직접 바인딩)
            this.container.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const action = e.currentTarget.dataset.action;
                    this.handleAction(action);
                });
            });
        }

        handleAction(action) {
            switch (action) {
                case 'add': {
                    const items = Array.from(this.availableList.querySelectorAll('.dual-listbox-item.selected'));
                    const remaining = this.remainingSlots();
                    if (remaining === 0) {
                        MubloRequest.showAlert('최대 ' + this.options.maxItems + '개까지 선택할 수 있습니다.', 'warning');
                        return;
                    }
                    const toAdd = items.slice(0, remaining);
                    toAdd.forEach(item => {
                        this.selectedIds.add(item.dataset.id);
                    });
                    if (items.length > toAdd.length) {
                        MubloRequest.showAlert('최대 ' + this.options.maxItems + '개까지 선택할 수 있습니다. ' + toAdd.length + '개만 추가되었습니다.', 'warning');
                    }
                    break;
                }
                case 'add-all': {
                    const remaining = this.remainingSlots();
                    if (remaining === 0) {
                        MubloRequest.showAlert('최대 ' + this.options.maxItems + '개까지 선택할 수 있습니다.', 'warning');
                        return;
                    }
                    const availableItems = this.options.available.filter(item => !this.selectedIds.has(item.id));
                    const toAdd = availableItems.slice(0, remaining);
                    toAdd.forEach(item => this.selectedIds.add(item.id));
                    if (availableItems.length > toAdd.length) {
                        MubloRequest.showAlert('최대 ' + this.options.maxItems + '개까지 선택할 수 있습니다. ' + toAdd.length + '개만 추가되었습니다.', 'warning');
                    }
                    break;
                }
                case 'remove':
                    this.selectedList.querySelectorAll('.dual-listbox-item.selected').forEach(item => {
                        this.selectedIds.delete(item.dataset.id);
                    });
                    break;
                case 'remove-all':
                    this.selectedIds.clear();
                    break;
            }

            this.updateLists();
            this.triggerChange();
        }

        triggerChange() {
            if (typeof this.options.onChanged === 'function') {
                this.options.onChanged(Array.from(this.selectedIds));
            }
        }

        /**
         * 드래그 앤 드롭 이벤트 바인딩
         */
        bindDragEvents() {
            const self = this;

            // 아이템 dragstart (이벤트 위임) — 컨테이너 바인딩이라 destroy()에서 제거 필요
            this._onDragStart = (e) => {
                const item = e.target.closest('.dual-listbox-item');
                if (!item) return;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', item.dataset.id);
                item.classList.add('dragging');
            };
            this.container.addEventListener('dragstart', this._onDragStart);

            this._onDragEnd = (e) => {
                const item = e.target.closest('.dual-listbox-item');
                if (item) item.classList.remove('dragging');
                // drop-target 클래스 정리
                [self.availableList, self.selectedList].forEach(list => {
                    list.classList.remove('drop-target');
                });
            };
            this.container.addEventListener('dragend', this._onDragEnd);

            // 양쪽 리스트에 drop zone 설정
            [this.availableList, this.selectedList].forEach(list => {
                list.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    list.classList.add('drop-target');
                });

                list.addEventListener('dragleave', (e) => {
                    // 자식 요소로 이동 시 무시
                    if (list.contains(e.relatedTarget)) return;
                    list.classList.remove('drop-target');
                });

                list.addEventListener('drop', (e) => {
                    e.preventDefault();
                    list.classList.remove('drop-target');
                    list.querySelectorAll('.dual-listbox-item').forEach(el => el.classList.remove('drag-over-above', 'drag-over-below'));

                    const id = e.dataTransfer.getData('text/plain');
                    if (!id) return;

                    const targetSide = list.dataset.side;

                    if (targetSide === 'selected' && self.selectedIds.has(id)) {
                        // 선택 목록 내 순서 변경
                        const dropTarget = e.target.closest('.dual-listbox-item');
                        if (dropTarget && dropTarget.dataset.id !== id) {
                            const ids = Array.from(self.selectedIds);
                            const fromIdx = ids.indexOf(id);
                            const toIdx = ids.indexOf(dropTarget.dataset.id);
                            if (fromIdx !== -1 && toIdx !== -1) {
                                ids.splice(fromIdx, 1);
                                ids.splice(toIdx, 0, id);
                                self.selectedIds = new Set(ids);
                                self.updateLists();
                                self.triggerChange();
                            }
                        }
                    } else if (targetSide === 'selected' && !self.selectedIds.has(id)) {
                        if (!self.canAdd(1)) {
                            MubloRequest.showAlert('최대 ' + self.options.maxItems + '개까지 선택할 수 있습니다.', 'warning');
                            return;
                        }
                        self.selectedIds.add(id);
                        self.updateLists();
                        self.triggerChange();
                    } else if (targetSide === 'available' && self.selectedIds.has(id)) {
                        self.selectedIds.delete(id);
                        self.updateLists();
                        self.triggerChange();
                    }
                });

                // 선택 목록 내 드래그 오버 시 위치 표시
                if (list.dataset.side === 'selected') {
                    list.addEventListener('dragover', (e) => {
                        const item = e.target.closest('.dual-listbox-item');
                        list.querySelectorAll('.dual-listbox-item').forEach(el => el.classList.remove('drag-over-above', 'drag-over-below'));
                        if (item) {
                            const rect = item.getBoundingClientRect();
                            const mid = rect.top + rect.height / 2;
                            item.classList.add(e.clientY < mid ? 'drag-over-above' : 'drag-over-below');
                        }
                    });
                }
            });
        }

        /**
         * 선택된 ID 목록 반환
         */
        getSelected() {
            return Array.from(this.selectedIds);
        }

        /**
         * 선택 목록 설정
         */
        setSelected(ids) {
            this.selectedIds = new Set((ids || []).map(id => String(id)));
            this.updateLists();
        }

        /**
         * 사용 가능한 아이템 목록 업데이트
         */
        setAvailable(items) {
            const normalized = this.normalizeItems(items);
            normalized.forEach(item => this.itemMap.set(item.id, item));
            this.options.available = normalized;
            this.updateLists();
        }

        /**
         * 최대 선택 수 변경
         */
        setMaxItems(max) {
            this.options.maxItems = max;
            this.updateSelectedHeader();
        }

        /**
         * 인스턴스 정리 — 컨테이너에 위임 바인딩된 리스너 제거
         *
         * 컨테이너(.dual-listbox-wrapper)는 모달 재오픈 시에도 유지되므로,
         * destroy() 없이 새 인스턴스를 만들면 click/dblclick 리스너가 누적되어
         * .selected 토글이 중복 실행(상쇄)된다.
         */
        destroy() {
            if (!this.container) return;
            this.container.removeEventListener('click', this._onClick);
            this.container.removeEventListener('keydown', this._onKeydown);
            this.container.removeEventListener('dblclick', this._onDblClick);
            this.container.removeEventListener('dragstart', this._onDragStart);
            this.container.removeEventListener('dragend', this._onDragEnd);
        }
    }

    // =========================================================================
    // 전역 인스턴스 및 함수
    // =========================================================================

    let blockRowFormInstance = null;

    /**
     * BlockRowForm 초기화
     */
    function initBlockRowForm(config) {
        blockRowFormInstance = new BlockRowForm(config);
        return blockRowFormInstance;
    }

    // 전역 함수 (기존 onclick 핸들러 호환용)
    window.openColumnModal = function(index) {
        if (blockRowFormInstance) {
            blockRowFormInstance.openColumnModal(index);
        }
    };

    window.saveColumnSettings = function() {
        if (blockRowFormInstance) {
            blockRowFormInstance.saveColumnSettings();
        }
    };

    window.showPreview = function() {
        if (blockRowFormInstance) {
            blockRowFormInstance.showPreview();
        }
    };

    // 모듈 내보내기
    window.BlockRowForm = {
        init: initBlockRowForm,
        DualListbox: DualListbox,
        getInstance: () => blockRowFormInstance
    };

    // Core UI 컴포넌트: Plugin JS에서 사용 가능
    window.MubloDualListbox = DualListbox;

})();
