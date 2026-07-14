<?php

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Auth\LoginFormRenderingEvent;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Member\PasswordResetService;

/**
 * Front AuthController
 *
 * 프론트 인증 컨트롤러
 * - 로그인 페이지/처리
 * - 로그아웃
 * - 계정 찾기 (아이디/비밀번호)
 * - 비밀번호 재설정 (이메일 토큰 기반)
 */
class AuthController
{
    private AuthService $authService;
    private MemberService $memberService;
    private PasswordResetService $passwordResetService;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        AuthService $authService,
        MemberService $memberService,
        PasswordResetService $passwordResetService,
        EventDispatcher $eventDispatcher
    ) {
        $this->authService = $authService;
        $this->memberService = $memberService;
        $this->passwordResetService = $passwordResetService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 로그인 페이지
     * GET /login
     */
    public function loginForm(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            return RedirectResponse::to('/');
        }

        // 패키지 확장점: 로그인 폼에 HTML 주입 (예: 비회원 주문 버튼)
        $loginFormEvent = new LoginFormRenderingEvent($context);
        $this->eventDispatcher->dispatch($loginFormEvent);

        $useEmailAsUserId = $context->getDomainInfo()?->isUseEmailAsUserId() ?? false;

        return ViewResponse::view('auth/login')
            ->withData([
                'redirect' => $this->sanitizeRedirectUrl($request->get('redirect', '/')),
                'useEmailAsUserId' => $useEmailAsUserId,
                'loginFormExtras' => $loginFormEvent->getHtmlSorted(),
            ]);
    }

    /**
     * 로그인 처리
     * POST /login
     */
    public function login(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        $userId = $request->post('user_id', '');
        $password = $request->post('password', '');
        $redirect = $this->sanitizeRedirectUrl($request->post('redirect', '/'));

        if (empty($userId) || empty($password)) {
            if ($request->isAjax()) {
                return JsonResponse::error('아이디와 비밀번호를 입력해주세요.');
            }
            return RedirectResponse::back();
        }

        $domainId = $context->getDomainId();
        if ($domainId === null) {
            if ($request->isAjax()) {
                return JsonResponse::error('도메인 정보를 찾을 수 없습니다.');
            }
            return RedirectResponse::back();
        }

        $result = $this->authService->attempt($domainId, $userId, $password, $request->getClientIp());

        if ($request->isAjax()) {
            if ($result->isSuccess()) {
                return JsonResponse::success([
                    'redirect' => $redirect,
                    'user' => $result->get('user'),
                ], $result->getMessage());
            }
            return JsonResponse::error($result->getMessage());
        }

        if ($result->isSuccess()) {
            return RedirectResponse::to($redirect);
        }

        return RedirectResponse::back();
    }

    /**
     * 로그아웃
     * GET|POST /logout
     */
    public function logout(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        $this->authService->logout();

        if ($request->isAjax()) {
            return JsonResponse::success(null, '로그아웃되었습니다.');
        }

        return RedirectResponse::to('/');
    }

    // =========================================================================
    // 계정 찾기
    // =========================================================================

    /**
     * 계정 찾기 페이지
     * GET /find-account
     */
    public function findAccountForm(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            return RedirectResponse::to('/');
        }

        $domainId = $context->getDomainId();
        $useEmailAsUserId = $context->getDomainInfo()?->isUseEmailAsUserId() ?? false;
        $hasEmailField = !$useEmailAsUserId ? $this->memberService->hasEmailField($domainId) : false;

        return ViewResponse::view('auth/findAccount')
            ->withData([
                'useEmailAsUserId' => $useEmailAsUserId,
                'hasEmailField' => $hasEmailField,
            ]);
    }

    /**
     * 아이디 찾기 (AJAX)
     * POST /find-account/find-userid
     */
    public function findUserId(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $formData = $request->input('formData') ?? [];
        $email = trim($formData['email'] ?? '');

        $result = $this->memberService->findUserIdsByEmail($domainId, $email);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 비밀번호 재설정 (이메일 토큰 기반)
    // =========================================================================

    /**
     * 비밀번호 재설정 요청 (AJAX)
     * POST /find-account/request-reset
     *
     * 토큰 생성 + 이메일 발송
     */
    public function requestReset(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $useEmailAsUserId = $context->getDomainInfo()?->isUseEmailAsUserId() ?? false;

        $formData = $request->input('formData') ?? [];
        $data = [
            'email' => trim($formData['email'] ?? ''),
            'user_id' => trim($formData['user_id'] ?? ''),
        ];

        $result = $this->passwordResetService->requestReset(
            $domainId,
            $data,
            $useEmailAsUserId,
            $request->getClientIp(),
            $request->getSchemeAndHost()
        );

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 비밀번호 재설정 폼 (이메일 링크 도착)
     * GET /find-account/reset-password?token=xxx
     */
    public function resetPasswordForm(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        $token = $request->get('token', '');

        $result = $this->passwordResetService->verifyToken($token);

        if ($result->isFailure()) {
            return ViewResponse::view('auth/resetPasswordExpired')
                ->withData(['message' => $result->getMessage()]);
        }

        return ViewResponse::view('auth/resetPassword')
            ->withData(['token' => $token]);
    }

    /**
     * 비밀번호 재설정 처리 (AJAX)
     * POST /find-account/reset-password
     */
    public function resetPassword(Request $request, Context $context): JsonResponse
    {
        $formData = $request->input('formData') ?? [];
        $token = $formData['token'] ?? '';
        $newPassword = $formData['new_password'] ?? '';
        $newPasswordConfirm = $formData['new_password_confirm'] ?? '';

        $result = $this->passwordResetService->resetPassword($token, $newPassword, $newPasswordConfirm);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 리다이렉트 URL 검증 (Open Redirect 방어)
     *
     * 내부 상대경로만 허용, 외부 URL은 '/'로 대체
     */
    private function sanitizeRedirectUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || $url === '/') {
            return '/';
        }

        // 프로토콜 상대 URL (//evil.com) 차단
        if (str_starts_with($url, '//')) {
            return '/';
        }

        // 절대 URL (http://, https://) 차단
        if (preg_match('#^https?://#i', $url)) {
            return '/';
        }

        // 내부 상대경로만 허용 (/로 시작)
        if (!str_starts_with($url, '/')) {
            return '/';
        }

        return $url;
    }
}
