/**
 * ============================================================
 * MubloEditor Export Plugin (v1.6)
 * 문서 내보내기 (Word .doc / PDF)
 * ============================================================
 *
 * - Word(.doc): HTML → application/msword Blob. 외부 의존성 없음, 항상 제공.
 * - PDF: 페이지에 html2pdf.js(window.html2pdf)가 로드된 경우에만 섹션 노출.
 *   플러그인이 라이브러리를 번들하지 않으므로 무의존 원칙을 유지한다.
 *
 * 사용법:
 * 1. MubloEditor.js 다음에 이 파일 로드
 * 2. data-toolbar-items 에 'export' 추가
 * 3. (선택) PDF 를 쓰려면 html2pdf.js 를 페이지에 로드
 */
(function () {
    'use strict';
    if (typeof MubloEditor === 'undefined') {
        console.error('[MubloEditorExport] MubloEditor must be loaded first');
        return;
    }

    const STR = {
        ko: {
            title: '내보내기', filename: '파일명',
            pdfTitle: 'PDF로 저장', pdfDesc: 'html2pdf.js — 브라우저에서 처리',
            pdfMissing: 'PDF 내보내기를 사용하려면 페이지에 html2pdf.js 를 로드하세요',
            paper: '용지', orientation: '방향', portrait: '세로', landscape: '가로',
            pdfBtn: 'PDF 다운로드',
            wordTitle: 'Word(.doc)로 저장', wordDesc: 'HTML→Word 변환, 브라우저에서 처리',
            wordBtn: 'Word 다운로드', close: '닫기',
        },
        en: {
            title: 'Export', filename: 'Filename',
            pdfTitle: 'Save as PDF', pdfDesc: 'html2pdf.js — processed in browser',
            pdfMissing: 'Load html2pdf.js on the page to enable PDF export',
            paper: 'Paper', orientation: 'Orientation', portrait: 'Portrait', landscape: 'Landscape',
            pdfBtn: 'Download PDF',
            wordTitle: 'Save as Word (.doc)', wordDesc: 'HTML→Word, processed in browser',
            wordBtn: 'Download Word', close: 'Close',
        }
    };
    const t = (k) => (STR[MubloEditor.getLocale()] || STR.ko)[k] || k;

    function injectCss() {
        if (document.getElementById('mublo-plugin-export-css')) return;
        const style = document.createElement('style');
        style.id = 'mublo-plugin-export-css';
        style.textContent = `
.mublo-editor-ex-section { margin-bottom:1rem; padding:1rem; border:1px solid var(--border, var(--bs-border-color, #dee2e6)); border-radius:.5rem; }
.mublo-editor-ex-section h6 { margin:0 0 .25rem; font-size:.9rem; }
.mublo-editor-ex-desc { margin-bottom:.75rem; font-size:.75rem; color:var(--muted-foreground, var(--bs-secondary-color, #6c757d)); }
.mublo-editor-ex-row { display:flex; align-items:center; gap:.5rem; margin-bottom:.625rem; font-size:.8125rem; }
.mublo-editor-ex-row label { flex:0 0 60px; }
.mublo-editor-ex-row input[type="text"], .mublo-editor-ex-row select { padding:.3rem .5rem; border:1px solid var(--border, var(--bs-border-color, #dee2e6)); border-radius:.25rem; background:var(--background, var(--bs-body-bg, #fff)); color:var(--foreground, var(--bs-body-color, #212529)); font-size:.8125rem; }
.mublo-editor-ex-btn { display:block; width:100%; padding:.55rem; border:none; border-radius:.375rem; background:var(--bs-primary, #0d6efd); color:#fff; font-size:.875rem; cursor:pointer; }
.mublo-editor-ex-btn:hover { filter:brightness(.92); }
`;
        document.head.appendChild(style);
    }

    /** 내보내기용 완결 HTML 문서 생성 */
    function buildDocumentHtml(editor) {
        const content = editor.getHTML();
        return `<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
body { font-family: 'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif; font-size: 11pt; line-height: 1.6; }
table { border-collapse: collapse; width: 100%; }
td, th { border: 1px solid #dee2e6; padding: 8px; }
img { max-width: 100%; height: auto; }
pre { background: #f1f3f5; padding: 12px; border-radius: 4px; font-family: Consolas, monospace; font-size: 10pt; white-space: pre-wrap; }
blockquote { margin: 1em 0; }
</style></head><body>${content}</body></html>`;
    }

    function downloadBlob(blob, filename) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    function exportWord(editor, filename) {
        // Word 는 HTML 파일(.doc)을 그대로 연다. BOM 으로 한글 인코딩 보장.
        const html = buildDocumentHtml(editor)
            .replace('<html>', '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">');
        const blob = new Blob(['﻿', html], { type: 'application/msword' });
        downloadBlob(blob, filename + '.doc');
    }

    function exportPdf(editor, filename, format, orientation) {
        const holder = document.createElement('div');
        holder.innerHTML = buildDocumentHtml(editor);
        window.html2pdf().set({
            margin: 10,
            filename: filename + '.pdf',
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'mm', format, orientation }
        }).from(holder).save();
    }

    function openDialog(editor) {
        injectCss();
        const hasPdf = typeof window.html2pdf === 'function';

        const pdfSection = hasPdf ? `
            <div class="mublo-editor-ex-section">
                <h6>📄 ${t('pdfTitle')}</h6>
                <div class="mublo-editor-ex-desc">${t('pdfDesc')}</div>
                <div class="mublo-editor-ex-row">
                    <label>${t('paper')}</label>
                    <select id="mex-format"><option value="a4">A4</option><option value="letter">Letter</option></select>
                    <label style="flex-basis:auto">${t('orientation')}</label>
                    <select id="mex-orient"><option value="portrait">${t('portrait')}</option><option value="landscape">${t('landscape')}</option></select>
                </div>
                <button type="button" class="mublo-editor-ex-btn" id="mex-pdf">📥 ${t('pdfBtn')}</button>
            </div>` : `
            <div class="mublo-editor-ex-section">
                <h6>📄 ${t('pdfTitle')}</h6>
                <div class="mublo-editor-ex-desc">${t('pdfMissing')}</div>
            </div>`;

        const body = `
            <div class="mublo-editor-ex-row" style="margin-bottom:1rem">
                <label>${t('filename')}</label>
                <input type="text" id="mex-name" value="document" style="flex:1">
            </div>
            ${pdfSection}
            <div class="mublo-editor-ex-section">
                <h6>📝 ${t('wordTitle')}</h6>
                <div class="mublo-editor-ex-desc">${t('wordDesc')}</div>
                <button type="button" class="mublo-editor-ex-btn" id="mex-word">📥 ${t('wordBtn')}</button>
            </div>
        `;

        const modal = editor.openModal(t('title'), body);
        // 다운로드 버튼 방식이므로 확인 버튼은 숨기고 취소를 닫기로
        modal.querySelector('#mublo-editor-modal-confirm').style.display = 'none';
        modal.querySelector('#mublo-editor-modal-cancel').textContent = t('close');

        const getName = () => (modal.querySelector('#mex-name').value.trim() || 'document').replace(/[\\/:*?"<>|]/g, '_');
        modal.querySelector('#mex-word').addEventListener('click', () => exportWord(editor, getName()));
        if (hasPdf) {
            modal.querySelector('#mex-pdf').addEventListener('click', () => {
                exportPdf(editor, getName(),
                    modal.querySelector('#mex-format').value,
                    modal.querySelector('#mex-orient').value);
            });
        }
    }

    MubloEditor.addToolbarItem('export', {
        icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        title: STR.ko.title,
        onClick: openDialog
    });
})();
