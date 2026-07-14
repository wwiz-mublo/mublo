/**
 * ============================================================
 * MubloModal.js - Mublo Framework 모달 시스템
 * ============================================================
 *
 * 인스턴스 기반 모달 시스템.
 *
 * 의존 (선택): MubloRequest.js (Remote Load 사용 시)
 *
 * 인스턴스 API:
 *   const modal = new MubloModal({ id, title, content, url, ... })
 *   modal.open()
 *   modal.close()
 *   modal.setContent(html)
 *   modal.setLoading(bool)
 *
 * 편의 정적 메서드:
 *   MubloModal.alert(message, title)
 *   MubloModal.confirm(message, title) → Promise<boolean>
 *
 * ============================================================
 */

class MubloModal {

    static _cssLoaded = false;
    static _instances = new Map();

    // 배경 스크롤 잠금 — MubloRequest 와 동일한 기법(body overflow:hidden + 스크롤바 폭 보정)으로
    // 통일한다. 기존 html.noscroll(position:fixed) 방식은 자체 스크롤 컨테이너를 쓰는 관리자
    // 레이아웃에서 실제 스크롤을 못 잡았다. ref-count 로 다중 모달 스택을 처리하고, 잠그기 직전
    // body 인라인 스타일을 보존했다가 마지막 해제 때 복원해 외부(부트스트랩 백드랍·MubloRequest)
    // 락 위에 겹쳐도 이중 보정/충돌이 없다.
    static _scrollLockCount = 0;
    static _savedBodyOverflow = '';
    static _savedBodyPaddingRight = '';

    /**
     * @param {Object} options
     * @param {string} options.id          모달 고유 ID
     * @param {string} [options.title]     모달 제목 (빈 문자열이면 헤더 생략)
     * @param {string} [options.className] 추가 CSS 클래스 (modal-sm, modal-lg, modal-xl, modal-full)
     * @param {string} [options.content]   모달 본문 HTML
     * @param {string} [options.url]       Remote Load URL (MubloRequest 필요)
     * @param {string} [options.footer]    모달 푸터 HTML
     * @param {Function} [options.onBeforeOpen]
     * @param {Function} [options.onAfterOpen]
     * @param {Function} [options.onBeforeClose]
     */
    constructor(options = {}) {
        this.id = options.id || 'mubloModal_' + Date.now();
        this.title = options.title ?? '';
        this.className = options.className || '';
        this.content = options.content || '';
        this.url = options.url || null;
        this.footer = options.footer || '';
        this.onBeforeOpen = options.onBeforeOpen || null;
        this.onAfterOpen = options.onAfterOpen || null;
        this.onBeforeClose = options.onBeforeClose || null;

        this._element = null;
    }

    /* =========================================================
     * 인스턴스 메서드
     * ========================================================= */

    open() {
        if (this.onBeforeOpen && this.onBeforeOpen() === false) return;

        MubloModal._loadCSS();
        this._removeExisting();
        this._createElement();

        if (this.url) {
            this._loadRemote();
        }

        MubloModal._instances.set(this.id, this);
    }

    close() {
        if (!this._element) return;
        if (this.onBeforeClose && this.onBeforeClose() === false) return;

        const content = this._element.querySelector('.customModal-content');
        if (content) content.classList.remove('open');

        setTimeout(() => {
            if (this._element) {
                this._element.style.display = 'none';
                this._element.remove();
                this._element = null;
            }
            MubloModal._instances.delete(this.id);
            if (this._scrollLocked) {
                MubloModal._unlockScroll();
                this._scrollLocked = false;
            }
        }, 200);
    }

    setContent(html) {
        if (!this._element) return;
        const body = this._element.querySelector('.customModal-body');
        if (body) body.innerHTML = html;
    }

    setLoading(show) {
        if (!this._element) return;
        const body = this._element.querySelector('.customModal-body');
        if (!body) return;

        if (show) {
            body.innerHTML = '<div class="customModal-loading"></div>';
        }
    }

    /* =========================================================
     * 내부 메서드
     * ========================================================= */

    _removeExisting() {
        const existing = document.getElementById(this.id);
        if (existing) existing.remove();
    }

    _createElement() {
        const html = `
            <div id="${this.id}" class="customModal ${this.className}">
                <div class="customModal-dialog">
                    <div class="customModal-content">
                        ${this.title ? `<div class="customModal-header"><div class="header-title">${this.title}</div><button type="button" class="closex" aria-label="닫기"><i class="bi bi-x-lg"></i></button></div>` : ''}
                        <div class="customModal-body">${this.url ? '<div class="customModal-loading"></div>' : this.content}</div>
                        ${this.footer ? `<div class="customModal-footer">${this.footer}</div>` : ''}
                    </div>
                </div>
            </div>`;

        document.body.insertAdjacentHTML('beforeend', html);

        this._element = document.getElementById(this.id);
        this._element.style.display = 'flex';

        // 배경 스크롤 잠금 (인스턴스당 1회, ref-count 공유)
        if (!this._scrollLocked) {
            MubloModal._lockScroll();
            this._scrollLocked = true;
        }

        // 애니메이션 트리거 (다음 프레임)
        requestAnimationFrame(() => {
            const content = this._element?.querySelector('.customModal-content');
            if (content) content.classList.add('open');
            if (this.onAfterOpen) this.onAfterOpen();
        });
    }

    async _loadRemote() {
        if (typeof MubloRequest === 'undefined') {
            console.error('[MubloModal] Remote Load requires MubloRequest.js');
            this.setContent('<p>MubloRequest.js가 로드되지 않았습니다.</p>');
            return;
        }

        try {
            const result = await MubloRequest.requestQuery(this.url);
            this.setContent(result.data?.html || result.data || '');
        } catch (e) {
            this.setContent('<p>데이터를 불러오지 못했습니다.</p>');
            console.error('[MubloModal] Remote Load Error:', e);
        }
    }

    /* =========================================================
     * 편의 정적 메서드
     * ========================================================= */

    /**
     * 알림 모달
     *
     * @param {string} message  메시지
     * @param {string} [title]  제목 (기본: '알림')
     * @returns {MubloModal}
     */
    /**
     * HTML 이스케이프 — 텍스트 메시지를 innerHTML에 넣기 전 스크립트 주입을 차단한다.
     * (title/content/footer는 개발자가 의도적으로 HTML을 넣는 자리라 이스케이프하지 않는다.)
     */
    static _escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    /**
     * 메시지 포매팅 — 먼저 이스케이프(XSS 방어)한 뒤, 줄바꿈(\n)만 <br>로 되살린다.
     * 이스케이프가 선행되므로 입력에 들어온 실제 "<br>" 문자열은 여전히 무력화된다.
     */
    static _formatMessage(str) {
        return MubloModal._escapeHtml(str).replace(/\n/g, '<br>');
    }

    static alert(message, title = '알림') {
        const modal = new MubloModal({
            title,
            content: `<p>${MubloModal._formatMessage(message)}</p>`,
            className: 'modal-sm',
            footer: '<button type="button" class="customModal-btn customModal-btn--primary closex">확인</button>',
        });
        modal.open();
        return modal;
    }

    /**
     * 확인 모달 (Promise 반환)
     *
     * @param {string} message  메시지
     * @param {string} [title]  제목 (기본: '확인')
     * @returns {Promise<boolean>}
     */
    static confirm(message, title = '확인') {
        return new Promise((resolve) => {
            const id = 'mubloConfirm_' + Date.now();
            const modal = new MubloModal({
                id,
                title,
                content: `<p>${MubloModal._formatMessage(message)}</p>`,
                className: 'modal-sm',
                footer: `
                    <button type="button" class="customModal-btn customModal-btn--secondary" data-action="cancel">취소</button>
                    <button type="button" class="customModal-btn customModal-btn--primary" data-action="confirm">확인</button>
                `,
                onBeforeClose: () => {
                    resolve(false);
                },
            });

            modal.open();

            // 버튼 이벤트 바인딩
            const el = document.getElementById(id);
            if (el) {
                el.querySelector('[data-action="confirm"]')?.addEventListener('click', () => {
                    modal.onBeforeClose = null; // cancel 콜백 방지
                    modal.close();
                    resolve(true);
                });
                el.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
                    modal.onBeforeClose = null;
                    modal.close();
                    resolve(false);
                });
            }
        });
    }

    /**
     * 입력 모달 (Promise 반환) — 경고문과 입력창을 한 화면에 표시한다.
     * "초기화" 같은 확인 문구 직접 입력 등 오조작 방어용.
     *
     * @param {string} message           안내 메시지 (줄바꿈은 \n)
     * @param {string} [title]           제목 (기본: '입력')
     * @param {Object} [options]
     * @param {string} [options.placeholder]   입력창 placeholder
     * @param {string} [options.defaultValue]  입력창 초기값
     * @param {string} [options.confirmText]   확인 버튼 문구 (기본: '확인')
     * @param {string} [options.cancelText]    취소 버튼 문구 (기본: '취소')
     * @returns {Promise<string|null>}   입력값(취소 시 null)
     */
    static prompt(message, title = '입력', options = {}) {
        return new Promise((resolve) => {
            const id = 'mubloPrompt_' + Date.now();
            const inputId = id + '_input';
            const placeholder = MubloModal._escapeHtml(options.placeholder || '');
            const defaultValue = MubloModal._escapeHtml(options.defaultValue || '');
            const confirmText = options.confirmText || '확인';
            const cancelText = options.cancelText || '취소';

            const modal = new MubloModal({
                id,
                title,
                content: `<p>${MubloModal._formatMessage(message)}</p>`
                       + `<input type="text" id="${inputId}" class="customModal-input" `
                       + `placeholder="${placeholder}" value="${defaultValue}" autocomplete="off">`,
                className: 'modal-sm',
                footer: `
                    <button type="button" class="customModal-btn customModal-btn--secondary" data-action="cancel">${cancelText}</button>
                    <button type="button" class="customModal-btn customModal-btn--primary" data-action="confirm">${confirmText}</button>
                `,
                onBeforeClose: () => {
                    resolve(null);
                },
                onAfterOpen: () => {
                    document.getElementById(inputId)?.focus();
                },
            });

            modal.open();

            const el = document.getElementById(id);
            const input = document.getElementById(inputId);

            const submit = () => {
                modal.onBeforeClose = null; // cancel 콜백 방지
                modal.close();
                resolve(input ? input.value : '');
            };
            const cancel = () => {
                modal.onBeforeClose = null;
                modal.close();
                resolve(null);
            };

            if (el) {
                el.querySelector('[data-action="confirm"]')?.addEventListener('click', submit);
                el.querySelector('[data-action="cancel"]')?.addEventListener('click', cancel);
            }
            input?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); submit(); }
            });
        });
    }

    /* =========================================================
     * CSS 로드 / 스크롤 관리
     * ========================================================= */

    static _loadCSS() {
        if (MubloModal._cssLoaded) return;
        if (document.querySelector('link[href*="components/mublo-modal.css"]')) {
            MubloModal._cssLoaded = true;
            return;
        }

        // 캐시버스팅된 URL은 MubloModal.js 태그의 data-css(서버가 asset()로 주입)에서. 없으면 raw 폴백.
        const tag = document.querySelector('script[src*="MubloModal.js"]');
        const href = (tag && tag.dataset.css) ? tag.dataset.css : '/assets/css/components/mublo-modal.css';

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id = 'mubloModalCSS';
        link.href = href;
        document.head.appendChild(link);
        MubloModal._cssLoaded = true;
    }

    static _lockScroll() {
        if (MubloModal._scrollLockCount === 0) {
            // 이미 외부(MubloRequest·부트스트랩 등)가 body 를 잠갔으면 스크롤바가 없어
            // scrollbarWidth 가 0 이 되므로 padding 을 덧대지 않는다(이중 보정 방지).
            MubloModal._savedBodyOverflow = document.body.style.overflow;
            MubloModal._savedBodyPaddingRight = document.body.style.paddingRight;
            const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (scrollbarWidth > 0) document.body.style.paddingRight = `${scrollbarWidth}px`;
        }
        MubloModal._scrollLockCount++;
    }

    static _unlockScroll() {
        MubloModal._scrollLockCount = Math.max(0, MubloModal._scrollLockCount - 1);
        if (MubloModal._scrollLockCount === 0) {
            document.body.style.overflow = MubloModal._savedBodyOverflow;
            document.body.style.paddingRight = MubloModal._savedBodyPaddingRight;
        }
    }

    /* =========================================================
     * 이벤트 위임 (닫기)
     * ========================================================= */

    static _initEventDelegation() {
        document.addEventListener('click', function (e) {
            if (
                e.target.closest('.closex') ||
                e.target.classList.contains('customModal') ||
                e.target.classList.contains('layer_btn_close')
            ) {
                const el = e.target.closest('.customModal');
                if (!el) return;
                const instance = MubloModal._instances.get(el.id);
                if (instance) {
                    instance.close();
                } else {
                    const content = el.querySelector('.customModal-content');
                    if (content) content.classList.remove('open');
                    // 우리 인스턴스가 아닌 외래 .customModal — ref-count 에 참여한 적 없으므로 해제도 없음
                    setTimeout(() => { el.remove(); }, 200);
                }
            }
        });
    }
}

// 자동 초기화
document.addEventListener('DOMContentLoaded', () => MubloModal._initEventDelegation());
