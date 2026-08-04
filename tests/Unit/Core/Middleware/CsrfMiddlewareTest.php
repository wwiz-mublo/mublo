<?php

namespace Tests\Unit\Core\Middleware;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Middleware\CsrfMiddleware;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Infrastructure\Security\CsrfManager;
use PHPUnit\Framework\TestCase;

class CsrfMiddlewareTest extends TestCase
{
    public function testBearerHeaderDoesNotBypassCsrfValidation(): void
    {
        $middleware = new CsrfMiddleware(new FakeCsrfManager(false));
        $request = new Request('POST', '/api/protected', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer arbitrary-token',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $context = $this->createMock(Context::class);

        $nextCalled = false;
        $response = $middleware->handle($request, $context, function () use (&$nextCalled): AbstractResponse {
            $nextCalled = true;
            return JsonResponse::success();
        });

        $this->assertFalse($nextCalled);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(419, $response->getStatusCode());
    }

    public function testHtmlCsrfFailureReturns419NotOk(): void
    {
        // 비-AJAX(브라우저 폼) POST + 무효 토큰 → HTML 에러 페이지.
        // 과거엔 상태코드가 없어 200 OK 로 나갔다(모니터링·크롤러·스캐너 오인). 419 로 고정.
        $middleware = new CsrfMiddleware(new FakeCsrfManager(false));
        $request = new Request('POST', '/some/form');
        $context = $this->createMock(Context::class);
        $context->method('isAdmin')->willReturn(false);

        $response = $middleware->handle($request, $context, function (): AbstractResponse {
            return JsonResponse::success();
        });

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(419, $response->getStatusCode());
    }

    public function testExcludePathStillBypassesCsrfValidation(): void
    {
        $middleware = new CsrfMiddleware(new FakeCsrfManager(false));
        $middleware->addExcludePath('/webhook/');

        $request = new Request('POST', '/webhook/callback');
        $context = $this->createMock(Context::class);

        $response = $middleware->handle($request, $context, function (): AbstractResponse {
            return JsonResponse::success(['ok' => true]);
        });

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExcludePathDoesNotBypassOnPartialPathMatch(): void
    {
        $middleware = new CsrfMiddleware(new FakeCsrfManager(false));
        $middleware->addExcludePath('/webhook/');

        $request = new Request('POST', '/payment/webhook/callback', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $context = $this->createMock(Context::class);

        $nextCalled = false;
        $response = $middleware->handle($request, $context, function () use (&$nextCalled): AbstractResponse {
            $nextCalled = true;
            return JsonResponse::success(['ok' => true]);
        });

        $this->assertFalse($nextCalled);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(419, $response->getStatusCode());
    }
}

class FakeCsrfManager extends CsrfManager
{
    public function __construct(private readonly bool $valid)
    {
    }

    // 미들웨어의 토큰 워밍이 실제 세션을 건드리지 않도록 override
    public function getToken(): string
    {
        return 'fake-token';
    }

    public function validateToken(string $token): bool
    {
        return $this->valid;
    }
}
