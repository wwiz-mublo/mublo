/**
 * block-preview-iframe.js — 블록 미리보기 iframe 공유 렌더러
 *
 * 목록 미리보기 모달(/admin/block-row)과 편집 폼 라이브 미리보기(/admin/block-row/edit)가
 * 각자 복제하던 iframe srcdoc 빌더를 하나로 합친 단일 소스.
 * iframe 이 로드하는 CSS/JS 목록(PREVIEW_ASSETS)이 곧 "블록 미리보기 의존성"의 단일 정의다.
 * (프론트 Head.php 가 전역으로 까는 것과 여기를 맞춰야 색·보더·아이콘·거터가 실제와 동일해진다.)
 *
 * 필요 DOM: #previewFrame, #previewLoading (두 화면 공통 ID)
 * 선행 로드(선택): front-preview-tokens.js — 프레임 스킨 변수 rebind·도메인 브랜드색을
 * 프리뷰에 재현한다. 없으면 tokens.css 기본값으로 폴백.
 * 전역 노출:
 *   - window.buildBlockPreviewSrcdoc(html, skinCss, skinJs, frontVars?) → srcdoc 문자열
 *   - window.renderBlockPreviewIframe(html, skinCss, skinJs, options)
 *       options.autoHeight: true 면 로드 후 내용 높이에 맞춰 200~600px 로 clamp (모달용).
 *       생략 시 iframe 높이를 건드리지 않음 (폼은 .preview-iframe 의 75vh 고정 사용).
 */
(function () {
    'use strict';

    // 미리보기 iframe 이 로드하는 전역 의존성 — 프론트와 정합을 맞추는 단일 지점.
    var PREVIEW_ASSETS = {
        css: [
            // 본문 폰트 — front.css body 의 var(--font-sans)("Plus Jakarta Sans","Noto Sans KR"…) 과 맞춘다
            'https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@100..900&display=swap',
            'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap',
            // 아이콘 폰트 — 블록의 bi-*/fa-* 아이콘 (프론트 Head.php 와 동일 버전)
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css',
            'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.1.0/css/all.min.css',
            // 실제 프론트와 동일한 로컬 버전. CDN 장애와 버전 차이를 제거한다.
            '/assets/lib/swiper/12/swiper-bundle.min.css',
            '/assets/lib/aos/2/dist/aos.css',
            // 코어 디자인 토큰 — 블록/블록스킨이 소비하는 semantic 토큰(색·보더·--content-max-width)의 출처.
            // 반드시 block.css 앞. (front.css 는 body flex/min-height 가 프리뷰에 새어 제외, 블록스킨은 tokens 이름을 직접 소비)
            '/assets/css/tokens.css',
            '/assets/css/block.css'
        ],
        js: [
            '/assets/lib/swiper/12/swiper-bundle.min.js',
            '/assets/lib/aos/2/dist/aos.js',
            '/assets/js/MubloItemLayout.js',
            '/assets/js/MubloSlider.js'
        ]
    };

    function links(paths) {
        return (paths || []).map(function (p) {
            return '<link rel="stylesheet" href="' + p + '">';
        }).join('\n');
    }

    function scripts(paths) {
        return (paths || []).map(function (p) {
            return '<script src="' + p + '"><\/script>';
        }).join('\n');
    }

    // 프론트 토큰 상위 캐스케이드 — 프레임 스킨의 변수 rebind(예:
    // --primary: var(--skin-accent)) + 도메인 브랜드색 override. tokens.css <link>
    // 뒤에 와야 이긴다(프론트 frame Head.php 로드 순서와 동일).
    // MubloFrontPreviewCss(front-preview-tokens.js) 미로드 시 빈 문자열로 폴백.
    var frontVarsPromise = null;
    function frontVarsCss() {
        if (!frontVarsPromise) {
            frontVarsPromise = window.MubloFrontPreviewCss
                ? window.MubloFrontPreviewCss
                    .buildCss(function (selector) { return selector; }, { coreTokens: false })
                    .catch(function () { return ''; })
                : Promise.resolve('');
        }
        return frontVarsPromise;
    }

    function buildBlockPreviewSrcdoc(html, skinCss, skinJs, frontVars) {
        return '<!DOCTYPE html>\n'
            + '<html lang="ko"><head><meta charset="UTF-8">\n'
            + '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n'
            + links(PREVIEW_ASSETS.css) + '\n'
            + (frontVars ? '<style>\n' + frontVars + '\n</style>\n' : '')
            + links(skinCss) + '\n'
            + '<style>\n'
            // 프리뷰 캔버스 흰색 — html 에 줘서 body margin(거터) 영역까지 균일하게.
            + 'html { background: #fff; }\n'
            // 페이지 컨텍스트(iframe body)의 margin 이 거터를 소유 = 브라우저 기본 방식.
            // 컨테이너엔 거터를 두지 않는다(프론트 .block-container = width만, 거터는 프레임 담당과 동일 원칙).
            // 폰트는 front.css body 와 동일하게 var(--font-sans) (tokens.css 정의) 소비.
            + 'body { margin: 16px; font-family: var(--font-sans); overflow-x: hidden; }\n'
            + '*, *::before, *::after { box-sizing: border-box; }\n'
            + 'ul, ol { list-style: none; margin: 0; padding: 0; }\n'
            + '.block-container { max-width: 100%; }\n'
            + '</style>\n'
            + '</head><body>\n'
            + html + '\n'
            + scripts(PREVIEW_ASSETS.js) + '\n'
            + scripts(skinJs) + '\n'
            + '<script>\n'
            + 'document.addEventListener("DOMContentLoaded", function () {\n'
            + '  if (typeof AOS !== "undefined") AOS.init({ once: true });\n'
            + '  if (typeof MubloItemLayout !== "undefined") MubloItemLayout.initAll();\n'
            + '  var reportHeight = function () {\n'
            + '    parent.postMessage({ type: "mublo:block-preview-height", height: document.documentElement.scrollHeight }, "*");\n'
            + '  };\n'
            + '  reportHeight();\n'
            + '  window.addEventListener("resize", reportHeight);\n'
            + '  if (typeof ResizeObserver !== "undefined") new ResizeObserver(reportHeight).observe(document.body);\n'
            + '});\n'
            + '<\/script>\n'
            + '</body></html>';
    }

    // sandboxed srcdoc has an opaque origin. Origin matching is therefore unavailable;
    // tie messages to the exact iframe window and accept only a bounded numeric height.
    window.addEventListener('message', function (event) {
        var frame = document.getElementById('previewFrame');
        if (!frame || event.source !== frame.contentWindow || frame.dataset.autoHeight !== '1') return;
        if (!event.data || event.data.type !== 'mublo:block-preview-height') return;

        var height = Number(event.data.height);
        if (!Number.isFinite(height)) return;
        frame.style.height = Math.min(Math.max(Math.ceil(height) + 32, 200), 600) + 'px';
    });

    function renderBlockPreviewIframe(html, skinCss, skinJs, options) {
        var opts = options || {};
        var loading = document.getElementById('previewLoading');
        var frame = document.getElementById('previewFrame');
        if (!frame) return;
        frame.dataset.autoHeight = opts.autoHeight ? '1' : '0';

        frontVarsCss().then(function (frontVars) {
            frame.srcdoc = buildBlockPreviewSrcdoc(html, skinCss || [], skinJs || [], frontVars);
        });
        frame.onload = function () {
            if (loading) loading.style.display = 'none';
            frame.style.display = '';

        };
    }

    window.buildBlockPreviewSrcdoc = buildBlockPreviewSrcdoc;
    window.renderBlockPreviewIframe = renderBlockPreviewIframe;
})();
