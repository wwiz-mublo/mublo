/**
 * front-preview-tokens.js — 프론트 디자인 토큰 프리뷰 조립기
 *
 * 블록 콘텐츠(칸 CSS)는 var(--primary) 같은 프론트 토큰을 소비하지만,
 * 관리자 문서/미리보기 iframe 에는 프론트의 토큰 캐스케이드가 없다.
 * 프론트 frame Head.php 의 실제 로드 순서를 그대로 재현한다:
 *
 *   1. tokens.css               — 코어 디자인 토큰 (단일 진실)
 *   2. 프레임 스킨 front.css     — 변수 rebind (예: --primary: var(--skin-accent))
 *      ⚠ 커스텀 프로퍼티(--*)만 추출 — 레이아웃/컴포넌트 규칙은 새지 않게 버린다
 *   3. 도메인 브랜드색            — :root --primary override (도메인 우선)
 *
 * 입력: window.MubloFrontPreviewTokens = { primaryColor, frameCssUrl } (admin Head.php)
 * 소비자:
 *   - block-html-editor/index.js  → 에디터 캔버스(.mublo-editor-content) 스코프로 주입
 *   - block-preview-iframe.js     → iframe srcdoc 에 셀렉터 원형(:root 등) 그대로 주입
 */
(function () {
    'use strict';

    var CSS_RULE_STYLE = 1;
    var CSS_RULE_MEDIA = 4;

    function conf() {
        return window.MubloFrontPreviewTokens || {};
    }

    /** 브라우저 CSS 파서로 정확히 파싱 (정규식 오파싱 방지) */
    function parseSheet(cssText) {
        var doc = document.implementation.createHTMLDocument('');
        var style = doc.createElement('style');
        style.textContent = cssText;
        doc.head.appendChild(style);
        return style.sheet;
    }

    /**
     * 선언 블록에서 커스텀 프로퍼티(--*)만 추린다.
     * cssText 직렬화를 괄호 깊이를 존중해 ';' 로 분할 — var(--a, ...) 내부 안전.
     */
    function customPropertyDecls(styleDecl) {
        var text = styleDecl.cssText || '';
        var out = [];
        var depth = 0;
        var start = 0;
        for (var i = 0; i <= text.length; i++) {
            var ch = text[i];
            if (ch === '(') depth++;
            else if (ch === ')') depth--;
            else if ((i === text.length || (ch === ';' && depth === 0))) {
                var part = text.slice(start, i).trim();
                start = i + 1;
                if (part && part.indexOf('--') === 0) out.push(part);
            }
        }
        return out;
    }

    /**
     * 룰 트리를 걸으며 커스텀 프로퍼티만 남긴 CSS 텍스트를 조립한다.
     * mapSelector(selector) 로 셀렉터를 목적지에 맞게 변환한다. @media 조건은 보존.
     */
    function walkRules(rules, mapSelector, lines) {
        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            if (rule.type === CSS_RULE_MEDIA) {
                var inner = [];
                walkRules(rule.cssRules, mapSelector, inner);
                if (inner.length) {
                    lines.push('@media ' + rule.conditionText + ' {\n' + inner.join('\n') + '\n}');
                }
            } else if (rule.type === CSS_RULE_STYLE) {
                var decls = customPropertyDecls(rule.style);
                if (!decls.length) continue;
                var selector = rule.selectorText
                    .split(',')
                    .map(function (s) { return mapSelector(s.trim()); })
                    .join(', ');
                lines.push(selector + ' { ' + decls.join('; ') + '; }');
            }
        }
    }

    function extractVars(cssText, mapSelector) {
        var lines = [];
        try {
            walkRules(parseSheet(cssText).cssRules, mapSelector, lines);
        } catch (e) { /* 파싱 실패 시 해당 층만 생략 */ }
        return lines.join('\n');
    }

    function fetchCss(url) {
        return fetch(url)
            .then(function (res) { return res.ok ? res.text() : ''; })
            .catch(function () { return ''; });
    }

    function isSafeColor(value) {
        return typeof value === 'string' && /^[#a-zA-Z0-9(),.%\s\/-]+$/.test(value);
    }

    /**
     * 프론트 토큰 캐스케이드 전체를 하나의 CSS 텍스트로 조립한다.
     *
     * @param {function} mapSelector  셀렉터 변환기 (예: ':root' → '.mublo-editor-content')
     * @param {object}   [opts]
     * @param {boolean}  [opts.coreTokens=true]  tokens.css 포함 여부
     *                   (미리보기 iframe 은 tokens.css 를 <link> 로 직접 로드하므로 false)
     * @returns {Promise<string>}
     */
    function buildCss(mapSelector, opts) {
        var includeCore = !opts || opts.coreTokens !== false;
        var urls = [];
        if (includeCore) urls.push('/assets/css/tokens.css');
        if (conf().frameCssUrl) urls.push(conf().frameCssUrl);

        return Promise.all(urls.map(fetchCss)).then(function (texts) {
            var parts = texts
                .map(function (text) { return text ? extractVars(text, mapSelector) : ''; })
                .filter(Boolean);

            var primary = conf().primaryColor;
            if (primary && isSafeColor(primary)) {
                parts.push(mapSelector(':root')
                    + ' { --primary: ' + primary + '; --color-primary: ' + primary + '; }');
            }
            return parts.join('\n');
        });
    }

    window.MubloFrontPreviewCss = { buildCss: buildCss };
})();
