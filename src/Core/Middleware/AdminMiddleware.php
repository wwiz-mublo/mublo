<?php
declare(strict_types=1);

namespace Mublo\Core\Middleware;

use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Admin\AdminMenuService;
use Mublo\Service\Admin\AdminPermissionService;

/**
 * Admin Middleware
 *
 * 관리자 접근 제어 (로그인 + 권한 체크)
 *
 * 흐름:
 * 1. 로그인 체크 → 미로그인시 /admin/login 리다이렉트
 * 2. 관리자 여부 체크 → 비관리자는 403
 * 3. 슈퍼관리자 체크 → is_super=1이면 모든 권한 패스
 * 4. 권한 체크 → denied_menus 테이블 조회, 차단시 403
 */
class AdminMiddleware implements MiddlewareInterface
{
    private AuthService $auth;
    private AdminMenuService $menuService;
    private AdminPermissionService $permissionService;
    private ?Logger $logger;

    public function __construct(
        AuthService $auth,
        AdminMenuService $menuService,
        AdminPermissionService $permissionService,
        ?Logger $logger = null
    ) {
        $this->auth = $auth;
        $this->menuService = $menuService;
        $this->permissionService = $permissionService;
        $this->logger = $logger;
    }

    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        $path = $request->getPath();

        // 1. 로그인 상태 확인
        if ($this->auth->guest()) {
            return $this->handleUnauthorized($request, '/admin/login');
        }

        // 2. 관리자 권한 확인
        if (!$this->auth->isAdmin()) {
            $this->logAccessDenied($path, 'not_admin', $this->auth->user());
            return $this->handleUnauthorized($request, '/admin/login?error=unauthorized');
        }

        // 2.5 권한/계정상태 DB 재검증 (세션 스냅샷 stale 방지)
        // 로그인 이후 강등·차단·탈퇴된 관리자가 세션 만료 전까지 권한을 유지하는 것을 막는다.
        // revalidatePrivileges가 세션 is_admin/is_super를 최신화하므로, 재검증 후 다시 관리자
        // 여부를 확인해 강등(관리자 → 일반)도 즉시 반영한다. (내부적으로 최대 60초 스로틀)
        $preUser = $this->auth->user();
        if (!$this->auth->revalidatePrivileges() || !$this->auth->isAdmin()) {
            $this->logAccessDenied($path, 'revalidation_failed', $preUser);
            $this->auth->logout();
            return $this->handleUnauthorized($request, '/admin/login?error=session_expired');
        }

        // 3. 슈퍼관리자는 모든 권한 패스
        if ($this->auth->isSuper()) {
            return $next($request, $context);
        }

        // 4. 권한 체크 (네거티브 방식)
        $activeCode = $this->menuService->getActiveCode($path);
        $action = $this->permissionService->detectAction($path, $request->getMethod());
        $user = $this->auth->user();

        if ($this->permissionService->isDenied(
            $context->getDomainId(),
            $user['level_value'],
            $activeCode,
            $action,
            $context->getDomainGroup()
        )) {
            $this->logAccessDenied($path, 'permission_denied', $user, [
                'activeCode' => $activeCode,
                'action' => $action,
                'domainId' => $context->getDomainId(),
                'domainGroup' => $context->getDomainGroup(),
            ]);
            return $this->handleForbidden($request);
        }

        return $next($request, $context);
    }

    /**
     * 미인증 처리
     */
    private function handleUnauthorized(Request $request, string $redirectUrl): AbstractResponse
    {
        if ($request->isAjax()) {
            return JsonResponse::unauthorized('로그인이 필요합니다.');
        }

        return new RedirectResponse($redirectUrl);
    }

    /**
     * 권한 없음 처리 (403)
     */
    private function handleForbidden(Request $request): AbstractResponse
    {
        if ($request->isAjax()) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        return ViewResponse::view('Error/403')
            ->fullPage()
            ->withStatusCode(403)
            ->withData(['message' => '접근 권한이 없습니다.']);
    }

    /**
     * 접근 거부 로깅 (진단용)
     */
    private function logAccessDenied(string $path, string $reason, ?array $user, array $extra = []): void
    {
        $context = array_merge([
            'path' => $path,
            'reason' => $reason,
            'user_id' => $user['user_id'] ?? 'unknown',
            'level_value' => $user['level_value'] ?? 0,
            'is_super' => $user['is_super'] ?? false,
            'is_admin' => $user['is_admin'] ?? false,
        ], $extra);

        if ($this->logger !== null) {
            $this->logger->warning('[AdminMiddleware] Access denied: ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }
}
