<?php
namespace Mublo\Core\Response;

/**
 * Class RedirectResponse
 *
 * 리다이렉트 응답
 */
class RedirectResponse extends AbstractResponse
{
    protected string $location;

    public function __construct(string $location, int $statusCode = 302)
    {
        $this->location = $location;
        $this->statusCode = $statusCode;
        $this->headers['Location'] = $location;
    }

    /**
     * 정적 팩토리 메서드
     *
     * @param string $url 리다이렉트 URL
     * @param int $statusCode HTTP 상태 코드 (기본 302)
     * @return self
     */
    public static function to(string $url, int $statusCode = 302): self
    {
        return new self($url, $statusCode);
    }

    /**
     * 영구 리다이렉트 (301)
     *
     * @param string $url 리다이렉트 URL
     * @return self
     */
    public static function permanent(string $url): self
    {
        return new self($url, 301);
    }

    /**
     * 이전 페이지로 리다이렉트
     *
     * HTTP_REFERER가 같은 호스트일 때만 사용, 외부 URL은 fallback으로 대체
     *
     * @param string $fallback 이전 페이지가 없거나 외부 URL일 경우 기본 URL
     * @return self
     */
    public static function back(string $fallback = '/'): self
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        if ($referer !== null) {
            $refererScheme = parse_url($referer, PHP_URL_SCHEME);
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $currentHost = $_SERVER['HTTP_HOST'] ?? '';

            // 포트 제거 후 비교
            $currentHost = explode(':', $currentHost)[0];

            // 안전한 스킴(http/https)이고 같은 호스트일 때만 리다이렉트
            if (in_array($refererScheme, ['http', 'https'], true)
                && $refererHost === $currentHost
            ) {
                return new self($referer);
            }
        }

        return new self($fallback);
    }

    public function getLocation(): string
    {
        return $this->location;
    }
}
