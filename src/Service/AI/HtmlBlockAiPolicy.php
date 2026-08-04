<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

final class HtmlBlockAiPolicy
{
    public const EDITABLE_ELEMENTS = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'nav',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'strong', 'em', 'small',
        'mark', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'figure', 'figcaption',
        'details', 'summary', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'hr', 'a',
        // P2 표현력 확장: 아이콘(<i class="bi-*">)·이미지(/storage/ 상대 경로 한정 — 새니타이저가 src 검증)
        'i', 'img',
    ];

    public const REMOVE_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'svg', 'canvas', 'video', 'audio', 'source',
        'picture', 'link', 'meta',
    ];

    public const CSS_PROPERTIES = [
        'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style',
        'line-height', 'letter-spacing', 'text-align', 'text-decoration', 'text-transform',
        'white-space', 'word-break', 'overflow-wrap', 'width', 'min-width', 'max-width', 'height', 'min-height',
        'max-height', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-width', 'border-style', 'border-color', 'border-radius', 'box-shadow',
        'display', 'gap', 'row-gap', 'column-gap', 'flex', 'flex-basis', 'flex-direction',
        'flex-grow', 'flex-shrink', 'flex-wrap', 'justify-content', 'align-items', 'align-content',
        'align-self', 'grid-template-columns', 'grid-template-rows', 'grid-auto-columns',
        'grid-auto-rows', 'grid-auto-flow', 'grid-column', 'grid-row', 'place-items', 'place-content',
        'overflow', 'overflow-x', 'overflow-y', 'opacity', 'list-style', 'list-style-type',
        'border-collapse', 'border-spacing', 'vertical-align', 'scroll-snap-type',
        'scroll-snap-align', 'scroll-behavior',
        // P2 표현력 확장 (품질 검토 P2-4) — 오버레이·네트워크 벡터가 아닌 안전군.
        // url()은 값 검증이 차단하므로 background 계열은 그라디언트 전용이 된다.
        // position/z-index/inset/animation(@keyframes)/content는 계속 불허.
        'background', 'background-image', 'background-position', 'background-size',
        'background-repeat', 'background-clip',
        'transition', 'transition-property', 'transition-duration', 'transition-timing-function',
        'transition-delay', 'transform', 'transform-origin',
        'text-shadow', 'outline', 'outline-offset', 'outline-color', 'outline-style', 'outline-width',
        'cursor', 'aspect-ratio', 'object-fit', 'object-position', 'visibility',
    ];

    public const SLIDER_ROOT_CLASS = 'mublo-slider';
    public const SLIDER_TRACK_CLASS = 'mublo-slider-track';
    public const SLIDER_ITEM_CLASS = 'mublo-slide';
    /**
     * AI 슬라이드 프리셋 (개선 계획 §6.4) — AI는 자유 형식 Swiper 옵션 대신
     * 이 프리셋만 선언하고, 표시 수·breakpoints·loop는 MubloSlider adapter가 결정한다.
     */
    public const SLIDER_PRESETS = ['hero', 'cards', 'gallery'];
    public const DEFAULT_SLIDER_AUTOPLAY_SECONDS = 5;
    public const TABS_ROOT_CLASS = 'mublo-tabs';
    public const TABS_LIST_CLASS = 'mublo-tab-list';
    public const TABS_TRIGGER_CLASS = 'mublo-tab';
    public const TABS_PANELS_CLASS = 'mublo-tab-panels';
    public const TABS_PANEL_CLASS = 'mublo-tab-panel';
    public const ACCORDION_ROOT_CLASS = 'mublo-accordion';
    public const ACCORDION_ITEM_CLASS = 'mublo-accordion-item';
    public const ACCORDION_TRIGGER_CLASS = 'mublo-accordion-trigger';
    public const ACCORDION_PANEL_CLASS = 'mublo-accordion-panel';

    public static function requestsHeroSlider(string $request): bool
    {
        $hasHero = preg_match('/(?:(?<![A-Z])hero(?:es)?(?![A-Z])|히어로|메인\s*배너)/iu', $request) === 1;
        $hasSlider = preg_match(
            '/(?:(?<![A-Z])slides?(?![A-Z])|(?<![A-Z])sliders?(?![A-Z])|(?<![A-Z])carousels?(?![A-Z])|(?<![A-Z])swipes?(?![A-Z])|슬라이드|슬라이더|캐러셀|스와이프)/iu',
            $request
        ) === 1;

        return $hasHero && $hasSlider;
    }

    public static function requestedHeroSlideCount(string $request): ?int
    {
        if (!self::requestsHeroSlider($request)) return null;
        if (preg_match('/(?<!\d)(\d{1,2})\s*(?:개(?:의)?|장|cards?|slides?|heroes?)/iu', $request, $matches) !== 1) {
            return null;
        }

        $count = (int) $matches[1];
        return $count >= 2 && $count <= 20 ? $count : null;
    }

    public static function requestsManualSlider(string $request): bool
    {
        return preg_match(
            '/(?:수동(?:으로)?|자동(?:으로)?[^.!?\n]{0,24}(?:없이|끄기|꺼|금지|하지\s*마|안\s*(?:되|하)|사용\s*(?:안|하지))|오토\s*플레이[^.!?\n]{0,16}(?:없이|끄기|꺼|금지|하지\s*마|안\s*(?:되|하))|(?:no|without|disable)\s+autoplay|autoplay\s+(?:off|disabled)|manual(?:ly)?)/iu',
            $request
        ) === 1;
    }

    /** @param array<string,mixed> $behavior @return array<string,mixed> */
    public static function enforceRequestBehavior(array $behavior, string $request): array
    {
        $types = is_array($behavior['types'] ?? null) ? $behavior['types'] : [];
        $hasSlider = in_array('slider', $types, true)
            || (($behavior['type'] ?? null) === 'slider');
        if ($hasSlider && self::requestsHeroSlider($request)) {
            // Hero와 slider가 함께 명시되면 카드라는 단어가 있어도 한 화면 한 장이 우선이다.
            $behavior['slider_preset'] = 'hero';
        }
        if ($hasSlider) {
            if (self::requestsManualSlider($request)) {
                $behavior['autoplay_seconds'] = 0;
            } elseif ((int) ($behavior['autoplay_seconds'] ?? 0) <= 0) {
                $behavior['autoplay_seconds'] = self::DEFAULT_SLIDER_AUTOPLAY_SECONDS;
            }
        }

        return $behavior;
    }
}
