<?php
namespace Mublo\Service\AI;

/**
 * 도메인 프레임 편집(header/footer) AI 정책 — 블록 정책의 확장판 (계획문서 §3.4)
 *
 * HtmlBlockAiPolicy 대비:
 * - 허용 확대: img(/storage/ 상대 경로·순수 템플릿 토큰 한정), address, i(아이콘),
 *   button(모바일 토글), target="_blank"+rel="noopener noreferrer" 세트
 * - 계속 불허: script·style·iframe·form 계열, svg·canvas·미디어, on* 속성,
 *   외부 URL src. CSS의 position·z-index·url()은 ScopedCssSanitizer가 차단
 *   (CSS_PROPERTIES 허용 목록에 없음 — 오버레이 벡터 차단)
 * - id는 화이트리스트만 보존 (모바일 패널 토글 훅)
 */
final class FrameAiPolicy
{
    public const EDITABLE_ELEMENTS = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'address',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'strong', 'em', 'small', 'i',
        'mark', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'figure', 'figcaption',
        'details', 'summary', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'hr', 'a',
        'img', 'button',
    ];

    public const REMOVE_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'textarea', 'select', 'option', 'svg', 'canvas', 'video', 'audio', 'source',
        'picture', 'link', 'meta',
    ];

    /**
     * 보존하는 id — front.js 훅 (모바일 패널 토글, GNB 앵커)
     */
    public const ALLOWED_IDS = ['mubloPanelToggle', 'main-nav'];

    /**
     * 프레임 정책이 적용된 새니타이저 인스턴스
     */
    public static function sanitizer(): AiHtmlSanitizer
    {
        return new AiHtmlSanitizer(
            self::EDITABLE_ELEMENTS,
            self::REMOVE_ELEMENTS,
            allowSiteImages: true,
            allowTargetBlank: true,
            allowedIds: self::ALLOWED_IDS,
            // HTMLPurifier basic 프로파일은 button·토큰 src를 정책과 무관하게 제거하므로
            // 프레임 모드는 DOM 허용목록 단독 + 인라인 스타일 제거로 정화한다
            useBasicPass: false,
            stripInlineStyles: true,
        );
    }
}
