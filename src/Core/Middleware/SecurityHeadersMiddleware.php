<?php
declare(strict_types=1);
namespace Mublo\Core\Middleware;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Core\Response\ViewResponse;

/**
 * 코어가 보장하는 최소 보안 헤더를 적용한다.
 *
 * 세 층으로 나뉜다.
 *
 *  1) 모든 응답 — X-Content-Type-Options: nosniff.
 *     업로드된 파일이나 API 응답을 브라우저가 HTML/스크립트로 추측 실행하는 것을 막는다.
 *
 *  2) HTML 응답 — Referrer-Policy: strict-origin-when-cross-origin.
 *     외부로 나가는 요청에 전체 URL(경로·쿼리)이 실려 나가지 않게 한다.
 *
 *  3) 관리자 HTML — X-Frame-Options 와 frame-ancestors.
 *     프론트에는 걸지 않는다. 게시판·상품 위젯을 다른 사이트에 iframe 으로
 *     붙이는 것은 정상적인 사용이라 코어가 막을 일이 아니다.
 *
 * 블록 편집기는 관리자 화면을 same-origin iframe 으로 쓰므로 DENY 대신
 * SAMEORIGIN/frame-ancestors 'self' 를 기본 정책으로 한다.
 *
 * CSP 는 넣지 않는다. 블록의 raw JS 를 편집 신뢰 사용자에게 열어 둔 정책과
 * 충돌하므로, 필요한 설치본이 직접 설정할 영역이다.
 *
 * 이미 설정된 헤더는 덮지 않는다 — 컨트롤러나 확장이 의도적으로 정한 값이 우선이다.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        $response = $next($request, $context);
        $headers = $response->getHeaders();

        if (!$this->findHeader($headers, 'X-Content-Type-Options')) {
            $response->withHeader('X-Content-Type-Options', 'nosniff');
        }

        if (!$this->isHtmlResponse($response)) {
            return $response;
        }

        if (!$this->findHeader($headers, 'Referrer-Policy')) {
            $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if (!$context->isAdmin()) {
            return $response;
        }

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
