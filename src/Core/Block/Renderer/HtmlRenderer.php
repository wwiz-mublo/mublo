<?php
namespace Mublo\Core\Block\Renderer;

use Mublo\Entity\Block\BlockColumn;

/**
 * HtmlRenderer
 *
 * HTML 직접입력 콘텐츠 렌더러
 *
 * 모드:
 * - single (기본): 단일 HTML/CSS/JS 출력
 * - slide: 여러 HTML 슬라이드를 Swiper로 출력 (MubloItemLayout.js 연동)
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $html: 정화된 HTML 콘텐츠 (single 모드)
 * - $css: CSS 문자열
 * - $js: JS 문자열
 * - $slides: 슬라이드 배열 (slide 모드, [{html: "..."}, ...])
 * - $slideAttrs: MubloItemLayout data 속성 문자열 (slide 모드)
 */
class HtmlRenderer implements RendererInterface
{
    use SkinRendererTrait;

    protected function getSkinType(): string
    {
        return 'html';
    }

    public function render(BlockColumn $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $skin = $column->getContentSkin() ?: 'basic';
        $mode = $config['mode'] ?? 'single';

        // 블록 스코핑 (정책: 블록 css/js 는 자기 칸 안에서만 동작)
        // - CSS: 셀렉터를 #bc-{key} 하위로 스코핑 — 에디터 미리보기와 동일 의미론(WYSIWYG)
        // - JS: 컨테이너 요소를 `block` 인자로 받는 IIFE 래핑 — 자기 블록 접근 표준화
        //   (전역 window 접근은 여전히 가능 — 격리가 아니라 실수 방지·관례 제공)
        // 앵커는 렌더 키 기반: single 은 "12"(기존 bc-12 그대로), 스택 콘텐츠는
        // "12-c-31" — 같은 스택의 HTML 콘텐츠끼리 CSS/JS 가 격리된다 (계획 7.2.1)
        $containerId = 'bc-' . $column->getRenderKey();
        $rawCss = \Mublo\Service\Block\BlockCssScoper::scope((string) ($config['css'] ?? ''), '#' . $containerId);
        $rawJs = $this->wrapJs((string) ($config['js'] ?? ''), $containerId);

        if ($mode === 'slide') {
            return $this->renderSlideMode($column, $skin, $config, $rawCss, $rawJs);
        }

        return $this->renderSingleMode($column, $skin, $config, $rawCss, $rawJs);
    }

    /**
     * 블록 JS 래핑 — 컨테이너 요소를 `block` 으로 전달, 최상위 선언의 전역 누수 방지
     */
    private function wrapJs(string $js, string $containerId): string
    {
        if (trim($js) === '') {
            return '';
        }

        return "(function(block){\n" . $js . "\n})(document.getElementById('" . $containerId . "'));";
    }

    private function renderSingleMode(BlockColumn $column, string $skin, array $config, string $css, string $js): string
    {
        $rawHtml = $config['html'] ?? '';

        if (empty($rawHtml)) {
            return $this->renderEmptyContent('HTML 콘텐츠가 없습니다.');
        }

        return $this->renderSkin($column, $skin, [
            'html' => $rawHtml,
            'css' => $css,
            'js' => $js,
            'slides' => null,
            'slideAttrs' => '',
        ]);
    }

    private function renderSlideMode(BlockColumn $column, string $skin, array $config, string $css, string $js): string
    {
        $slides = $config['slides'] ?? [];

        if (empty($slides)) {
            return $this->renderEmptyContent('슬라이드 콘텐츠가 없습니다.');
        }

        $attrs = $this->buildSlideAttrs($config);

        return $this->renderSkin($column, $skin, [
            'html' => '',
            'css' => $css,
            'js' => $js,
            'slides' => $slides,
            'slideAttrs' => $attrs,
        ]);
    }

    private function buildSlideAttrs(array $config): string
    {
        $parts = [
            'data-pc-style="slide"',
            'data-mo-style="slide"',
            'data-pc-cols="1"',
            'data-mo-cols="1"',
        ];

        $pcAutoplay = (int) ($config['pc_autoplay'] ?? 0);
        $moAutoplay = (int) ($config['mo_autoplay'] ?? 0);

        if ($pcAutoplay > 0) {
            $parts[] = 'data-pc-autoplay="' . $pcAutoplay . '"';
        }
        if ($moAutoplay > 0) {
            $parts[] = 'data-mo-autoplay="' . $moAutoplay . '"';
        }
        if (!empty($config['pc_loop'])) {
            $parts[] = 'data-pc-loop="true"';
        }
        if (!empty($config['mo_loop'])) {
            $parts[] = 'data-mo-loop="true"';
        }

        return implode(' ', $parts);
    }
}
