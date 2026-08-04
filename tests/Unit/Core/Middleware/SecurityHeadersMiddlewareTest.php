<?php

namespace Tests\Unit\Core\Middleware;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Middleware\SecurityHeadersMiddleware;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use PHPUnit\Framework\TestCase;

class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAdminViewUsesSameOriginFrameProtection(): void
    {
        $request = new Request('GET', '/admin');
        $context = new Context($request);
        $context->setAdmin(true);

        $response = (new SecurityHeadersMiddleware())->handle(
            $request,
            $context,
            fn (): AbstractResponse => ViewResponse::view('Dashboard/Index')->fullPage()
        );

        $this->assertSame('SAMEORIGIN', $response->getHeaders()['X-Frame-Options']);
        $this->assertSame("frame-ancestors 'self'", $response->getHeaders()['Content-Security-Policy']);
    }

    public function testExistingCspIsExtendedWithoutOverwritingOtherDirectives(): void
    {
        $request = new Request('GET', '/admin');
        $context = new Context($request);
        $context->setAdmin(true);
        $view = ViewResponse::view('Dashboard/Index')
            ->withHeader('content-security-policy', "default-src 'self'");

        $response = (new SecurityHeadersMiddleware())->handle(
            $request,
            $context,
            fn (): AbstractResponse => $view
        );

        $this->assertSame(
            "default-src 'self'; frame-ancestors 'self'",
            $response->getHeaders()['content-security-policy']
        );
    }

    public function testExplicitFramePolicyIsPreserved(): void
    {
        $request = new Request('GET', '/admin/standalone');
        $context = new Context($request);
        $context->setAdmin(true);
        $view = ViewResponse::view('Standalone')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'");

        $response = (new SecurityHeadersMiddleware())->handle(
            $request,
            $context,
            fn (): AbstractResponse => $view
        );

        $this->assertSame('DENY', $response->getHeaders()['X-Frame-Options']);
        $this->assertSame(
            "default-src 'self'; frame-ancestors 'none'",
            $response->getHeaders()['Content-Security-Policy']
        );
    }

    public function testFrontAndJsonResponsesAreNotModified(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $frontRequest = new Request('GET', '/');
        $frontContext = new Context($frontRequest);

        $frontResponse = $middleware->handle(
            $frontRequest,
            $frontContext,
            fn (): AbstractResponse => ViewResponse::view('Home/Index')
        );

        $adminRequest = new Request('GET', '/admin/api/status');
        $adminContext = new Context($adminRequest);
        $adminContext->setAdmin(true);
        $jsonResponse = $middleware->handle(
            $adminRequest,
            $adminContext,
            fn (): AbstractResponse => JsonResponse::success()
        );

        $this->assertArrayNotHasKey('X-Frame-Options', $frontResponse->getHeaders());
        $this->assertArrayNotHasKey('X-Frame-Options', $jsonResponse->getHeaders());
    }

    public function testFrontHtmlGetsSniffingAndReferrerProtection(): void
    {
        $request = new Request('GET', '/');
        $context = new Context($request);

        $headers = (new SecurityHeadersMiddleware())->handle(
            $request,
            $context,
            fn (): AbstractResponse => ViewResponse::view('Home/Index')
        )->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
    }

    public function testNonHtmlResponsesStillGetNosniff(): void
    {
        $request = new Request('GET', '/api/status');
        $context = new Context($request);

        $headers = (new SecurityHeadersMiddleware())->handle(
            $request,
            $context,
            fn (): AbstractResponse => JsonResponse::success()
        )->getHeaders();

        // 업로드 파일·API 응답을 HTML 로 추측 실행하는 것을 막는 헤더라 HTML 이 아니어도 붙인다.
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        // Referrer-Policy 는 문서 탐색에 관한 정책이라 HTML 에만 붙인다.
        $this->assertArrayNotHasKey('Referrer-Policy', $headers);
    }

    public function testExplicitHeadersArePreserved(): void
    {
        $request = new Request('GET', '/');
        $context = new Context($request);

        $headers = (new SecurityHeadersMiddleware())->handle(
            $request,
            $context,
            fn (): AbstractResponse => ViewResponse::view('Home/Index')
                ->withHeader('X-Content-Type-Options', 'custom')
                ->withHeader('Referrer-Policy', 'no-referrer')
        )->getHeaders();

        $this->assertSame('custom', $headers['X-Content-Type-Options']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
    }
}
