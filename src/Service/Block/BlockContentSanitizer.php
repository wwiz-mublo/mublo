<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

use Mublo\Enum\Block\BlockContentType;
use Mublo\Helper\Security\HtmlSanitizer;

/**
 * BlockContentSanitizer
 *
 * 블록 콘텐츠 저장 경로가 공유하는 정화 규칙.
 */
class BlockContentSanitizer
{
    /**
     * HTML 직접입력 블록의 content_config를 정화한다.
     *
     * - html / slides[].html: script/on* 등 액티브 콘텐츠 제거
     * - css: </style> 브레이크아웃만 무력화
     * - js: 의도된 스크립트 채널이므로 보존
     */
    public function sanitizeHtmlConfig(array $config): array
    {
        // block 프로파일 — 블록은 관리자가 조판하는 채널이라 레이아웃 CSS 까지 허용한다.
        // 스크립트 채널은 rich 와 똑같이 막힌다(HtmlSanitizer 참고).
        if (!empty($config['html'])) {
            $config['html'] = HtmlSanitizer::sanitizeForBlock($config['html']);
        }

        if (!empty($config['slides']) && is_array($config['slides'])) {
            foreach ($config['slides'] as $i => $slide) {
                if (is_array($slide) && !empty($slide['html'])) {
                    $config['slides'][$i]['html'] = HtmlSanitizer::sanitizeForBlock($slide['html']);
                }
            }
        }

        if (!empty($config['css']) && is_string($config['css'])) {
            do {
                $previous = $config['css'];
                $config['css'] = preg_replace('#</\s*style#i', '', $config['css']);
            } while ($config['css'] !== $previous);
        }

        return $config;
    }

    public function normalizeContentConfig(mixed $contentConfig): ?array
    {
        if ($contentConfig === null || $contentConfig === '') {
            return null;
        }

        if (is_array($contentConfig)) {
            return $contentConfig;
        }

        if (is_string($contentConfig)) {
            $decoded = json_decode($contentConfig, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public function columnContainsScript(array $column): bool
    {
        foreach (BlockContentTree::contentNodes($column) as $content) {
            $config = $this->normalizeContentConfig($content['content_config'] ?? null);
            if (is_array($config)
                && isset($config['js'])
                && is_string($config['js'])
                && trim($config['js']) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

}
