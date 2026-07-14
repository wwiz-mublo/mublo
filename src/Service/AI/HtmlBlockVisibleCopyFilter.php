<?php
namespace Mublo\Service\AI;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** AI 작업 과정 설명이 방문자용 HTML에 섞이지 않도록 제거한다. */
final class HtmlBlockVisibleCopyFilter
{
    private const CANDIDATE_TAGS = [
        'p', 'span', 'small', 'li', 'figcaption',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /** @return array{html:string,removed:string[]} */
    public function filter(string $html): array
    {
        if (trim($html) === '') return ['html' => '', 'removed' => []];

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div data-mublo-copy-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $removed = [];

        $query = implode(' | ', array_map(static fn (string $tag): string => '//' . $tag, self::CANDIDATE_TAGS));
        $nodes = $xpath->query($query);
        if ($nodes) foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof DOMElement || !$node->parentNode) continue;
            $text = $this->normalizeText($node->textContent);
            if (!$this->isMetaNarration($text)) continue;
            $removed[] = $text;
            $node->parentNode->removeChild($node);
        }

        $root = $xpath->query('//*[@data-mublo-copy-root="1"]')?->item(0);
        if (!$root) return ['html' => '', 'removed' => array_values(array_unique($removed))];
        $result = '';
        foreach ($root->childNodes as $child) $result .= $dom->saveHTML($child);

        return ['html' => trim($result), 'removed' => array_values(array_unique($removed))];
    }

    private function isMetaNarration(string $text): bool
    {
        if ($text === '' || mb_strlen($text) > 400) return false;

        $patterns = [
            '/(?:참고\s*자료|첨부\s*(?:자료|파일)|요청|프롬프트).{0,120}(?:맞춰|따라|바탕|기반|반영).{0,160}(?:정리|구성|작성|제작|생성)(?:했|하였|해\s*드렸|되었)/u',
            '/(?:요청하신|요청한|요청에\s*따라).{0,200}(?:정리|구성|작성|제작|생성)(?:했|하였|해\s*드렸|되었)/u',
            '/(?:두|세|네|다섯|\d+)\s*(?:개|가지|장)\s*(?:의\s*)?(?:카드|항목|슬라이드).{0,120}(?:정리|구성)(?:했|하였|되어)/u',
            '/(?:reference materials?|attachments?|your request|the prompt).{0,180}(?:organized|summarized|created|generated|presented|arranged)/iu',
            '/(?:organized|summarized|created|generated|presented|arranged).{0,120}(?:based on|according to|in line with).{0,120}(?:reference materials?|attachments?|your request|the prompt)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) return true;
        }
        return false;
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
