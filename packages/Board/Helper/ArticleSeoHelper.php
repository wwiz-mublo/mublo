<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Helper;

/**
 * 게시글 SEO 메타 데이터 빌더
 *
 * 게시글의 제목/본문/썸네일로부터
 *  - title / description / keywords
 *  - 대표 이미지 URL
 *  - BlogPosting / BreadcrumbList / VideoObject JSON-LD
 * 를 만들어 Controller가 View(Head)로 넘기기 좋은 형태로 반환.
 */
final class ArticleSeoHelper
{
    private const DESCRIPTION_LENGTH = 160;

    public static function buildTitle(string $articleTitle, string $boardName, string $siteName): string
    {
        $parts = [];
        $articleTitle = trim($articleTitle);
        $boardName = trim($boardName);
        $siteName = trim($siteName);

        if ($articleTitle !== '') {
            $parts[] = $articleTitle;
        }
        if ($boardName !== '' && $boardName !== $articleTitle) {
            $parts[] = $boardName;
        }
        if ($siteName !== '') {
            $parts[] = $siteName;
        }

        return implode(' | ', $parts);
    }

    /**
     * 본문 HTML에서 description 추출
     * - 태그 제거 → 공백 정규화 → 길이 트림
     * - 추출 결과가 $minLength 미만이면 $fallbackParts 를 이어붙여 보강
     *
     * @param array<int, string> $fallbackParts 본문 텍스트가 부족할 때 덧붙일 문구들
     */
    public static function buildDescription(
        string $contentHtml,
        int $length = self::DESCRIPTION_LENGTH,
        array $fallbackParts = [],
        int $minLength = 60
    ): string {
        $text = '';

        if ($contentHtml !== '') {
            $stripped = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $contentHtml) ?? $contentHtml;
            $text = strip_tags($stripped);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $text = trim($text);
        }

        if (mb_strlen($text, 'UTF-8') < $minLength && !empty($fallbackParts)) {
            $parts = $text !== '' ? [$text] : [];
            foreach ($fallbackParts as $p) {
                $p = trim((string) $p);
                if ($p !== '' && !in_array($p, $parts, true)) {
                    $parts[] = $p;
                }
            }
            $text = trim(implode(' ', $parts));
        }

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') > $length) {
            $text = mb_substr($text, 0, $length, 'UTF-8') . '…';
        }

        return $text;
    }

    /**
     * 본문에서 첫 번째 <img src> 추출 (절대/상대 URL 모두 그대로 반환)
     */
    public static function extractFirstImageUrl(string $contentHtml): ?string
    {
        if ($contentHtml === '' || stripos($contentHtml, '<img') === false) {
            return null;
        }

        if (preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $contentHtml, $m)) {
            $url = trim($m[1]);
            return $url !== '' ? $url : null;
        }

        return null;
    }

    /**
     * 대표 이미지 URL 결정
     * 우선순위: thumbnail → 본문 첫 <img> → 본문 첫 영상 썸네일 → null
     *
     * @param array<int, array{provider: string, id: string, embed_url: string, thumbnail_url: ?string}>|null $videos
     */
    public static function pickOgImage(?string $thumbnail, string $contentHtml, ?array $videos = null): ?string
    {
        $thumbnail = trim((string) $thumbnail);
        if ($thumbnail !== '') {
            return $thumbnail;
        }

        $first = self::extractFirstImageUrl($contentHtml);
        if ($first !== null) {
            return $first;
        }

        if (is_array($videos)) {
            foreach ($videos as $v) {
                if (!empty($v['thumbnail_url'])) {
                    return $v['thumbnail_url'];
                }
            }
        }

        return null;
    }

    /**
     * 상대 URL을 절대 URL로 변환
     */
    public static function toAbsoluteUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($baseUrl);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if ($url[0] !== '/') {
            $url = '/' . $url;
        }

        return $origin . $url;
    }

    /**
     * BlogPosting JSON-LD
     *
     * @param array{
     *   title: string,
     *   description: string,
     *   url: string,
     *   image?: ?string,
     *   author?: ?string,
     *   publisher?: ?string,
     *   published_at?: ?string,
     *   updated_at?: ?string,
     *   comment_count?: ?int,
     *   interactions?: array<string, int>,
     * } $data
     */
    public static function buildArticleJsonLd(array $data): array
    {
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $data['url'] ?? '',
            ],
        ];

        if (!empty($data['image'])) {
            $jsonLd['image'] = $data['image'];
        }

        if (!empty($data['published_at'])) {
            $jsonLd['datePublished'] = self::toIso8601($data['published_at']);
        }
        if (!empty($data['updated_at'])) {
            $jsonLd['dateModified'] = self::toIso8601($data['updated_at']);
        }

        if (!empty($data['author'])) {
            $jsonLd['author'] = [
                '@type' => 'Person',
                'name' => $data['author'],
            ];
        }

        if (!empty($data['publisher'])) {
            $jsonLd['publisher'] = [
                '@type' => 'Organization',
                'name' => $data['publisher'],
            ];
        }

        if (isset($data['comment_count']) && (int) $data['comment_count'] > 0) {
            $jsonLd['commentCount'] = (int) $data['comment_count'];
        }

        if (!empty($data['interactions']) && is_array($data['interactions'])) {
            $stats = [];
            foreach ($data['interactions'] as $type => $count) {
                $count = (int) $count;
                if ($count <= 0) {
                    continue;
                }
                $interactionType = match ($type) {
                    'view' => 'https://schema.org/ReadAction',
                    'like', 'reaction' => 'https://schema.org/LikeAction',
                    'comment' => 'https://schema.org/CommentAction',
                    default => null,
                };
                if ($interactionType === null) {
                    continue;
                }
                $stats[] = [
                    '@type' => 'InteractionCounter',
                    'interactionType' => $interactionType,
                    'userInteractionCount' => $count,
                ];
            }
            if (!empty($stats)) {
                $jsonLd['interactionStatistic'] = $stats;
            }
        }

        return $jsonLd;
    }

    /**
     * BreadcrumbList JSON-LD
     *
     * @param array<int, array{name: string, url?: ?string}> $items
     */
    public static function buildBreadcrumbJsonLd(array $items): array
    {
        $list = [];
        $position = 1;
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entry = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
            ];
            if (!empty($item['url'])) {
                $entry['item'] = $item['url'];
            }
            $list[] = $entry;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    /**
     * 본문 HTML의 <img>에 alt가 누락된 경우 글 제목 기반으로 자동 채움
     *
     * 규칙:
     * - alt 속성이 아예 없으면 추가
     * - alt="" (빈 문자열)은 작성자 의도일 수 있어 건드리지 않음
     * - alt에 값이 있으면 절대 덮어쓰지 않음
     */
    public static function enrichImageAlts(string $html, string $title): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        $title = trim($title);
        if ($title === '') {
            return $html;
        }

        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $index = 0;

        return preg_replace_callback(
            '/<img\b([^>]*)>/i',
            function ($m) use ($escapedTitle, &$index) {
                $index++;
                $attrs = $m[1];

                // alt 속성이 이미 존재(빈 문자열 포함)하면 그대로 둠
                if (preg_match('/\salt\s*=/i', $attrs)) {
                    return $m[0];
                }

                $label = $index > 1 ? "{$escapedTitle} 이미지 {$index}" : $escapedTitle;
                $attrs = rtrim($attrs) . ' alt="' . $label . '"';

                return '<img' . $attrs . '>';
            },
            $html
        ) ?? $html;
    }

    /**
     * 본문 HTML에서 YouTube/Vimeo 영상 임베드 추출
     *
     * BoardContentSanitizer가 정규화한 URL 패턴(youtube[-nocookie].com/embed/{ID},
     * player.vimeo.com/video/{ID})을 기준으로 영상 ID를 뽑아 표준화된 정보로 반환.
     *
     * @return array<int, array{provider: string, id: string, embed_url: string, thumbnail_url: ?string}>
     */
    public static function extractVideoEmbeds(string $contentHtml): array
    {
        if ($contentHtml === '' || stripos($contentHtml, '<iframe') === false) {
            return [];
        }

        $videos = [];
        $seen = [];

        if (preg_match_all(
            '#<iframe\b[^>]*?\bsrc\s*=\s*["\']https?://(?:www\.)?youtube(-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#i',
            $contentHtml,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $noCookie = strtolower($m[1]);
                $id = $m[2];
                $key = 'youtube:' . $id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                // canonical embed URL 은 www. 가 붙은 형태
                $host = 'www.youtube' . $noCookie . '.com';
                $videos[] = [
                    'provider' => 'youtube',
                    'id' => $id,
                    'embed_url' => 'https://' . $host . '/embed/' . $id,
                    // maxresdefault 는 HD 업로드 영상에만 존재(없으면 빈 이미지) →
                    // 모든 영상에 존재하는 hqdefault 사용해 og:image/썸네일 깨짐 방지
                    'thumbnail_url' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                ];
            }
        }

        if (preg_match_all(
            '#<iframe\b[^>]*?\bsrc\s*=\s*["\']https?://player\.vimeo\.com/video/(\d+)#i',
            $contentHtml,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id = $m[1];
                $key = 'vimeo:' . $id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $videos[] = [
                    'provider' => 'vimeo',
                    'id' => $id,
                    'embed_url' => 'https://player.vimeo.com/video/' . $id,
                    // Vimeo 썸네일은 oEmbed 호출이 필요해 런타임 비용을 피하려고 미수집
                    'thumbnail_url' => null,
                ];
            }
        }

        return $videos;
    }

    /**
     * VideoObject JSON-LD
     *
     * @param array{provider: string, id: string, embed_url: string, thumbnail_url: ?string} $video
     * @param array{
     *   name: string,
     *   description: string,
     *   upload_date?: ?string,
     *   thumbnail_fallback?: ?string,
     *   publisher?: ?string,
     *   publisher_logo?: ?string,
     * } $articleContext
     */
    public static function buildVideoJsonLd(array $video, array $articleContext): array
    {
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $articleContext['name'] ?? '',
            'description' => $articleContext['description'] ?? '',
            'embedUrl' => $video['embed_url'],
        ];

        $thumb = $video['thumbnail_url'] ?? null;
        if ($thumb === null && !empty($articleContext['thumbnail_fallback'])) {
            $thumb = $articleContext['thumbnail_fallback'];
        }
        if ($thumb) {
            $jsonLd['thumbnailUrl'] = $thumb;
        }

        if (!empty($articleContext['upload_date'])) {
            $jsonLd['uploadDate'] = self::toIso8601($articleContext['upload_date']);
        }

        if (!empty($articleContext['publisher'])) {
            $publisher = [
                '@type' => 'Organization',
                'name' => $articleContext['publisher'],
            ];
            if (!empty($articleContext['publisher_logo'])) {
                $publisher['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $articleContext['publisher_logo'],
                ];
            }
            $jsonLd['publisher'] = $publisher;
        }

        return $jsonLd;
    }

    /**
     * 키워드 메타 자동 조합
     *
     * 빈 값/중복 제거 후 콤마로 연결. 길이/단일 토큰 길이 컷오프 적용.
     *
     * @param array<int, string> $tokens
     */
    public static function buildKeywords(array $tokens, int $maxLength = 200, int $maxTokenLength = 40): string
    {
        $clean = [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t, 'UTF-8') > $maxTokenLength) {
                continue;
            }
            if (in_array($t, $clean, true)) {
                continue;
            }
            $clean[] = $t;
        }
        $joined = implode(', ', $clean);
        if (mb_strlen($joined, 'UTF-8') > $maxLength) {
            $joined = mb_substr($joined, 0, $maxLength, 'UTF-8');
        }
        return $joined;
    }

    private static function toIso8601(string $datetime): string
    {
        try {
            return (new \DateTimeImmutable($datetime))->format(\DateTimeImmutable::ATOM);
        } catch (\Exception) {
            return $datetime;
        }
    }
}
