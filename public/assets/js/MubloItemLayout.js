/**
 * MubloItemLayout.js
 *
 * 서버가 만든 <ul><li> 콘텐츠에 list / slide / none 반응형
 * 레이아웃을 적용하는 공용 런타임. HTML을 다시 그리지 않고
 * .mublo-item-layout의 data-* 선언만 해석한다.
 *
 * @example
 * <div class="mublo-item-layout"
 *      data-pc-style="slide" data-mo-style="list"
 *      data-pc-cols="4" data-mo-cols="2"
 *      data-swiper='{"spaceBetween":10,"loop":true}'>
 *   <ul><li>...</li></ul>
 * </div>
 */
window.MubloItemLayout = (function () {
    'use strict';

    const SELECTOR = '.mublo-item-layout';
    const DEFAULT_BREAKPOINT = 768;
    const DEBOUNCE_DELAY = 150;

    // id API 하위 호환용 저장소 + DOM 요소 기준 중복 방지.
    const instances = Object.create(null);
    const elementInstances = new WeakMap();
    let idCounter = 0;
    let mutationObserver = null;

    function debounce(fn, delay) {
        let timer = null;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(fn, delay);
        };
    }

    // 레이아웃별 resize 리스너 대신 페이지 하나의 디바운스 리스너를 공유한다.
    const resizeCallbacks = new Set();
    const notifyResize = debounce(function () {
        resizeCallbacks.forEach(function (callback) { callback(); });
    }, DEBOUNCE_DELAY);

    const reducedMotionQuery = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;
    const motionCallbacks = new Set();
    const notifyMotionChange = function () {
        motionCallbacks.forEach(function (callback) { callback(); });
    };
    if (reducedMotionQuery) {
        if (typeof reducedMotionQuery.addEventListener === 'function') {
            reducedMotionQuery.addEventListener('change', notifyMotionChange);
        } else if (typeof reducedMotionQuery.addListener === 'function') {
            reducedMotionQuery.addListener(notifyMotionChange);
        }
    }

    function parseJsonAttr(el, attr) {
        const raw = el.getAttribute(attr);
        if (!raw) return null;
        try {
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
        } catch (e) {
            console.warn('MubloItemLayout: ' + attr + ' JSON 파싱 실패', e);
            return null;
        }
    }

    function isSwiperAvailable() {
        return typeof Swiper !== 'undefined';
    }

    function isMobileViewport(breakpoint) {
        // CSS max-width: 767.98px과 맞춰 768px부터 PC로 본다.
        return window.innerWidth < breakpoint;
    }

    function prefersReducedMotion() {
        return !!(reducedMotionQuery && reducedMotionQuery.matches);
    }

    function normalizeMode(value) {
        return value === 'slide' || value === 'none' ? value : 'list';
    }

    function registerResponsiveCallback(callback) {
        if (resizeCallbacks.size === 0) window.addEventListener('resize', notifyResize);
        resizeCallbacks.add(callback);
        motionCallbacks.add(callback);
    }

    function unregisterResponsiveCallback(callback) {
        resizeCallbacks.delete(callback);
        motionCallbacks.delete(callback);
        if (resizeCallbacks.size === 0) window.removeEventListener('resize', notifyResize);
    }

    function nextBoxId() {
        let id;
        do {
            id = 'mublo-il-' + (++idCounter);
        } while (Object.prototype.hasOwnProperty.call(instances, id) || document.getElementById(id));
        return id;
    }

    function numericSlidesPerView(options) {
        let value = options.slidesPerView;
        if (options.breakpoints && typeof options.breakpoints === 'object') {
            Object.keys(options.breakpoints)
                .map(Number)
                .filter(function (point) { return Number.isFinite(point) && point <= window.innerWidth; })
                .sort(function (a, b) { return a - b; })
                .forEach(function (point) {
                    const candidate = options.breakpoints[point]?.slidesPerView;
                    if (candidate !== undefined) value = candidate;
                });
        }
        return typeof value === 'number' && Number.isFinite(value) ? value : 1;
    }

    /** 단일 .mublo-item-layout 초기화. */
    function init(el, options) {
        if (!el || !(el instanceof HTMLElement)) {
            console.error('MubloItemLayout: 유효한 HTMLElement가 필요합니다.');
            return null;
        }
        if (elementInstances.has(el)) return elementInstances.get(el);

        options = options || {};
        let boxId = el.id;
        if (!boxId || Object.prototype.hasOwnProperty.call(instances, boxId)) {
            boxId = nextBoxId();
            el.id = boxId;
        }

        // 스킨 계약은 직계 <ul><li>다. 내부 콘텐츠의 다른 ul은 건드리지 않는다.
        const ul = el.querySelector(':scope > ul');
        if (!ul) {
            console.warn('MubloItemLayout: 직계 <ul> 요소를 찾을 수 없습니다.', boxId);
            return null;
        }

        const pcStyle = normalizeMode(el.dataset.pcStyle || 'list');
        const moStyle = normalizeMode(el.dataset.moStyle || 'list');
        const breakpoint = Math.max(1, parseInt(el.dataset.breakpoint, 10) || DEFAULT_BREAKPOINT);
        const swiperOptions = options.swiperOptions || parseJsonAttr(el, 'data-swiper') || {};
        const pcAutoplay = Math.max(0, parseInt(el.dataset.pcAutoplay, 10) || 0);
        const moAutoplay = Math.max(0, parseInt(el.dataset.moAutoplay, 10) || 0);
        const pcLoop = el.dataset.pcLoop === 'true';
        const moLoop = el.dataset.moLoop === 'true';
        const pcSlideCover = el.dataset.pcSlideCover === 'true';
        const moSlideCover = el.dataset.moSlideCover === 'true';

        let swiperInstance = null;
        let currentMode = null;
        let lastIsMobile = null;
        let lastReducedMotion = null;
        let coverGeneration = 0;
        let onFocusIn = null;
        let onFocusOut = null;

        function directItems() {
            return ul.querySelectorAll(':scope > li');
        }

        function clearSlideCover() {
            coverGeneration++;
            el.classList.remove('has-fixed-height');
            el.style.removeProperty('--slide-height');
        }

        function removeAutoplayFocusHandlers() {
            if (onFocusIn) el.removeEventListener('focusin', onFocusIn);
            if (onFocusOut) el.removeEventListener('focusout', onFocusOut);
            onFocusIn = null;
            onFocusOut = null;
        }

        function destroySwiper() {
            removeAutoplayFocusHandlers();
            if (swiperInstance) {
                swiperInstance.destroy(true, true);
                swiperInstance = null;
            }
            el.classList.remove('swiper', 'is-slide');
            ul.classList.remove('swiper-wrapper');
            directItems().forEach(function (li) { li.classList.remove('swiper-slide'); });
            clearSlideCover();
        }

        function enableListMode() {
            destroySwiper();
            el.classList.remove('is-hidden');
            el.classList.add('is-list');
            currentMode = 'list';
        }

        function enableHiddenMode() {
            destroySwiper();
            el.classList.remove('is-list');
            el.classList.add('is-hidden');
            currentMode = 'none';
        }

        function isActiveCover() {
            return isMobileViewport(breakpoint) ? moSlideCover : pcSlideCover;
        }

        function applySlideCover() {
            const generation = ++coverGeneration;
            const imgs = el.querySelectorAll('.swiper-slide img');
            if (!imgs.length) return;

            const promises = Array.from(imgs).map(function (img) {
                if (img.complete) return Promise.resolve(img);
                return new Promise(function (resolve) {
                    img.addEventListener('load', function () { resolve(img); }, { once: true });
                    img.addEventListener('error', function () { resolve(img); }, { once: true });
                });
            });

            Promise.all(promises).then(function () {
                if (generation !== coverGeneration || currentMode !== 'slide' || !isActiveCover()) return;

                let minHeight = Infinity;
                imgs.forEach(function (img) {
                    const previousHeight = img.style.height;
                    const previousFit = img.style.objectFit;
                    img.style.height = 'auto';
                    img.style.objectFit = '';
                    const height = img.getBoundingClientRect().height;
                    img.style.height = previousHeight;
                    img.style.objectFit = previousFit;
                    if (height > 0 && height < minHeight) minHeight = height;
                });

                if (minHeight === Infinity) return;
                el.classList.add('has-fixed-height');
                el.style.setProperty('--slide-height', Math.round(minHeight) + 'px');
                swiperInstance?.update();
            });
        }

        function refreshSlideCover() {
            clearSlideCover();
            if (isActiveCover()) applySlideCover();
        }

        function enableSlideMode() {
            if (!isSwiperAvailable()) {
                console.warn('MubloItemLayout: Swiper가 없어 list 모드로 전환합니다.', boxId);
                enableListMode();
                return;
            }
            if (swiperInstance) {
                swiperInstance.update();
                refreshSlideCover();
                return;
            }

            el.classList.add('swiper', 'is-slide');
            el.classList.remove('is-list', 'is-hidden');
            ul.classList.add('swiper-wrapper');
            directItems().forEach(function (li) { li.classList.add('swiper-slide'); });

            const rawPcCols = el.dataset.pcCols;
            const rawMoCols = el.dataset.moCols;
            const pcCols = rawPcCols === 'auto' ? 'auto' : (parseInt(rawPcCols, 10) || 1);
            const moCols = rawMoCols === 'auto' ? 'auto' : (parseInt(rawMoCols, 10) || 1);
            const activeCols = isMobileViewport(breakpoint) ? moCols : pcCols;
            const defaults = {
                observer: true,
                observeParents: true,
                watchOverflow: true,
                keyboard: { enabled: true, onlyInViewport: true },
                a11y: { enabled: true }
            };

            if (swiperOptions.slidesPerView === undefined) {
                defaults.slidesPerView = activeCols;
                if (activeCols === 'auto' || activeCols > 1) defaults.spaceBetween = 16;

                if (pcStyle === 'slide' && moStyle === 'slide' && pcCols !== moCols) {
                    defaults.slidesPerView = moCols;
                    if (moCols === 'auto' || moCols > 1) defaults.spaceBetween = 16;
                    defaults.breakpoints = {};
                    defaults.breakpoints[breakpoint] = { slidesPerView: pcCols };
                    if (pcCols === 'auto' || pcCols > 1) defaults.breakpoints[breakpoint].spaceBetween = 16;
                }
            }

            const merged = Object.assign({}, defaults, swiperOptions);
            const slideCount = directItems().length;
            const slidesPerView = numericSlidesPerView(merged);
            const mobile = isMobileViewport(breakpoint);

            if (swiperOptions.autoplay === undefined) {
                const delay = mobile ? moAutoplay : pcAutoplay;
                if (delay > 0 && slideCount > slidesPerView && !prefersReducedMotion()) {
                    merged.autoplay = {
                        delay: delay,
                        disableOnInteraction: false,
                        pauseOnMouseEnter: true
                    };
                }
            }
            if (swiperOptions.loop === undefined) {
                const loop = mobile ? moLoop : pcLoop;
                if (loop && slideCount > slidesPerView) merged.loop = true;
            }
            if (prefersReducedMotion()) merged.autoplay = false;
            if (merged.loop && slideCount <= numericSlidesPerView(merged)) merged.loop = false;

            swiperInstance = new Swiper(el, merged);
            currentMode = 'slide';

            if (merged.autoplay && swiperInstance.autoplay) {
                onFocusIn = function () { swiperInstance?.autoplay?.stop(); };
                onFocusOut = function (event) {
                    if (!el.contains(event.relatedTarget)) swiperInstance?.autoplay?.start();
                };
                el.addEventListener('focusin', onFocusIn);
                el.addEventListener('focusout', onFocusOut);
            }
            refreshSlideCover();
        }

        function applyMode() {
            const mobile = isMobileViewport(breakpoint);
            const reducedMotion = prefersReducedMotion();
            const targetMode = mobile ? moStyle : pcStyle;

            el.classList.toggle('is-mobile-layout', mobile);
            el.classList.toggle('is-pc-layout', !mobile);

            if (targetMode === currentMode) {
                if (currentMode === 'slide'
                    && ((lastIsMobile !== null && lastIsMobile !== mobile)
                        || (lastReducedMotion !== null && lastReducedMotion !== reducedMotion))) {
                    enableListMode();
                    enableSlideMode();
                } else if (currentMode === 'slide') {
                    swiperInstance?.update();
                    refreshSlideCover();
                }
                lastIsMobile = mobile;
                lastReducedMotion = reducedMotion;
                return;
            }

            lastIsMobile = mobile;
            lastReducedMotion = reducedMotion;
            if (targetMode === 'slide') enableSlideMode();
            else if (targetMode === 'none') enableHiddenMode();
            else enableListMode();
        }

        let instance = null;
        instance = {
            el: el,
            refresh: applyMode,
            update: function () {
                swiperInstance?.update();
                if (currentMode === 'slide') refreshSlideCover();
            },
            getSwiper: function () { return swiperInstance; },
            destroy: function () {
                destroySwiper();
                el.classList.remove('is-list', 'is-hidden', 'is-mobile-layout', 'is-pc-layout');
                currentMode = null;
                unregisterResponsiveCallback(applyMode);
                elementInstances.delete(el);
                if (instances[boxId] === instance) delete instances[boxId];
            }
        };

        instances[boxId] = instance;
        elementInstances.set(el, instance);
        registerResponsiveCallback(applyMode);
        applyMode();
        return instance;
    }

    function initAll(root) {
        const scope = root && root.querySelectorAll ? root : document;
        if (scope.matches?.(SELECTOR)) init(scope);
        scope.querySelectorAll(SELECTOR).forEach(function (element) { init(element); });
    }

    function destroy(target) {
        const instance = target instanceof HTMLElement ? elementInstances.get(target) : instances[target];
        if (instance) instance.destroy();
    }

    function layoutsIn(node) {
        if (!node || node.nodeType !== 1) return [];
        const found = [];
        if (node.matches?.(SELECTOR)) found.push(node);
        if (node.querySelectorAll) found.push(...node.querySelectorAll(SELECTOR));
        return found;
    }

    /** 동적으로 추가된 블록은 초기화하고, 제거된 블록은 리스너까지 정리한다. */
    function observeDynamicLayouts() {
        if (mutationObserver || typeof MutationObserver === 'undefined' || !document.body) return;
        mutationObserver = new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.removedNodes.forEach(function (node) {
                    layoutsIn(node).forEach(function (layout) { destroy(layout); });
                });
                record.addedNodes.forEach(function (node) {
                    layoutsIn(node).forEach(function (layout) { init(layout); });
                });
            });
        });
        mutationObserver.observe(document.body, { childList: true, subtree: true });
    }

    function start() {
        initAll();
        observeDynamicLayouts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }

    return {
        init: init,
        initAll: initAll,
        destroy: destroy,
        refreshAll: function () {
            Object.keys(instances).forEach(function (id) { instances[id].refresh(); });
        },
        instances: instances
    };
})();
