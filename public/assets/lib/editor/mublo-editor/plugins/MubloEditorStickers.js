/**
 * ============================================================
 * MubloEditor Stickers Plugin (v1.6)
 * 이모티콘/스티커 삽입
 * ============================================================
 *
 * 스티커 에셋은 각 프로젝트가 제공하고, 이 플러그인은 뷰어만 담당한다.
 *
 * 사용법:
 * 1. MubloEditor.js 다음에 이 파일 로드
 * 2. 팩 등록:
 *    MubloEditorStickers.setPacks([
 *        { name: '팩이름', baseUrl: '/assets/stickers/pack1/',
 *          items: [{ file: 'a.png', label: '기분 최고!' }, ...] }
 *    ]);
 * 3. data-toolbar-items 에 'sticker' 추가
 *
 * item.file 대신 item.src(절대/데이터 URL)도 허용된다.
 */
(function () {
    'use strict';
    if (typeof MubloEditor === 'undefined') {
        console.error('[MubloEditorStickers] MubloEditor must be loaded first');
        return;
    }

    const STR = {
        ko: { title: '이모티콘', recent: '최근 사용', empty: '등록된 스티커 팩이 없습니다. MubloEditorStickers.setPacks()로 등록하세요.', close: '닫기' },
        en: { title: 'Stickers', recent: 'Recent', empty: 'No sticker packs. Register with MubloEditorStickers.setPacks().', close: 'Close' }
    };
    const t = (k) => (STR[MubloEditor.getLocale()] || STR.ko)[k] || k;
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const RECENT_KEY = 'mublo-editor-recent-stickers';
    const RECENT_MAX = 10;
    let packs = [];

    const srcOf = (pack, item) => item.src || ((pack.baseUrl || '') + item.file);

    function getRecent() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; } catch (e) { return []; }
    }
    function pushRecent(entry) {
        try {
            const list = [entry, ...getRecent().filter(r => r.src !== entry.src)].slice(0, RECENT_MAX);
            localStorage.setItem(RECENT_KEY, JSON.stringify(list));
        } catch (e) { /* localStorage 불가 환경 무시 */ }
    }

    function injectCss() {
        if (document.getElementById('mublo-plugin-stickers-css')) return;
        const style = document.createElement('style');
        style.id = 'mublo-plugin-stickers-css';
        style.textContent = `
.mublo-editor-st-tabs { display:flex; flex-wrap:wrap; gap:.375rem; margin-bottom:.875rem; }
.mublo-editor-st-tab { padding:.25rem .75rem; border:1px solid var(--border, var(--bs-border-color, #dee2e6)); border-radius:999px; background:transparent; color:var(--foreground, var(--bs-body-color, #212529)); font-size:.8125rem; cursor:pointer; }
.mublo-editor-st-tab.active { border-color:var(--bs-primary, #0d6efd); color:var(--bs-primary, #0d6efd); background:rgba(13,110,253,.08); }
.mublo-editor-st-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(86px,1fr)); gap:.5rem; max-height:320px; overflow-y:auto; }
.mublo-editor-st-item { display:flex; flex-direction:column; align-items:center; gap:.25rem; padding:.5rem .25rem; border:1px solid var(--border, var(--bs-border-color, #dee2e6)); border-radius:.5rem; background:var(--background, var(--bs-body-bg, #fff)); cursor:pointer; }
.mublo-editor-st-item:hover { border-color:var(--bs-primary, #0d6efd); box-shadow:0 2px 6px rgba(13,110,253,.15); }
.mublo-editor-st-item img { width:56px; height:56px; object-fit:contain; }
.mublo-editor-st-item span { font-size:.6875rem; color:var(--muted-foreground, var(--bs-secondary-color, #6c757d)); text-align:center; line-height:1.2; }
.mublo-editor-st-empty { padding:1.5rem; text-align:center; color:var(--muted-foreground, var(--bs-secondary-color, #6c757d)); font-size:.8125rem; }
`;
        document.head.appendChild(style);
    }

    function itemHtml(src, label) {
        return `<button type="button" class="mublo-editor-st-item" data-src="${esc(src)}" data-label="${esc(label || '')}">
            <img src="${esc(src)}" alt="${esc(label || '')}" loading="lazy"><span>${esc(label || '')}</span>
        </button>`;
    }

    function openDialog(editor) {
        injectCss();
        editor.saveSelection();

        const recent = getRecent();
        const tabs = [];
        if (recent.length) tabs.push({ key: '__recent', name: t('recent'), items: recent.map(r => ({ src: r.src, label: r.label })) });
        packs.forEach((p, i) => tabs.push({ key: 'pack' + i, name: p.name, items: p.items.map(it => ({ src: srcOf(p, it), label: it.label })) }));

        let body;
        if (!tabs.length) {
            body = `<div class="mublo-editor-st-empty">${t('empty')}</div>`;
        } else {
            const tabsHtml = tabs.map((tab, i) =>
                `<button type="button" class="mublo-editor-st-tab${i === 0 ? ' active' : ''}" data-tab="${tab.key}">${esc(tab.name)}</button>`).join('');
            const gridsHtml = tabs.map((tab, i) =>
                `<div class="mublo-editor-st-grid" data-tab="${tab.key}"${i === 0 ? '' : ' style="display:none"'}>` +
                tab.items.map(it => itemHtml(it.src, it.label)).join('') + '</div>').join('');
            body = `<div class="mublo-editor-st-tabs">${tabsHtml}</div>${gridsHtml}`;
        }

        const modal = editor.openModal(t('title'), body);
        modal.querySelector('#mublo-editor-modal-confirm').style.display = 'none';
        modal.querySelector('#mublo-editor-modal-cancel').textContent = t('close');

        modal.querySelectorAll('.mublo-editor-st-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                modal.querySelectorAll('.mublo-editor-st-tab').forEach(b => b.classList.toggle('active', b === tab));
                modal.querySelectorAll('.mublo-editor-st-grid').forEach(g => {
                    g.style.display = g.dataset.tab === tab.dataset.tab ? '' : 'none';
                });
            });
        });
        modal.querySelectorAll('.mublo-editor-st-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const src = btn.dataset.src, label = btn.dataset.label;
                pushRecent({ src, label });
                editor.insertHTML(
                    `<img data-mublo-sticker src="${esc(src)}" alt="${esc(label)}" style="max-width:120px;height:auto;vertical-align:middle;">&nbsp;`
                );
                modal.querySelector('#mublo-editor-modal-cancel').click();
            });
        });
    }

    MubloEditor.addToolbarItem('sticker', {
        icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
        title: STR.ko.title,
        onClick: openDialog
    });

    // 전역 설정 API
    window.MubloEditorStickers = {
        /** 스티커 팩 등록: [{ name, baseUrl?, items: [{file|src, label}] }] */
        setPacks(list) { if (Array.isArray(list)) packs = list; },
        getPacks() { return packs; }
    };
})();
