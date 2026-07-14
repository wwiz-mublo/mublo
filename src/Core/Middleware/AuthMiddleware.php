<?php

namespace Mublo\Core\Middleware;

use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Service\Auth\AuthService;

/**
 * Auth Middleware
 *
 * 인증이 필요한 페이지 접근 제어
 */
class AuthMiddleware implements MiddlewareInterface
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }

    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        // 로그인 상태 확인
        if ($this->auth->guest()) {
            if ($request->isAjax()) {
                return JsonResponse::unauthorized('Unauthenticated.');
            }

            // 로그인 페이지로 리다이렉트 (원래 URL 보존)
            $loginPath = $context->isAdmin() ? '/admin/login' : '/login';
            $currentUri = $request->getUri();
            $redirectUrl = $loginPath . '?redirect=' . urlencode($currentUri);

            return new RedirectResponse($redirectUrl);
        }

        return $next($request, $context);
    }
}
