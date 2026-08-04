<?php
declare(strict_types=1);
namespace Mublo\Helper\Sns;

/**
 * SNS 채널 헬퍼
 *
 * SNS 타입별 메타데이터(아이콘, 라벨, 색상) 제공
 * 배열 형태의 SNS 채널 데이터 처리
 */
class SnsHelper
{
    /**
     * 지원하는 SNS 타입 목록
     */
    public const TYPES = [
        'youtube' => [
            'label' => 'YouTube',
            'icon' => 'bi-youtube',
            'color' => '#FF0000',
            'placeholder' => 'https://youtube.com/@channel',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'icon' => 'bi-instagram',
            'color' => '#E4405F',
            'placeholder' => 'https://instagram.com/username',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'icon' => 'bi-facebook',
            'color' => '#1877F2',
            'placeholder' => 'https://facebook.com/page',
        ],
        'twitter' => [
            'label' => 'X (Twitter)',
            'icon' => 'bi-twitter-x',
            'color' => '#000000',
            'placeholder' => 'https://x.com/username',
        ],
        'blog_naver' => [
            'label' => '네이버 블로그',
            'icon' => 'bi-journal-text',
            'color' => '#03C75A',
            'placeholder' => 'https://blog.naver.com/blogid',
        ],
        'kakao_channel' => [
            'label' => '카카오 채널',
            'icon' => 'bi-chat-fill',
            'color' => '#FEE500',
            'placeholder' => 'https://pf.kakao.com/_channelid',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'icon' => 'bi-tiktok',
            'color' => '#000000',
            'placeholder' => 'https://tiktok.com/@username',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'icon' => 'bi-linkedin',
            'color' => '#0A66C2',
            'placeholder' => 'https://linkedin.com/company/name',
        ],
    ];

    /**
     * 모든 SNS 타입 목록 반환
     */
    public static function getTypes(): array
    {
        return self::TYPES;
    }

    /**
     * 타입 목록을 select 옵션용 배열로 반환
     */
    public static function getTypeOptions(): array
    {
        $options = [];
        foreach (self::TYPES as $type => $info) {
            $options[$type] = $info['label'];
        }
        return $options;
    }

    /**
     * 특정 SNS 타입 정보 반환
     */
    public static function getType(string $type): ?array
    {
        return self::TYPES[$type] ?? null;
    }

    /**
     * SNS 타입의 아이콘 클래스 반환
     */
    public static function getIcon(string $type): string
    {
        return self::TYPES[$type]['icon'] ?? 'bi-link-45deg';
    }

    /**
     * SNS 타입의 라벨 반환
     */
    public static function getLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /**
     * SNS 타입의 색상 반환
     */
    public static function getColor(string $type): string
    {
        return self::TYPES[$type]['color'] ?? '#6c757d';
    }

    /**
     * SNS 타입의 placeholder 반환
     */
    public static function getPlaceholder(string $type): string
    {
        return self::TYPES[$type]['placeholder'] ?? 'https://';
    }

    /**
     * URL이 있는 SNS 채널만 필터링 (배열 형태)
     *
     * @param array $channels [{"type": "youtube", "url": "..."}, ...]
     * @return array 유효한 채널만 반환
     */
    public static function filterActiveChannels(array $channels): array
    {
        return array_filter($channels, fn($channel) =>
            !empty($channel['type']) && !empty($channel['url'])
        );
    }

    /**
     * SNS 채널 배열을 HTML 링크로 변환
     *
     * @param array $channels [{"type": "youtube", "url": "..."}, ...]
     * @param bool $showLabel 라벨 표시 여부
     * @return string HTML
     */
    public static function renderLinks(array $channels, bool $showLabel = false): string
    {
        $active = self::filterActiveChannels($channels);
        if (empty($active)) {
            return '';
        }

        $html = '';
        foreach ($active as $channel) {
            $type = $channel['type'] ?? '';
            $url = $channel['url'] ?? '';

            if (empty($type) || empty($url)) continue;

            $info = self::getType($type);
            $icon = $info['icon'] ?? 'bi-link-45deg';
            $label = $info['label'] ?? $type;
            $color = $info['color'] ?? '#6c757d';

            $html .= sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="sns-link" style="color:%s" title="%s">',
                htmlspecialchars($url),
                htmlspecialchars($color),
                htmlspecialchars($label)
            );
            $html .= '<i class="bi ' . $icon . '"></i>';
            if ($showLabel) {
                $html .= ' <span>' . htmlspecialchars($label) . '</span>';
            }
            $html .= '</a> ';
        }

        return $html;
    }

    /**
     * 폼 제출 데이터를 배열 형태로 정규화
     *
     * @param array $formChannels ['type' => [...], 'url' => [...]]
     * @return array [{"type": "...", "url": "..."}, ...]
     */
    public static function normalizeFormData(array $formChannels): array
    {
        $result = [];

        $types = $formChannels['type'] ?? [];
        $urls = $formChannels['url'] ?? [];

        foreach ($types as $index => $type) {
            $url = $urls[$index] ?? '';
            if (!empty($type) && !empty($url)) {
                $result[] = [
                    'type' => $type,
                    'url' => $url,
                ];
            }
        }

        return $result;
    }

    /**
     * SNS 타입의 인라인 SVG 반환 (Bootstrap Icons 미의존 환경용)
     *
     * viewBox="0 0 24 24", fill="currentColor"
     */
    public static function getSvg(string $type): string
    {
        $svgs = [
            'youtube' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
            'instagram' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>',
            'facebook' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'twitter' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'tiktok' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.28 8.28 0 0 0 4.83 1.55V6.79a4.85 4.85 0 0 1-1.06-.1z"/></svg>',
            'linkedin' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            // 네이버 블로그 — section.blog.naver.com 공식 파비콘(원형 + N 녹아웃)
            'blog_naver' => '<svg viewBox="0 0 28 28" fill="currentColor" aria-hidden="true"><path d="M28 14c0 7.731-6.269 14-14 14S0 21.731 0 14 6.269 0 14 0s14 6.269 14 14M16.343 7.42v7.04l-4.89-7.04H7.419v13.16h4.238v-7.04l4.89 7.04h4.034V7.42z"/></svg>',
            // 카카오 채널 — 라운드스퀘어 + k (kakao business 파비콘 형태)
            'kakao_channel' => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" aria-hidden="true"><path d="M4 0h16a4 4 0 0 1 4 4v16a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4Zm5 6v12h2.5v-4.2l.6-.6 3 3.8h3.1l-4.2-5.2 4-4h-3.1l-3.4 3.4V6Z"/></svg>',
        ];

        return $svgs[$type] ?? '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7a5 5 0 0 0-5 5 5 5 0 0 0 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1 0 1.71-1.39 3.1-3.1 3.1h-4V17h4a5 5 0 0 0 5-5 5 5 0 0 0-5-5z"/></svg>';
    }

    /**
     * 타입 목록을 JSON으로 반환 (JavaScript용)
     */
    public static function getTypesJson(): string
    {
        return json_encode(self::TYPES, JSON_UNESCAPED_UNICODE);
    }
}
