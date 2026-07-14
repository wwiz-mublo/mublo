<?php
namespace Mublo\Core\Middleware;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Core\Response\ViewResponse;

/**
 * 관리자 HTML 응답에 코어가 보장하는 최소 보안 헤더를 적용한다.
 *
 * 블록 편집기는 관리자 화면을 same-origin iframe으로 사용하므로 DENY 대신
 * SAMEORIGIN/frame-ancestors 'self'를 기본 정책으로 사용한다.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        $response = $next($request, $context);

        if (!$context->isAdmin() || !$this->isHtmlResponse($response)) {
            return $response;
        }

        $headers = $response->getHeaders();

        if (!$this->findHeader($headers, 'X-Frame-Options')) {
            $response->withHeader('X-Frame-Options', 'SAMEORIGIN');
        }

        $cspHeader = $this->findHeader($headers, 'Content-Security-Policy');
        if ($cspHeader === null) {
            $response->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
        } elseif (!preg_match('/(?:^|;)\s*frame-ancestors\b/i', $cspHeader['value'])) {
            $response->withHeader(
                $cspHeader['name'],
                rtrim($cspHeader['value'], " \t\n\r\0\x0B;") . "; frame-ancestors 'self'"
            );
        }

        return $response;
    }

    private function isHtmlResponse(AbstractResponse $response): bool
    {
        return $response instanceof ViewResponse || $response instanceof HtmlResponse;
    }

    /**
     * @param array<string, string> $headers
     * @return array{name: string, value: string}|null
     */
    private function findHeader(array $headers, string $name): ?array
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return ['name' => $headerName, 'value' => $value];
            }
        }

        return null;
    }
}
