<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Controller\Front;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;

class SnsAuthController
{
    private const SESSION_STATE    = 'sns_oauth_state';
    private const SESSION_REDIRECT = 'sns_login_redirect';

    public function __construct(
        private SnsProviderRegistry   $registry,
        private SnsLoginService       $loginService,
        private SnsLoginConfigService $configService,
        private SessionInterface      $session,
        private Logger                $logger,
        private AuthContextInterface  $auth,
    ) {}

    /**
     * OAuth2 인증 시작 → SNS 제공자 페이지로 리다이렉트
     *
     * GET /sns-login/auth/{provider}?redirect=/target
     */
    public function start(array $params, Context $context): RedirectResponse
    {
        $providerName = $params['provider'] ?? '';
        $provider     = $this->registry->get($providerName);

        if (!$provider) {
            return RedirectResponse::to('/login?error=unsupported_provider');
        }

        // 활성화 여부 확인
        $domainId   = $context->getDomainId() ?? 1;
        $enabledMap = $this->configService->getEnabledMap($domainId);
        if (empty($enabledMap[$providerName])) {
            return RedirectResponse::to('/login?error=provider_disabled');
        }

        // CSRF 방지: state 생성 후 세션 저장 (10분 만료)
        $state = bin2hex(random_bytes(16));
        $this->session->set(self::SESSION_STATE, [
            'token'      => $state,
            'expires_at' => time() + 600,
        ]);

        // 로그인 후 돌아올 URL 저장 (오픈 리다이렉트 방지: 상대 경로만 허용)
        $request  = $context->getRequest();
        $redirect = $request->get('redirect', '/');
        if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = '/';
        }
        $this->session->set(self::SESSION_REDIRECT, $redirect);

        return RedirectResponse::to($provider->getAuthorizationUrl($state));
    }

    /**
     * OAuth2 콜백 처리
     *
     * GET /sns-login/callback/{provider}?code=XXX&state=YYY
     */
    public function callback(array $params, Context $context): RedirectResponse
    {
        $request      = $context->getRequest();
        $providerName = $params['provider'] ?? '';
        $code         = $request->get('code', '');
        $state        = $request->get('state', '');

        // state 검증 (CSRF + 만료)
        $savedState = $this->session->get(self::SESSION_STATE);
        $this->session->remove(self::SESSION_STATE);

        if (
            !is_array($savedState) ||
            empty($savedState['token']) ||
            !hash_equals($savedState['token'], $state) ||
            time() > ($savedState['expires_at'] ?? 0)
        ) {
            return RedirectResponse::to('/login?error=invalid_state');
        }

        $provider = $this->registry->get($providerName);
        if (!$provider || empty($code)) {
            return RedirectResponse::to('/login?error=invalid_callback');
        }

        try {
            $tokenData  = $provider->exchangeCode($code);
            $userInfo   = $provider->getUserInfo($tokenData['access_token']);
            $domainId    = $context->getDomainId() ?? 1;
            $domainGroup = $context->getDomainGroup();

            $result = $this->loginService->handleCallback($domainId, $userInfo, $tokenData, $domainGroup, $context->getRequest()->getClientIp());

            if ($result->isFailure()) {
                return RedirectResponse::to('/login?error=' . urlencode($result->getMessage()));
            }

            $action   = $result->get('action');
            $redirect = $this->session->get(self::SESSION_REDIRECT) ?? '/';
            $this->session->remove(self::SESSION_REDIRECT);

            if ($action === 'profile_needed') {
                return RedirectResponse::to('/sns-login/profile/complete');
            }

            return RedirectResponse::to($redirect);

        } catch (\Throwable $e) {
            $this->logger->exception($e, 'error', [
                'provider' => $providerName,
                'step'     => 'callback',
            ]);
            return RedirectResponse::to('/login?error=sns_error');
        }
    }

    /**
     * SNS 연결 해제
     *
     * POST /sns-login/unlink
     */
    public function unlink(array $params, Context $context): JsonResponse
    {
        $request  = $context->getRequest();
        $provider = trim($request->input('provider') ?? '');

        if (!$this->registry->has($provider)) {
            return JsonResponse::error('지원하지 않는 제공자입니다.');
        }

        // 로그인 회원은 Context 가 아니라 인증 계약에서 얻는다.
        $memberId = $this->auth->id();
        if (!$memberId) {
            return JsonResponse::error('로그인이 필요합니다.');
        }

        $result = $this->loginService->unlinkAccount($memberId, $provider);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
