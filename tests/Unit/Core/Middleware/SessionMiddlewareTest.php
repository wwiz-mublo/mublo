<?php

namespace Tests\Unit\Core\Middleware;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Middleware\SessionMiddleware;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Infrastructure\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * SessionMiddleware 예외 경로 매칭 검증.
 *
 * CsrfMiddleware와 동일한 경계 prefix 매칭이어야 한다(부분 문자열 오탐 방지).
 * 세션 시작 여부로 제외 동작을 관찰한다.
 */
class SessionMiddlewareTest extends TestCase
{
    /** @return bool 세션이 시작되었으면 true(=제외되지 않음) */
    private function sessionStartedFor(string $path, string $excludePrefix): bool
    {
        $started = false;

        $session = $this->createMock(SessionManager::class);
        $session->method('start')->willReturnCallback(function () use (&$started): void {
            $started = true;
        });

        $middleware = new SessionMiddleware($session);
        $middleware->addExcludePath($excludePrefix);

        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);

        $middleware->handle(
            new Request('POST', $path),
            $context,
            fn (): AbstractResponse => JsonResponse::success()
        );

        return $started;
    }

    public function testCallbackChildPathSkipsSession(): void
    {
        $this->assertFalse($this->sessionStartedFor('/pay-app/callback/feedback', '/pay-app/callback/'));
    }

    public function testExactBoundarySkipsSession(): void
    {
        $this->assertFalse($this->sessionStartedFor('/pay-app/callback', '/pay-app/callback/'));
    }

    public function testSiblingPrefixDoesNotSkip(): void
    {
        // 경계가 다른 형제 경로 → 세션 시작돼야 함(오탐 방지)
        $this->assertTrue($this->sessionStartedFor('/pay-app/callback-evil', '/pay-app/callback/'));
    }

    public function testMidPathSubstringDoesNotSkip(): void
    {
        // 구 str_contains 방식이면 잘못 스킵되던 케이스
        $this->assertTrue($this->sessionStartedFor('/foo/pay-app/callback/x', '/pay-app/callback/'));
    }
}
