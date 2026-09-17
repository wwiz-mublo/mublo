<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Controller\Front;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\ReauthenticationInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;

class SnsAuthController
{
    private const SESSION_STATE    = 'sns_oauth_state';
    private const SESSION_REDIRECT = 'sns_login_redirect';

    /** 왕복의 목적 — 콜백 주소를 하나만 쓰기 위해 state 와 함께 보관한다. */
    private const INTENT_LOGIN  = 'login';
    private const INTENT_REAUTH = 'reauth';

    public function __construct(
        private SnsProviderRegistry   $registry,
        private SnsLoginService       $loginService,
        private SnsLoginConfigService $configService,
        private SessionInterface      $session,
        private Logger                $logger,
        private AuthContextInterface  $auth,
        private SnsAccountRepository  $accountRepository,
        private ReauthenticationInterface $reauthentication,
    ) {}

    /**
     * OAuth2 인증 시작 → SNS 제공자 페이지로 리다이렉트
     *
     * GET /sns-login/auth/{provider}?redirect=/target
     */
    public function start(array $params, Context $context): RedirectResponse
    {
        return $this->beginAuthorization($params, $context, self::INTENT_LOGIN, '/login');
    }

    /**
     * 본인 확인용 재인증 시작 — 로그인한 회원이 자기 연결로 다시 인증한다.
     *
     * GET /sns-login/reauth/{provider}?redirect=/mypage/profile
     *
     * 제공자 등록 화면에 콜백 URL 을 하나 더 넣게 하지 않으려고 콜백은 로그인과 같은
     * 주소를 쓴다. 대신 무엇을 하려던 왕복인지 state 와 함께 세션에 남긴다.
     */
    public function startReauthentication(array $params, Context $context): RedirectResponse
    {
        $memberId = $this->auth->id();
        if (!$memberId) {
            return RedirectResponse::to('/login');
        }

        $providerName = $params['provider'] ?? '';

        // 연결하지 않은 제공자로는 본인을 증명할 수 없다. 남의 카카오 계정으로 로그인해
        // 확인을 받아내는 길을 막는다.
        if (!$this->accountRepository->findByMemberAndProvider($memberId, $providerName)) {
            return RedirectResponse::to('/mypage/profile?error=sns_not_linked');
        }

        return $this->beginAuthorization($params, $context, self::INTENT_REAUTH, '/mypage/profile', $memberId);
    }

    /**
     * OAuth2 인가 요청을 시작하고, 돌아왔을 때 무엇을 할지 세션에 남긴다.
     */
    private function beginAuthorization(
        array $params,
        Context $context,
        string $intent,
        string $errorBase,
        ?int $memberId = null,
    ): RedirectResponse {
        $providerName = $params['provider'] ?? '';
        $provider     = $this->registry->get($providerName);

        if (!$provider) {
            return RedirectResponse::to($errorBase . '?error=unsupported_provider');
        }

        // 활성화 여부 확인
        $domainId   = $context->getDomainId() ?? 1;
        $enabledMap = $this->configService->getEnabledMap($domainId);
        if (empty($enabledMap[$providerName])) {
            return RedirectResponse::to($errorBase . '?error=provider_disabled');
        }

        // CSRF 방지: state 생성 후 세션 저장 (10분 만료)
        $state = bin2hex(random_bytes(16));
        $this->session->set(self::SESSION_STATE, [
            'token'      => $state,
            'expires_at' => time() + 600,
            'intent'     => $intent,
            'member_id'  => $memberId,
        ]);

        // 돌아올 URL 저장 (오픈 리다이렉트 방지: 상대 경로만 허용)
        $request  = $context->getRequest();
        $redirect = $request->get('redirect', '/');
        if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = $intent === self::INTENT_REAUTH ? '/mypage/profile' : '/';
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
            $base = (is_array($savedState) && ($savedState['intent'] ?? null) === self::INTENT_REAUTH)
                ? '/mypage/profile'
                : '/login';
            return RedirectResponse::to($base . '?error=invalid_state');
        }

        $intent   = $savedState['intent'] ?? self::INTENT_LOGIN;
        $errorBase = $intent === self::INTENT_REAUTH ? '/mypage/profile' : '/login';

        $provider = $this->registry->get($providerName);
        if (!$provider || empty($code)) {
            return RedirectResponse::to($errorBase . '?error=invalid_callback');
        }

        try {
            $tokenData  = $provider->exchangeCode($code);
            $userInfo   = $provider->getUserInfo($tokenData['access_token']);
            $domainId    = $context->getDomainId() ?? 1;
            $domainGroup = $context->getDomainGroup();

            if ($intent === self::INTENT_REAUTH) {
                return $this->completeReauthentication($domainId, $userInfo, $savedState);
            }

            $result = $this->loginService->handleCallback(
                $domainId,
                $userInfo,
                $tokenData,
                $domainGroup,
                $request->getClientIp(),
                (string) $request->header('User-Agent', ''),
            );

            if ($result->isFailure()) {
                return RedirectResponse::to('/login?error=' . urlencode($result->getMessage()));
            }

            $action = $result->get('action');

            // 가입이 여러 단계로 나뉘면(약관 동의·프로필 입력) 여기서 끝나지 않는다.
            // 돌아갈 주소를 지금 지우면 마지막 단계가 그 자리를 잃어, 특정 글에서
            // 로그인한 사람이 원래 보던 곳으로 돌아가지 못한다 — 마지막 단계가 쓴다.
            if ($action === 'agreement_needed') {
                return RedirectResponse::to('/sns-login/agree');
            }

            if ($action === 'profile_needed') {
                return RedirectResponse::to('/sns-login/profile/complete');
            }

            return RedirectResponse::to($this->consumeRedirect());

        } catch (\Throwable $e) {
            $this->logger->exception($e, 'error', [
                'provider' => $providerName,
                'step'     => 'callback',
                'intent'   => $intent,
            ]);
            return RedirectResponse::to($errorBase . '?error=sns_error');
        }
    }

    /**
     * 로그인 전에 보던 곳으로 돌려보낼 주소를 꺼내고 세션에서 지운다.
     *
     * 가입이 여러 단계로 나뉘므로 마지막 단계에서 한 번만 소비해야 한다.
     */
    public static function consumeRedirectFrom(SessionInterface $session): string
    {
        $redirect = $session->get(self::SESSION_REDIRECT) ?? '/';
        $session->remove(self::SESSION_REDIRECT);

        return is_string($redirect) && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')
            ? $redirect
            : '/';
    }

    private function consumeRedirect(): string
    {
        return self::consumeRedirectFrom($this->session);
    }

    /**
     * 제공자 재인증을 마치고 본인 확인 사실을 코어에 남긴다.
     *
     * 세 가지가 모두 같은 회원을 가리켜야 한다 — 왕복을 시작한 회원, 지금 로그인한 회원,
     * 방금 인증한 SNS 계정의 주인. 하나라도 어긋나면 남의 계정으로 받아낸 확인이 된다.
     *
     * @param array<string, mixed> $savedState
     */
    private function completeReauthentication(int $domainId, SnsUserInfo $userInfo, array $savedState): RedirectResponse
    {
        $redirect = $this->session->get(self::SESSION_REDIRECT) ?? '/mypage/profile';
        $this->session->remove(self::SESSION_REDIRECT);

        $memberId = $this->auth->id();
        $startedBy = $savedState['member_id'] ?? null;

        if (!$memberId || $startedBy !== $memberId) {
            return RedirectResponse::to('/mypage/profile?error=reauth_session_changed');
        }

        $account = $this->accountRepository->findByProvider($domainId, $userInfo->provider, $userInfo->uid);
        if (!$account || $account->getMemberId() !== $memberId) {
            // 로그인한 회원의 연결이 아닌 계정으로 인증했다 — 본인 증명이 되지 않는다.
            return RedirectResponse::to('/mypage/profile?error=reauth_account_mismatch');
        }

        $this->reauthentication->confirm($memberId);

        return RedirectResponse::to($redirect);
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
