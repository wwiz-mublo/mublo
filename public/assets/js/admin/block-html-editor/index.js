/**
 * BlockHtmlEditor
 *
 * Admin block code editor wrapper for HTML/CSS/JS textareas.
 * Kept separate from MubloEditor because this surface edits trusted admin code.
 */
(function () {
    'use strict';

    function resolveElement(target) {
        return typeof target === 'string' ? document.querySelector(target) : target;
    }

    // ---------------------------------------------------------
    // 프론트 디자인 토큰 주입
    //
    // 칸 CSS 는 var(--primary) 같은 프론트 토큰을 소비하지만, 에디터 캔버스는
    // 관리자 문서 안이라 프론트의 토큰 캐스케이드(tokens.css → 프레임 스킨
    // 변수 rebind → 도메인 브랜드색)가 없다. MubloFrontPreviewCss(
    // front-preview-tokens.js)로 캐스케이드 전체를 캔버스 스코프로 주입한다.
    // ---------------------------------------------------------
    const FRONT_TOKENS_STYLE_ID = 'block_html_editor_front_tokens';
    const FRONT_TOKENS_SCOPE = '.mublo-editor-content';

    function scopeSelector(selector) {
        if (selector === ':root') return FRONT_TOKENS_SCOPE;
        // .dark / [data-theme="dark"] 등은 캔버스 하위로 한정 —
        // 관리자 셸의 다크 테마가 프리뷰 토큰을 뒤집지 못하게 한다.
        return FRONT_TOKENS_SCOPE + ' ' + selector;
    }

    function injectFrontTokens() {
        if (!window.MubloFrontPreviewCss) return;
        if (document.getElementById(FRONT_TOKENS_STYLE_ID)) return;

        const style = document.createElement('style');
        style.id = FRONT_TOKENS_STYLE_ID;
        document.head.appendChild(style);

        window.MubloFrontPreviewCss.buildCss(scopeSelector)
            .then((css) => { style.textContent = css; })
            .catch(() => {});
    }

    /** CSS selector list를 괄호·문자열 내부의 쉼표를 건드리지 않고 나눈다. */
    function splitSelectorList(selectorText) {
        const selectors = [];
        let start = 0;
        let depth = 0;
        let quote = '';
        for (let i = 0; i <= selectorText.length; i++) {
            const char = selectorText[i];
            if (quote) {
                if (char === quote && selectorText[i - 1] !== '\\') quote = '';
                continue;
            }
            if (char === '"' || char === "'") quote = char;
            else if (char === '(' || char === '[') depth++;
            else if (char === ')' || char === ']') depth = Math.max(0, depth - 1);
            else if ((char === ',' && depth === 0) || i === selectorText.length) {
                const selector = selectorText.slice(start, i).trim();
                if (selector) selectors.push(selector);
                start = i + 1;
            }
        }
        return selectors;
    }

    function scopePreviewSelector(selector) {
        if (selector.includes(FRONT_TOKENS_SCOPE)) return selector;
        if (/^(?:\:root|html|body)(?=$|[\s.#:\[])/i.test(selector)) {
            return selector.replace(/^(?:\:root|html|body)/i, FRONT_TOKENS_SCOPE);
        }
        return FRONT_TOKENS_SCOPE + ' ' + selector;
    }

    /**
     * 사용자 CSS를 편집 캔버스 안으로만 한정한다.
     * @media/@supports/@container 등 그룹 규칙은 재귀적으로 보존하고,
     * @import는 관리자 문서 전체에 영향을 줄 수 있어 편집 미리보기에서만 제외한다.
     */
    function scopeCssForEditor(css) {
        if (!css.trim()) return '';
        const doc = document.implementation.createHTMLDocument('');
        const style = doc.createElement('style');
        style.textContent = css;
        doc.head.appendChild(style);

        function serializeRules(rules) {
            return Array.from(rules || []).map(rule => {
                if (rule.type === CSSRule.IMPORT_RULE) return '';
                if (rule.type === CSSRule.STYLE_RULE) {
                    const selector = splitSelectorList(rule.selectorText)
                        .map(scopePreviewSelector)
                        .join(', ');
                    return selector + ' { ' + rule.style.cssText + ' }';
                }
                if (rule.cssRules) {
                    const cssText = rule.cssText || '';
                    const brace = cssText.indexOf('{');
                    const header = brace >= 0 ? cssText.slice(0, brace).trim() : '';
                    return header ? header + ' {\n' + serializeRules(rule.cssRules) + '\n}' : '';
                }
                return rule.cssText || '';
            }).filter(Boolean).join('\n');
        }

        try {
            return serializeRules(style.sheet?.cssRules);
        } catch (error) {
            console.warn('BlockHtmlEditor: CSS 미리보기 스코프 변환 실패', error);
            return '';
        }
    }

    function getIndentBeforeCursor(text, cursor) {
        const lineStart = text.lastIndexOf('\n', cursor - 1) + 1;
        const line = text.slice(lineStart, cursor);
        const match = line.match(/^\s*/);
        return match ? match[0] : '';
    }

    class CodeEditor {
        constructor(textarea, options = {}) {
            this.textarea = resolveElement(textarea);
            this.options = {
                mode: 'html',
                tabSize: 4,
                onChange: null,
                ...options,
            };

            if (!this.textarea) {
                throw new Error('BlockHtmlEditor target not found');
            }

            this._handleInput = this._handleInput.bind(this);
            this._handleKeydown = this._handleKeydown.bind(this);
            this._init();
        }

        _init() {
            this.textarea.classList.add('bhe-textarea', `bhe-textarea--${this.options.mode}`);
            this.textarea.spellcheck = false;
            this.textarea.autocomplete = 'off';
            this.textarea.autocapitalize = 'off';
            this.textarea.wrap = 'off';

            this.textarea.addEventListener('input', this._handleInput);
            this.textarea.addEventListener('keydown', this._handleKeydown);
        }

        _handleInput() {
            this.options.onChange?.(this.getValue(), this);
        }

        _handleKeydown(e) {
            if (e.key === 'Tab') {
                this._insertTab(e);
                return;
            }

            if (e.key === 'Enter') {
                this._insertNewlineWithIndent(e);
                return;
            }

            if (['"', "'", '`', '(', '[', '{'].includes(e.key)) {
                this._insertPair(e);
            }
        }

        _insertTab(e) {
            e.preventDefault();
            const indent = ' '.repeat(this.options.tabSize);
            const start = this.textarea.selectionStart;
            const end = this.textarea.selectionEnd;
            const value = this.textarea.value;

            if (start !== end && value.slice(start, end).includes('\n')) {
                const before = value.slice(0, start);
                const selected = value.slice(start, end);
                const after = value.slice(end);
                const indented = selected.split('\n').map(line => indent + line).join('\n');
                this.textarea.value = before + indented + after;
                this.textarea.selectionStart = start;
                this.textarea.selectionEnd = start + indented.length;
            } else {
                this.textarea.value = value.slice(0, start) + indent + value.slice(end);
                this.textarea.selectionStart = this.textarea.selectionEnd = start + indent.length;
            }

            this._handleInput();
        }

        _insertNewlineWithIndent(e) {
            e.preventDefault();
            const start = this.textarea.selectionStart;
            const end = this.textarea.selectionEnd;
            const value = this.textarea.value;
            let indent = getIndentBeforeCursor(value, start);

            const prevChar = value[start - 1] || '';
            if (prevChar === '{' || prevChar === '[' || prevChar === '(' || this._isOpeningHtmlTag(value, start)) {
                indent += ' '.repeat(this.options.tabSize);
            }

            const insert = '\n' + indent;
            this.textarea.value = value.slice(0, start) + insert + value.slice(end);
            this.textarea.selectionStart = this.textarea.selectionEnd = start + insert.length;
            this._handleInput();
        }

        _insertPair(e) {
            const pairs = { '"': '"', "'": "'", '`': '`', '(': ')', '[': ']', '{': '}' };
            const close = pairs[e.key];
            if (!close) return;

            const start = this.textarea.selectionStart;
            const end = this.textarea.selectionEnd;
            const value = this.textarea.value;

            if (e.key === "'" && this.options.mode === 'css') return;
            if (start !== end) {
                e.preventDefault();
                const selected = value.slice(start, end);
                this.textarea.value = value.slice(0, start) + e.key + selected + close + value.slice(end);
                this.textarea.selectionStart = start + 1;
                this.textarea.selectionEnd = end + 1;
                this._handleInput();
                return;
            }

            const next = value[start] || '';
            if (next && /\S/.test(next) && !/[)\]}>"'`]/.test(next)) return;

            e.preventDefault();
            this.textarea.value = value.slice(0, start) + e.key + close + value.slice(end);
            this.textarea.selectionStart = this.textarea.selectionEnd = start + 1;
            this._handleInput();
        }

        _isOpeningHtmlTag(value, cursor) {
            if (this.options.mode !== 'html') return false;
            const lineStart = value.lastIndexOf('\n', cursor - 1) + 1;
            const text = value.slice(lineStart, cursor);
            return /<([a-z][a-z0-9-]*)(?:\s[^>]*)?>$/i.test(text) && !/<\/[a-z][^>]*>$/i.test(text);
        }

        getValue() {
            return this.textarea.value;
        }

        setValue(value) {
            this.textarea.value = value ?? '';
            this._handleInput();
        }

        focus() {
            this.textarea.focus();
            return this;
        }

        destroy() {
            this.textarea.removeEventListener('input', this._handleInput);
            this.textarea.removeEventListener('keydown', this._handleKeydown);
            this.textarea.classList.remove('bhe-textarea', `bhe-textarea--${this.options.mode}`);
        }
    }

    class VisualEditor {
        constructor(target, options = {}) {
            this.textarea = resolveElement(target);
            this.options = {
                toolbar: 'landing',
                height: 500,
                toolbarItems: [
                    'source', 'separator', 'undo', 'redo', 'separator', 'fontsize', 'separator',
                    'bold', 'italic', 'underline', 'separator', 'forecolor', 'backcolor', 'separator',
                    'alignleft', 'aligncenter', 'alignright', 'separator', 'link', 'unlink',
                    'image', 'video', 'separator', 'fullscreen'
                ],
                css: '',
                ...options,
            };
            this.instance = null;
            this.cssStyleId = `${this.textarea?.id || 'block_html'}_visual_css`;
            this.overrideStyleId = `${this.textarea?.id || 'block_html'}_visual_overrides`;

            if (!this.textarea) {
                throw new Error('BlockHtmlEditor visual target not found');
            }

            this._init();
        }

        _init() {
            this.textarea.dataset.toolbar = this.options.toolbar;
            this.textarea.dataset.height = String(this.options.height);
            this.textarea.dataset.toolbarItems = this.options.toolbarItems.join(',');

            if (typeof BlockHtmlEditorBase === 'undefined') {
                throw new Error('BlockHtmlEditor visual mode requires the BlockHtmlEditorBase script');
            }

            this.instance = BlockHtmlEditorBase.get(this.textarea.id);
            if (!this.instance) {
                this.instance = BlockHtmlEditorBase.create(this.textarea);
            }

            injectFrontTokens();
            this.injectCss(this.options.css);
            this._applyEditorOverrides();
        }

        getHTML() {
            if (this.instance?.getHTML) {
                return this.instance.getHTML();
            }
            return this.textarea.value || '';
        }

        setHTML(html) {
            if (this.instance?.setHTML) {
                this.instance.setHTML(html || '');
            } else {
                this.textarea.value = html || '';
            }
            this.injectCss(this.options.css);
        }

        sync() {
            this.instance?.sync?.();
            return this;
        }

        focus() {
            this.instance?.focus?.();
            return this;
        }

        injectCss(css) {
            this.options.css = css || '';
            let style = document.getElementById(this.cssStyleId);

            if (!this.options.css.trim()) {
                style?.remove();
                return;
            }

            if (!style) {
                style = document.createElement('style');
                style.id = this.cssStyleId;
                document.head.appendChild(style);
            }

            const previewCss = this.options.css
                .replace(/-webkit-text-fill-color\s*:\s*transparent\s*;?/gi, '')
                .replace(/background-clip\s*:\s*text\s*;?/gi, '')
                .replace(/-webkit-background-clip\s*:\s*text\s*;?/gi, '')
                .replace(/opacity\s*:\s*0\s*;?/gi, 'opacity:1;');
            style.textContent = scopeCssForEditor(previewCss);
        }

        _applyEditorOverrides() {
            if (document.getElementById(this.overrideStyleId)) return;

            const wrapperClass = 'block-html-editor-wrapper';
            const style = document.createElement('style');
            style.id = this.overrideStyleId;
            // 테마(라이트 고정)는 호스트 컨테이너의 data-bs-theme 가 담당 — 여기선 슬라이더 구조만.
            style.textContent = [
                `.${wrapperClass} .mublo-editor-content .swiper{overflow:visible!important;height:auto!important;position:static!important;display:block!important}`,
                `.${wrapperClass} .mublo-editor-content .swiper-wrapper{display:block!important;height:auto!important;transform:none!important;flex-direction:column!important}`,
                `.${wrapperClass} .mublo-editor-content .swiper-slide{width:100%!important;height:auto!important;position:static!important;margin-bottom:8px;display:block!important;flex-shrink:initial!important;transform:none!important}`,
                `.${wrapperClass} .mublo-editor-content .swiper-pagination,.${wrapperClass} .mublo-editor-content .swiper-button-prev,.${wrapperClass} .mublo-editor-content .swiper-button-next{display:none!important}`,
                // AI 슬라이드(개선 계획 §6.4): 저장 CSS의 scroll-snap fallback을 무력화하고
                // 모든 슬라이드를 세로로 펼쳐 직접 선택·수정할 수 있게 한다
                `.${wrapperClass} .mublo-editor-content .mublo-slider-track{display:block!important;overflow-x:visible!important}`,
                `.${wrapperClass} .mublo-editor-content .mublo-slide{margin-bottom:8px}`
            ].join('\n');
            document.head.appendChild(style);
        }
    }

    const visualInstances = new Map();

    window.BlockHtmlEditor = {
        create(target, options = {}) {
            return new CodeEditor(target, options);
        },
        createVisual(target, options = {}) {
            const el = resolveElement(target);
            if (!el) {
                throw new Error('BlockHtmlEditor visual target not found');
            }

            if (visualInstances.has(el.id)) {
                const existing = visualInstances.get(el.id);
                if (options.css !== undefined) existing.injectCss(options.css);
                return existing;
            }

            const editor = new VisualEditor(el, options);
            visualInstances.set(el.id, editor);
            return editor;
        },
        getVisual(id) {
            return visualInstances.get(id) || null;
        },
    };
})();
