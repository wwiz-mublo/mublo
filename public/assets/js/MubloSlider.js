/**
 * ============================================================
 * MubloSlider.js
 * (c) 2026 Mublo
 * ============================================================
 *
 * AI 슬라이드(.mublo-slider) 전용 전역 Swiper adapter.
 * (설계: storage/docs/Mublo_AI_HTML_Frame_Generation_Improvement_Plan.md §6.4)
 *
 * ------------------------------------------------------------
 * 설계 원칙
 * ------------------------------------------------------------
 *
 * 1. 저장 HTML은 논리 구조만 담는다
 *    - section.mublo-slider > .mublo-slider-track > .mublo-slide
 *    - swiper/swiper-wrapper/swiper-slide 클래스와 transform은
 *      이 adapter가 런타임에만 부여한다 (저장 HTML에 영구 기록 금지)
 *
 * 2. new Swiper() 직접 호출은 이 adapter 내부 한 곳뿐
 *    - 블록별 신뢰 JS(TrustedBlockBehaviorBuilder)는
 *      MubloSlider.init(root, { preset, autoplaySeconds })만 호출한다
 *
 * 3. Swiper 미로드 시 예외 없이 fallback
 *    - init이 조용히 null을 반환하고, 블록 CSS의
 *      .mublo-slider:not(.is-enhanced) scroll-snap 규칙이 그대로 적용된다
 *
 * ------------------------------------------------------------
 * 프리셋 (AI behavior 계약 — 자유 형식 Swiper 옵션 불허)
 * ------------------------------------------------------------
 *
 * hero    : 큰 메시지·배너 — 모든 폭에서 1장
 * cards   : 기능·후기·상품 카드 — 모바일 1.1 / 태블릿 2 / 데스크톱 3
 * gallery : 이미지 중심 — 모바일 1.2 / 태블릿 2.2 / 데스크톱 4
 *
 * navigation·pagination·loop·breakpoints는 프리셋과 슬라이드 수로
 * Core가 결정한다. AI 슬라이드는 5초 autoplay가 기본이며 사용자가 수동
 * 전환을 요청한 경우에만 0을 전달한다. prefers-reduced-motion: reduce에서는 억제한다.
 */

window.MubloSlider = (function () {
    'use strict';

    var PRESETS = {
        hero: {
            base: { slidesPerView: 1, spaceBetween: 0 },
            breakpoints: null,
            maxView: 1
        },
        cards: {
            base: { slidesPerView: 1.1, spaceBetween: 16 },
            breakpoints: {
                768: { slidesPerView: 2, spaceBetween: 16 },
                1024: { slidesPerView: 3, spaceBetween: 20 }
            },
            maxView: 3
        },
        gallery: {
            base: { slidesPerView: 1.2, spaceBetween: 12 },
            breakpoints: {
                768: { slidesPerView: 2.2, spaceBetween: 12 },
                1024: { slidesPerView: 4, spaceBetween: 12 }
            },
            maxView: 4
        }
    };

    var instances = new WeakMap();

    function isSwiperAvailable() {
        return typeof Swiper !== 'undefined';
    }

    function prefersReducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function directSlides(track) {
        return Array.prototype.filter.call(track.children, function (child) {
            return child.classList && child.classList.contains('mublo-slide');
        });
    }

    /**
     * @param {HTMLElement} root  .mublo-slider 요소
     * @param {Object} [options]
     * @param {string} [options.preset]          "hero" | "cards" | "gallery" (기본 hero)
     * @param {number} [options.autoplaySeconds]  AI 기본 5, 0 = 자동재생 없음
     * @returns {Object|null} 인스턴스 — Swiper 미로드·구조 불충족 시 null (fallback 유지)
     */
    function init(root, options) {
        if (!root || !(root instanceof HTMLElement)) return null;
        if (instances.has(root)) return instances.get(root);
        if (!isSwiperAvailable()) return null; // scroll-snap fallback 유지

        options = options || {};
        var presetName = Object.prototype.hasOwnProperty.call(PRESETS, options.preset) ? options.preset : 'hero';
        var preset = PRESETS[presetName];

        var track = root.querySelector('.mublo-slider-track');
        var slides = track ? directSlides(track) : [];
        if (!track || slides.length < 2) return null;

        root.classList.add('swiper', 'is-enhanced');
        track.classList.add('swiper-wrapper');
        slides.forEach(function (slide) { slide.classList.add('swiper-slide'); });

        var pagination = document.createElement('div');
        pagination.className = 'swiper-pagination';
        root.appendChild(pagination);

        var swiperOptions = Object.assign({
            observer: true,
            observeParents: true,
            watchOverflow: true,
            keyboard: { enabled: true, onlyInViewport: true },
            a11y: { enabled: true },
            pagination: { el: pagination, clickable: true }
        }, preset.base);
        if (preset.breakpoints) swiperOptions.breakpoints = preset.breakpoints;

        // loop는 표시 수보다 슬라이드가 넉넉할 때만 (Swiper watchOverflow와 병행)
        if (slides.length > preset.maxView) swiperOptions.loop = true;

        var autoplaySeconds = Math.max(0, Math.min(30, parseInt(options.autoplaySeconds, 10) || 0));
        var autoplayEnabled = autoplaySeconds > 0 && !prefersReducedMotion() && slides.length > preset.maxView;
        if (autoplayEnabled) {
            swiperOptions.autoplay = {
                delay: autoplaySeconds * 1000,
                disableOnInteraction: false,
                pauseOnMouseEnter: true
            };
        }

        var swiper = new Swiper(root, swiperOptions);

        // 키보드 포커스가 슬라이더 안에 머무는 동안 autoplay 정지 (a11y)
        var onFocusIn = null;
        var onFocusOut = null;
        if (autoplayEnabled && swiper.autoplay) {
            onFocusIn = function () { swiper.autoplay.stop(); };
            onFocusOut = function (event) {
                if (!root.contains(event.relatedTarget)) swiper.autoplay.start();
            };
            root.addEventListener('focusin', onFocusIn);
            root.addEventListener('focusout', onFocusOut);
        }

        var instance = {
            root: root,
            getSwiper: function () { return swiper; },
            update: function () { swiper.update(); },
            destroy: function () {
                if (onFocusIn) root.removeEventListener('focusin', onFocusIn);
                if (onFocusOut) root.removeEventListener('focusout', onFocusOut);
                swiper.destroy(true, true);
                pagination.remove();
                slides.forEach(function (slide) { slide.classList.remove('swiper-slide'); });
                track.classList.remove('swiper-wrapper');
                root.classList.remove('swiper', 'is-enhanced');
                instances.delete(root);
            }
        };

        instances.set(root, instance);
        return instance;
    }

    function destroy(root) {
        var instance = instances.get(root);
        if (instance) instance.destroy();
    }

    return {
        init: init,
        destroy: destroy,
        get: function (root) { return instances.get(root) || null; }
    };
})();
