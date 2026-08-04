<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Helper;

/**
 * 게시판 외부 링크 URL 정책.
 *
 * 저장·조회·클릭 경계에서 동일한 규칙을 사용해 기존 위험 데이터까지 차단한다.
 */
final class BoardLinkUrlPolicy
{
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $url = trim($value);
        $parts = parse_url($url);
        if ($url === ''
            || strlen($url) > 500
            || preg_match('/[\x00-\x20\x7F]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $url;
    }
}
