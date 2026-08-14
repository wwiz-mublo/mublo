/**
 * ============================================================
 * MubloEditor Layouts Plugin (v1.6, v1.7.1 프리셋 10종 완성)
 * 이미지+텍스트 레이아웃 삽입
 * ============================================================
 *
 * 사용법:
 * 1. MubloEditor.js 다음에 이 파일 로드
 * 2. data-toolbar-items 에 'layout' 추가
 *
 * 출력은 figure[data-mublo-layout] + 인라인 스타일로 완결되어
 * 뷰 페이지에 별도 CSS 가 필요 없다.
 */
(function () {
    'use strict';
    if (typeof MubloEditor === 'undefined') {
        console.error('[MubloEditorLayouts] MubloEditor must be loaded first');
        return;
    }

    const STR = {
        ko: {
            title: '이미지+텍스트 레이아웃', layoutPick: '레이아웃 선택',
            image: '이미지', imageUrl: '이미지 URL', upload: '업로드',
            imageWidth: '이미지 너비', text: '텍스트',
            grabText: '선택한 텍스트 가져오기', textPlaceholder: '내용을 입력하세요',
            insert: '삽입', sample: '내용을 입력하세요.',
            l_imgLeft: '이미지 왼쪽', l_imgRight: '이미지 오른쪽',
            l_imgTop: '이미지 위·텍스트 아래', l_textTop: '텍스트 위·이미지 아래',
            l_capLeft: '이미지+캡션 왼쪽', l_capRight: '이미지+캡션 오른쪽',
            l_col2: '2단 (이미지|텍스트)', l_col2r: '2단 (텍스트|이미지)',
            l_col3: '3단 이미지',
            l_overlay: '전체 이미지+텍스트 오버레이',
            image2: '이미지 2', image3: '이미지 3',
        },
        en: {
            title: 'Image + Text Layout', layoutPick: 'Choose layout',
            image: 'Image', imageUrl: 'Image URL', upload: 'Upload',
            imageWidth: 'Image width', text: 'Text',
            grabText: 'Use selected text', textPlaceholder: 'Enter text',
            insert: 'Insert', sample: 'Enter your text here.',
            l_imgLeft: 'Image left', l_imgRight: 'Image right',
            l_imgTop: 'Image top', l_textTop: 'Text top',
            l_capLeft: 'Image+caption left', l_capRight: 'Image+caption right',
            l_col2: '2-col (image|text)', l_col2r: '2-col (text|image)',
            l_col3: '3-col images',
            l_overlay: 'Full image + overlay',
            image2: 'Image 2', image3: 'Image 3',
        }
    };
    const t = (k) => (STR[MubloEditor.getLocale()] || STR.ko)[k] || k;
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    // 레이아웃 정의: build(img, text, widthPct) → HTML
    const IMG_STYLE = 'display:block;width:100%;height:auto;border-radius:6px;';
    const LAYOUTS = [
        { id: 'img-left', label: 'l_imgLeft', build: (img, text, w) =>
            `<figure data-mublo-layout="img-left" style="display:flex;gap:16px;align-items:flex-start;margin:1em 0;">` +
            `<span style="flex:0 0 ${w}%;max-width:${w}%;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}"></span>` +
            `<span style="flex:1;min-width:0;">${text}</span></figure>` },
        { id: 'img-right', label: 'l_imgRight', build: (img, text, w) =>
            `<figure data-mublo-layout="img-right" style="display:flex;gap:16px;align-items:flex-start;margin:1em 0;">` +
            `<span style="flex:1;min-width:0;">${text}</span>` +
            `<span style="flex:0 0 ${w}%;max-width:${w}%;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}"></span></figure>` },
        { id: 'img-top', label: 'l_imgTop', build: (img, text, w) =>
            `<figure data-mublo-layout="img-top" style="margin:1em 0;">` +
            `<span style="display:block;max-width:${w}%;margin:0 auto .75em;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}"></span>` +
            `<span style="display:block;">${text}</span></figure>` },
        { id: 'text-top', label: 'l_textTop', build: (img, text, w) =>
            `<figure data-mublo-layout="text-top" style="margin:1em 0;">` +
            `<span style="display:block;margin-bottom:.75em;">${text}</span>` +
            `<span style="display:block;max-width:${w}%;margin:0 auto;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}"></span></figure>` },
        { id: 'col-2', label: 'l_col2', build: (img, text) =>
            `<figure data-mublo-layout="col-2" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;margin:1em 0;">` +
            `<span style="flex:1 1 240px;min-width:0;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}"></span>` +
            `<span style="flex:1 1 240px;min-width:0;">${text}</span></figure>` },
        { id: 'col-2r', label: 'l_col2r', build: (img, text) =>
            `<figure data-mublo-layout="col-2r" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;margin:1em 0;">` +
            `<span style="flex:1 1 240px;min-width:0;">${text}</span>` +
            `<span style="flex:1 1 240px;min-width:0;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}"></span></figure>` },
        { id: 'cap-left', label: 'l_capLeft', build: (img, text, w) =>
            `<figure data-mublo-layout="cap-left" style="display:flex;gap:16px;align-items:flex-start;margin:1em 0;">` +
            `<span style="flex:0 0 ${w}%;max-width:${w}%;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}">` +
            `<span style="display:block;margin-top:.4em;color:#6c757d;font-size:.8em;text-align:center;">${text}</span></span>` +
            `<span style="flex:1;min-width:0;"></span></figure>` },
        { id: 'cap-right', label: 'l_capRight', build: (img, text, w) =>
            `<figure data-mublo-layout="cap-right" style="display:flex;gap:16px;align-items:flex-start;justify-content:flex-end;margin:1em 0;">` +
            `<span style="flex:1;min-width:0;"></span>` +
            `<span style="flex:0 0 ${w}%;max-width:${w}%;"><img src="${img}" alt="" loading="lazy" style="${IMG_STYLE}">` +
            `<span style="display:block;margin-top:.4em;color:#6c757d;font-size:.8em;text-align:center;">${text}</span></span></figure>` },
        { id: 'col-3', label: 'l_col3', multi: true, build: (img, text, w, extras) => {
            const cols = [img].concat(extras || []).filter(Boolean).slice(0, 3);
            return `<figure data-mublo-layout="col-3" style="margin:1em 0;">` +
                `<span style="display:flex;flex-wrap:wrap;gap:12px;">` +
                cols.map(u => `<span style="flex:1 1 30%;min-width:120px;"><img src="${u}" alt="" loading="lazy" style="${IMG_STYLE}"></span>`).join('') +
                `</span>` +
                `<span style="display:block;margin-top:.5em;color:#6c757d;font-size:.85em;text-align:center;">${text}</span></figure>`;
        } },
        { id: 'overlay', label: 'l_overlay', build: (img, text) =>
            `<figure data-mublo-layout="overlay" style="position:relative;margin:1em 0;border-radius:8px;overflow:hidden;">` +
            `<img src="${img}" alt="" loading="lazy" style="display:block;width:100%;height:auto;">` +
            `<span style="position:absolute;left:0;right:0;bottom:0;padding:1.2em 1em .9em;background:linear-gradient(transparent,rgba(0,0,0,.72));color:#fff;font-weight:600;">${text}</span></figure>` }
    ];

    // 프리셋 미니 아이콘 (CSS 도형)
    const PREVIEWS = {
        'img-left':  '<span class="mel-b mel-img" style="width:38%"></span><span class="mel-b mel-txt" style="flex:1"></span>',
        'img-right': '<span class="mel-b mel-txt" style="flex:1"></span><span class="mel-b mel-img" style="width:38%"></span>',
        'img-top':   '<span style="display:flex;flex-direction:column;gap:3px;width:100%"><span class="mel-b mel-img" style="height:16px"></span><span class="mel-b mel-txt" style="height:10px"></span></span>',
        'text-top':  '<span style="display:flex;flex-direction:column;gap:3px;width:100%"><span class="mel-b mel-txt" style="height:10px"></span><span class="mel-b mel-img" style="height:16px"></span></span>',
        'col-2':     '<span class="mel-b mel-img" style="flex:1"></span><span class="mel-b mel-txt" style="flex:1"></span>',
        'col-2r':    '<span class="mel-b mel-txt" style="flex:1"></span><span class="mel-b mel-img" style="flex:1"></span>',
        'col-3':     '<span class="mel-b mel-img" style="flex:1"></span><span class="mel-b mel-img" style="flex:1"></span><span class="mel-b mel-img" style="flex:1"></span>',
        'cap-left':  '<span style="display:flex;flex-direction:column;gap:2px;width:38%"><span class="mel-b mel-img" style="height:14px"></span><span class="mel-b mel-txt" style="height:5px"></span></span><span style="flex:1"></span>',
        'cap-right': '<span style="flex:1"></span><span style="display:flex;flex-direction:column;gap:2px;width:38%"><span class="mel-b mel-img" style="height:14px"></span><span class="mel-b mel-txt" style="height:5px"></span></span>',
        'overlay':   '<span class="mel-b mel-img" style="width:100%;height:24px;position:relative"><span style="position:absolute;left:2px;right:2px;bottom:2px;height:7px;background:rgba(33,37,41,.55);border-radius:2px"></span></span>'
    };

    function injectCss() {
        if (document.getElementById('mublo-plugin-layouts-css')) return;
        const style = document.createElement('style');
        style.id = 'mublo-plugin-layouts-css';
        style.textContent = `
.mublo-editor-layout-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:.5rem; margin-bottom:1rem; }
.mublo-editor-layout-card { display:flex; flex-direction:column; gap:.375rem; padding:.5rem; border:1px solid var(--border, var(--bs-border-color, #dee2e6)); border-radius:.5rem; background:var(--background, var(--bs-body-bg, #fff)); cursor:pointer; }
.mublo-editor-layout-card:hover { border-color: var(--bs-primary, #0d6efd); }
.mublo-editor-layout-card.selected { border-color: var(--bs-primary, #0d6efd); background: rgba(13,110,253,.06); }
.mublo-editor-layout-card-preview { display:flex; gap:3px; align-items:stretch; height:32px; }
.mel-b { border-radius:3px; }
.mel-img { background:#a5b4fc; }
.mel-txt { background:var(--secondary, var(--bs-secondary-bg, #e9ecef)); }
.mublo-editor-layout-card-label { font-size:.7rem; text-align:center; color:var(--muted-foreground, var(--bs-secondary-color, #6c757d)); }
.mublo-editor-layout-row { display:flex; align-items:center; gap:.5rem; margin-bottom:.625rem; }
.mublo-editor-layout-row label { flex:0 0 76px; font-size:.8125rem; }
.mublo-editor-layout-row input[type="text"], .mublo-editor-layout-row textarea { flex:1; padding:.375rem .5rem; border:1px solid var(--border, var(--bs-border-color, #dee2e6)); border-radius:.25rem; background:var(--background, var(--bs-body-bg, #fff)); color:var(--foreground, var(--bs-body-color, #212529)); font-size:.8125rem; }
.mublo-editor-layout-thumb { max-height:72px; border-radius:.25rem; display:none; }
`;
        document.head.appendChild(style);
    }

    function openDialog(editor) {
        injectCss();
        editor.saveSelection();
        const selectedText = editor.getSelectedText();

        const cards = LAYOUTS.map((l, i) => `
            <button type="button" class="mublo-editor-layout-card${i === 0 ? ' selected' : ''}" data-layout="${l.id}">
                <span class="mublo-editor-layout-card-preview">${PREVIEWS[l.id]}</span>
                <span class="mublo-editor-layout-card-label">${t(l.label)}</span>
            </button>`).join('');

        const body = `
            <div class="mublo-editor-layout-grid">${cards}</div>
            <div class="mublo-editor-layout-row">
                <label>${t('imageUrl')}</label>
                <input type="text" id="mel-img-url" placeholder="https://...">
                <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-secondary" id="mel-upload-btn">${t('upload')}</button>
                <input type="file" id="mel-upload-input" accept="image/*" style="display:none">
            </div>
            <img id="mel-thumb" class="mublo-editor-layout-thumb" alt="">
            <div class="mublo-editor-layout-row mel-extra" style="display:none">
                <label>${t('image2')}</label>
                <input type="text" id="mel-img-url2" placeholder="https://...">
                <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-secondary" id="mel-upload-btn2">${t('upload')}</button>
                <input type="file" id="mel-upload-input2" accept="image/*" style="display:none">
            </div>
            <div class="mublo-editor-layout-row mel-extra" style="display:none">
                <label>${t('image3')}</label>
                <input type="text" id="mel-img-url3" placeholder="https://...">
                <button type="button" class="mublo-editor-modal-btn mublo-editor-modal-btn-secondary" id="mel-upload-btn3">${t('upload')}</button>
                <input type="file" id="mel-upload-input3" accept="image/*" style="display:none">
            </div>
            <div class="mublo-editor-layout-row">
                <label>${t('imageWidth')}</label>
                <input type="range" id="mel-width" min="20" max="100" value="40" style="flex:1">
                <span id="mel-width-val" style="flex:0 0 44px;text-align:right;font-weight:600;color:var(--bs-primary,#0d6efd);font-size:.8125rem;">40%</span>
            </div>
            <div class="mublo-editor-layout-row" style="align-items:flex-start">
                <label>${t('text')}</label>
                <textarea id="mel-text" rows="3" placeholder="${t('textPlaceholder')}">${esc(selectedText)}</textarea>
            </div>
        `;

        const modal = editor.openModal(t('title'), body, t('insert'), (m) => {
            const layoutId = m.querySelector('.mublo-editor-layout-card.selected')?.dataset.layout || 'img-left';
            const imgUrl = m.querySelector('#mel-img-url').value.trim();
            const width = parseInt(m.querySelector('#mel-width').value, 10);
            const rawText = m.querySelector('#mel-text').value.trim() || t('sample');
            const isUrl = (v) => /^(https?:\/\/|data:image\/|\/)/i.test(v);
            if (!imgUrl || !isUrl(imgUrl)) return false;

            const extras = ['2', '3']
                .map(n => m.querySelector('#mel-img-url' + n).value.trim())
                .filter(isUrl).map(esc);
            const layout = LAYOUTS.find(l => l.id === layoutId);
            const textHtml = esc(rawText).replace(/\n/g, '<br>');
            const html = layout.build(esc(imgUrl), textHtml, width, extras) + '<p><br></p>';
            editor.insertHTML(html);
        });

        // 레이아웃 카드 선택 (multi 프리셋이면 이미지 2·3 입력 노출)
        const syncExtraRows = () => {
            const sel = modal.querySelector('.mublo-editor-layout-card.selected');
            const isMulti = !!LAYOUTS.find(l => l.id === (sel && sel.dataset.layout))?.multi;
            modal.querySelectorAll('.mel-extra').forEach(row => { row.style.display = isMulti ? '' : 'none'; });
        };
        modal.querySelectorAll('.mublo-editor-layout-card').forEach(card => {
            card.addEventListener('click', () => {
                modal.querySelectorAll('.mublo-editor-layout-card').forEach(c => c.classList.toggle('selected', c === card));
                syncExtraRows();
            });
        });
        syncExtraRows();
        // 너비 슬라이더
        const width = modal.querySelector('#mel-width');
        width.addEventListener('input', () => {
            modal.querySelector('#mel-width-val').textContent = width.value + '%';
        });
        // URL 입력 → 썸네일 미리보기
        const urlInput = modal.querySelector('#mel-img-url');
        const thumb = modal.querySelector('#mel-thumb');
        const showThumb = () => {
            const v = urlInput.value.trim();
            if (/^(https?:\/\/|data:image\/|\/)/i.test(v)) { thumb.src = v; thumb.style.display = 'block'; }
            else thumb.style.display = 'none';
        };
        urlInput.addEventListener('change', showThumb);
        // 업로드 버튼 → 에디터 업로드 핸들러 재사용, 없으면 base64 (이미지 1·2·3 공통)
        ['', '2', '3'].forEach(suffix => {
            const urlIn = modal.querySelector('#mel-img-url' + suffix);
            const fileIn = modal.querySelector('#mel-upload-input' + suffix);
            modal.querySelector('#mel-upload-btn' + suffix).addEventListener('click', () => fileIn.click());
            fileIn.addEventListener('change', async () => {
                const file = fileIn.files && fileIn.files[0];
                if (!file) return;
                const handler = editor.getImageUploadHandler();
                try {
                    if (handler) {
                        urlIn.value = await handler(new MubloEditor.BlobInfo(file), () => {});
                    } else {
                        urlIn.value = await new Promise((resolve, reject) => {
                            const reader = new FileReader();
                            reader.onload = () => resolve(reader.result);
                            reader.onerror = reject;
                            reader.readAsDataURL(file);
                        });
                    }
                    if (!suffix) showThumb();
                } catch (err) {
                    console.error('[MubloEditorLayouts] upload failed:', err);
                }
            });
        });
    }

    // 외부/테스트용 최소 API — 프리셋 목록과 HTML 빌더
    window.MubloEditorLayouts = {
        presets: LAYOUTS.map(l => l.id),
        buildLayout: (id, img, text, widthPct, extraImgs) => {
            const l = LAYOUTS.find(x => x.id === id);
            return l ? l.build(img, text, widthPct, extraImgs) : '';
        }
    };

    MubloEditor.addToolbarItem('layout', {
        icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="8" height="16" rx="1"/><line x1="14" y1="6" x2="21" y2="6"/><line x1="14" y1="11" x2="21" y2="11"/><line x1="14" y1="16" x2="21" y2="16"/></svg>',
        title: STR[MubloEditor.getLocale()] ? t('title') : '이미지+텍스트 레이아웃',
        onClick: openDialog
    });
})();
