(function () {
    'use strict';

    const LANGUAGE_NAMES = {
        bash: 'Bash',
        code: 'Code',
        css: 'CSS',
        html: 'HTML',
        javascript: 'JavaScript',
        js: 'JavaScript',
        json: 'JSON',
        jsx: 'JSX',
        markdown: 'Markdown',
        md: 'Markdown',
        php: 'PHP',
        plaintext: 'Text',
        python: 'Python',
        py: 'Python',
        shell: 'Shell',
        sql: 'SQL',
        text: 'Text',
        ts: 'TypeScript',
        tsx: 'TSX',
        typescript: 'TypeScript',
        xml: 'XML',
        yaml: 'YAML',
        yml: 'YAML'
    };

    function detectLanguage(pre, code) {
        const explicit = code.dataset.language || pre.dataset.language;
        const classNames = [code.className, pre.className].filter(Boolean).join(' ');
        const classMatch = classNames.match(/(?:lang(?:uage)?)-([a-z0-9_+-]+)/i);
        const brushMatch = classNames.match(/brush:\s*([a-z0-9_+-]+)/i);
        const language = String(explicit || classMatch?.[1] || brushMatch?.[1] || 'code').toLowerCase();

        return LANGUAGE_NAMES[language] || language;
    }

    function legacyCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            const copied = document.execCommand('copy');
            if (!copied) {
                throw new Error('copy command failed');
            }
        } finally {
            textarea.remove();
        }
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            try {
                legacyCopy(text);
                resolve();
            } catch (error) {
                reject(error);
            }
        });
    }

    function setCopyState(button, label, stateClass) {
        button.setAttribute('aria-label', label);
        button.title = label;
        button.classList.remove('board-code-block__copy--success', 'board-code-block__copy--error');
        if (stateClass) {
            button.classList.add(stateClass);
        }
    }

    function enhance(pre) {
        if (pre.dataset.boardCodeEnhanced === 'true') {
            return;
        }

        const code = pre.querySelector(':scope > code') || pre;
        const source = code.textContent || '';
        const wrapper = document.createElement('div');
        const toolbar = document.createElement('div');
        const dots = document.createElement('span');
        const language = document.createElement('span');
        const copyButton = document.createElement('button');

        wrapper.className = 'board-code-block';
        toolbar.className = 'board-code-block__toolbar';
        dots.className = 'board-code-block__dots';
        dots.setAttribute('aria-hidden', 'true');
        language.className = 'board-code-block__language';
        language.textContent = detectLanguage(pre, code);
        copyButton.type = 'button';
        copyButton.className = 'board-code-block__copy';
        copyButton.setAttribute('aria-label', '코드 복사');
        copyButton.title = '코드 복사';

        copyButton.addEventListener('click', function () {
            copyText(source).then(function () {
                setCopyState(copyButton, '코드가 복사되었습니다', 'board-code-block__copy--success');
            }).catch(function () {
                setCopyState(copyButton, '코드 복사에 실패했습니다', 'board-code-block__copy--error');
            }).finally(function () {
                window.setTimeout(function () {
                    setCopyState(copyButton, '코드 복사', '');
                }, 1800);
            });
        });

        toolbar.append(dots, language, copyButton);
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.append(toolbar, pre);
        pre.dataset.boardCodeEnhanced = 'true';
    }

    function enhanceAll() {
        document.querySelectorAll('.board-view__content pre').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceAll, { once: true });
    } else {
        enhanceAll();
    }
})();
