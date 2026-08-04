<?php
declare(strict_types=1);

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Auth\ProxyLoginService;

/**
 * Admin AuthController
 *
 * 관리자 인증 컨트롤러
 * - 로그인 폼
 * - 로그인 처리
 * - 로그아웃
 */
class AuthController
{
    private AuthService $authService;
    private ProxyLoginService $proxyLoginService;

    public function __construct(AuthService $authService, ProxyLoginService $proxyLoginService)
    {
        $this->authService = $authService;
        $this->proxyLoginService = $proxyLoginService;
    }

    /**
     * 로그인 폼 표시
     *
     * GET /admin/login
     */
    public function loginForm(array $params, Context $context): ViewResponse|RedirectResponse
    {
        // 이미 로그인된 관리자는 대시보드로 리다이렉트
        if ($this->authService->isAdmin()) {
            return RedirectResponse::to('/admin/dashboard');
        }

        return ViewResponse::view('auth/login')
            ->fullPage()
            ->withData([
                'pageTitle' => '관리자 로그인',
            ]);
    }

    /**
     * 로그인 처리
     *
     * POST /admin/login
     */
    public function login(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $request = $context->getRequest();

        $formData = $request->post('formData') ?? [];
        $userId = $formData['user_id'] ?? '';
        $password = $formData['password'] ?? '';

        // 입력값 검증
        if (empty($userId) || empty($password)) {
            return ViewResponse::view('auth/login')
                ->fullPage()
                ->withData([
                    'pageTitle' => '관리자 로그인',
                    'error' => '아이디와 비밀번호를 입력해주세요.',
                    'user_id' => $userId,
                ]);
        }

        // 로그인 시도 (도메인 스코프 적용)
        $domainId = $context->getDomainId();
        if ($domainId === null) {
            return ViewResponse::view('auth/login')
                ->fullPage()
                ->withData([
                    'pageTitle' => '관리자 로그인',
                    'error' => '도메인 정보를 찾을 수 없습니다.',
                    'user_id' => $userId,
                ]);
        }

        $result = $this->authService->attempt($domainId, $userId, $password, $request->getClientIp());

        if ($result->isFailure()) {
            return ViewResponse::view('auth/login')
                ->fullPage()
                ->withData([
                    'pageTitle' => '관리자 로그인 실패',
                    'error' => $result->getMessage(),
                    'user_id' => $userId,
                ]);
        }

        // 관리자 권한 확인
        if (!$this->authService->isAdmin()) {
            $this->authService->logout();

            return ViewResponse::view('auth/login')
                ->fullPage()
                ->withData([
                    'pageTitle' => '관리자 로그인 권한 없음',
                    'error' => '관리자 권한이 없습니다.',
                    'user_id' => $userId,
                ]);
        }

        // 로그인 성공 - 대시보드로 리다이렉트
        return RedirectResponse::to('/admin/dashboard');
    }

    /**
     * 대리 로그인 토큰 검증 및 자동 로그인
     *
     * GET /admin/proxy-login?token=xxx
     */
    public function proxyLoginVerify(array $params, Context $context): RedirectResponse
    {
        $request = $context->getRequest();
        $token = $request->get('token') ?? '';

        if (empty($token)) {
            return RedirectResponse::to('/admin/login');
        }

        $domainId = $context->getDomainId();
        if (!$domainId) {
            return RedirectResponse::to('/admin/login');
        }

        $result = $this->proxyLoginService->verifyToken($token, $domainId);

        if ($result->isFailure()) {
            return RedirectResponse::to('/admin/login');
        }

        $member = $result->get('member');

        // 대상 계정이 활성 상태가 아니면(차단·탈퇴·휴면) 대리 로그인 거부.
        // (loginByMember 도 최종 fail-closed 게이트를 갖지만, 여기서 깔끔히 리다이렉트한다.)
        if (!$member || !$member->isActive()) {
            return RedirectResponse::to('/admin/login');
        }

        // 대상 계정으로 로그인 (기본은 도메인 소유자, 지정 시 그 회원)
        $this->authService->loginByMember($member);

        // 자기 세션 인계(호스트명 변경 후 새 주소로 옮겨 탄 경우)는 대리 로그인이
        // 아니므로 배너·상위 관리자 모드를 걸지 않는다.
        if (!$result->get('is_self_handoff', false)) {
            // 대리 로그인 정보 세션에 저장 (수수료 설정 등 상위 관리자 기능 활성화용)
            $this->authService->setProxyLogin(
                (int) $result->get('source_domain_id'),
                (int) $result->get('admin_member_id'),
                $result->get('admin_nickname', '관리자'),
                $result->get('site_name', '')
            );
        }

        $redirectUrl = $result->get('redirect_url', '/admin/dashboard');
        return RedirectResponse::to($redirectUrl);
    }

    /**
     * 로그아웃
     *
     * POST /admin/logout
     */
    public function logout(array $params, Context $context): JsonResponse|RedirectResponse
    {
        $this->authService->logout();

        $request = $context->getRequest();
        if ($request->isAjax()) {
            return JsonResponse::success(null, '로그아웃되었습니다.');
        }

        return RedirectResponse::to('/admin/login');
    }
}
