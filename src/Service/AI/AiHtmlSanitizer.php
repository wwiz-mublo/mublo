<?php
namespace Mublo\Service\AI;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Mublo\Helper\Security\HtmlSanitizer;

/**
 * AI 생성 HTML 새니타이저
 *
 * 기본값(인자 없음)은 HTML 블록 정책(HtmlBlockAiPolicy) 그대로다 —
 * 기존 블록 경로의 동작은 변하지 않는다.
 *
 * 프레임 편집(도메인 프레임 편집) 등 다른 정책이 필요하면 생성자에
 * 정책 상수 집합·옵션을 주입한다 (FrameAiPolicy::sanitizer() 참조).
 * HtmlBlockAiPolicy가 final + 상수 직접 참조 구조라 정책 클래스 교체
 * 대신 새니타이저 파라미터화를 택했다 (계획문서 §5).
 */
class AiHtmlSanitizer
{
    /**
     * 템플릿 문자열 단독 값 (예: src="{{logo_url}}") — 치환 전 단계 보존 대상
     */
    private const TOKEN_VALUE_PATTERN = '/^\{\{\s*[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\s*\}\}$/';

    /**
     * @param string[]|null $editableElements null = HtmlBlockAiPolicy::EDITABLE_ELEMENTS
     * @param string[]|null $removeElements   null = HtmlBlockAiPolicy::REMOVE_ELEMENTS
     * @param bool $allowSiteImages  img src를 /storage/ 상대 경로 또는 순수 템플릿 토큰에 한해 허용
     *                               (P2부터 블록 경로도 기본 허용 — 외부 URL은 계속 차단)
     * @param bool $allowTargetBlank target="_blank"를 rel="noopener noreferrer" 강제 세트로 허용
     * @param string[] $allowedIds   보존할 id 화이트리스트 (프레임 토글 훅 등)
     * @param bool $useBasicPass     HTMLPurifier 1차 패스 사용 여부. 프레임 모드는 정책 요소
     *                               (button·토큰 src)를 1차 패스가 지워버리므로 끄고, 대신
     *                               stripInlineStyles로 인라인 스타일 채널을 막는다
     * @param bool $stripInlineStyles style 속성 제거 (1차 패스 생략 시 필수 보강)
     */
    public function __construct(
        private ?array $editableElements = null,
        private ?array $removeElements = null,
        private bool $allowSiteImages = true,
        private bool $allowTargetBlank = false,
        private array $allowedIds = [],
        private bool $useBasicPass = true,
        private bool $stripInlineStyles = false,
    ) {
    }

    public function sanitize(string $html): string
    {
        $editableElements = $this->editableElements ?? HtmlBlockAiPolicy::EDITABLE_ELEMENTS;
        $removeElements = $this->removeElements ?? HtmlBlockAiPolicy::REMOVE_ELEMENTS;

        if ($this->useBasicPass) {
            $html = HtmlSanitizer::sanitizeBasic($html);
        }
        if (trim($html) === '') return '';

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div data-mublo-ai-root="1">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        foreach ($removeElements as $tag) {
            $nodes = $xpath->query('//' . $tag);
            if ($nodes) foreach (iterator_to_array($nodes) as $node) $node->parentNode?->removeChild($node);
        }

        $elements = $xpath->query('//*');
        if ($elements) foreach (iterator_to_array($elements) as $element) {
            if (!$element instanceof DOMElement) continue;
            if (!$element->hasAttribute('data-mublo-ai-root')
                && !in_array(strtolower($element->tagName), $editableElements, true)) {
                $parent = $element->parentNode;
                if ($parent) {
                    while ($element->firstChild) $parent->insertBefore($element->firstChild, $element);
                    $parent->removeChild($element);
                }
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if ($name === 'id' && in_array($attribute->value, $this->allowedIds, true)) {
                    continue;
                }
                if ($name === 'src' && $this->allowSiteImages && $this->isAllowedImageSrc($attribute->value)) {
                    continue;
                }
                if ($name === 'target' && $this->allowTargetBlank && $attribute->value === '_blank') {
                    // 세트 강제: target=_blank는 항상 rel=noopener noreferrer와 함께
                    $element->setAttribute('rel', 'noopener noreferrer');
                    continue;
                }
                if ($name === 'rel' && $this->allowTargetBlank && $element->getAttribute('target') === '_blank') {
                    continue;
                }
                if ($name === 'style' && $this->stripInlineStyles) {
                    $element->removeAttribute($attribute->name);
                    continue;
                }
                if ($name === 'id' || $name === 'src' || $name === 'srcset' || $name === 'target'
                    || $name === 'rel' || str_starts_with($name, 'on') || str_starts_with($name, 'data-')) {
                    if ($name !== 'data-mublo-ai-root') $element->removeAttribute($attribute->name);
                }
            }
            if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
                $href = trim($element->getAttribute('href'));
                if (!str_starts_with($href, '/') || str_starts_with($href, '//')) $element->removeAttribute('href');
            }
            // src가 살아남지 못한 img는 빈 껍데기 — 요소째 제거
            if (strtolower($element->tagName) === 'img' && !$element->hasAttribute('src')) {
                $element->parentNode?->removeChild($element);
            }
        }

        $root = $xpath->query('//*[@data-mublo-ai-root="1"]')?->item(0);
        if (!$root) return '';
        $result = '';
        foreach ($root->childNodes as $child) $result .= $dom->saveHTML($child);

        // 일부 libxml 버전은 src 등 URL 속성의 {}를 %7B/%7D로 퍼센트 인코딩한다
        // (CI와 로컬이 달랐던 원인). 템플릿 토큰만 원형으로 복원한다.
        $result = (string) preg_replace_callback(
            '/%7B%7B(.*?)%7D%7D/i',
            static fn (array $m): string => '{{' . rawurldecode($m[1]) . '}}',
            $result
        );

        return trim($result);
    }

    private function isAllowedImageSrc(string $value): bool
    {
        $value = trim($value);

        return str_starts_with($value, '/storage/')
            || preg_match(self::TOKEN_VALUE_PATTERN, $value) === 1;
    }
}
