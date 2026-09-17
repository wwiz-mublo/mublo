<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Controller\Front;

use Mublo\Contract\Member\PolicyQueryInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Result\Result;
use Mublo\Core\Session\SessionInterface;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;

/**
 * SNS 가입 약관 동의 단계
 *
 * 가입 약관을 운영하는 사이트에서는 SNS 가입도 동의를 받아야 한다. 종전에는 바로 가입이
 * 버튼 한 번에 끝나 동의를 받을 화면이 없었고, 그래서 같은 사이트인데 SNS 회원만 동의
 * 이력이 비어 있었다. 약관을 쓰지 않는 사이트는 이 화면을 지나가지 않는다.
 *
 * 화면은 여기서 그리지만 판정은 코어가 한다 — 필수 약관 누락 여부도, 동의 증빙(버전·
 * 내용 해시)도 코어가 만든다.
 */
class SnsAgreeController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/SnsLogin/views/Front/Agree/';

    public function __construct(
        private SnsLoginService $loginService,
        private PolicyQueryInterface $policies,
        private SessionInterface $session,
    ) {}

    /**
     * 동의 폼 표시
     * GET /sns-login/agree
     */
    public function form(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $pending = $this->loginService->getPendingSession();
        if (!$pending) {
            return RedirectResponse::to('/login');
        }

        $domainId  = (int) $pending['domain_id'];
        $documents = $this->loginService->registerPolicies($domainId);

        // 화면에 왔는데 약관이 없다면 설정이 그 사이에 바뀐 것이다. 빈 화면을 보여주는
        // 대신 원래 가려던 곳으로 보낸다.
        if ($documents === []) {
            return RedirectResponse::to('/sns-login/agree/skip');
        }

        $domainConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
        $rendered     = [];
        foreach ($documents as $document) {
            $rendered[$document->policyId] = $this->policies->renderDocument($document, $domainConfig);
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Index')
            ->withData([
                'pageTitle'        => 'SNS 로그인 - 약관 동의',
                'provider'         => $pending['provider'] ?? '',
                'documents'        => $documents,
                'renderedContents' => $rendered,
            ]);
    }

    /**
     * 동의 처리
     * POST /sns-login/agree
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $pending = $this->loginService->getPendingSession();

        if (!$pending) {
            return JsonResponse::error('세션이 만료되었습니다. 다시 로그인해주세요.');
        }

        $agreedPolicyIds = [];
        foreach ((array) ($request->input('agreements') ?? []) as $policyId => $checked) {
            if (!empty($checked)) {
                $agreedPolicyIds[] = (int) $policyId;
            }
        }

        // 필수 약관을 빠뜨렸는지는 코어가 판정한다 — 확장이 판단하면 사이트마다 기준이
        // 갈리고, 화면을 우회한 요청도 통과한다.
        $validation = $this->policies->validateRegisterAgreements(
            (int) $pending['domain_id'],
            $agreedPolicyIds,
        );
        if ($validation->isFailure()) {
            return JsonResponse::error($validation->getMessage());
        }

        $this->loginService->rememberAgreements($agreedPolicyIds);

        return $this->continueAfterAgreement($context);
    }

    /**
     * 약관이 사라진 경우의 통과 경로
     * GET /sns-login/agree/skip
     */
    public function skip(array $params, Context $context): RedirectResponse
    {
        $result = $this->loginService->continueAfterAgreement(
            $context->getRequest()->getClientIp(),
            (string) $context->getRequest()->header('User-Agent', ''),
        );

        return RedirectResponse::to($this->destinationFor($result));
    }

    private function continueAfterAgreement(Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $result  = $this->loginService->continueAfterAgreement(
            $request->getClientIp(),
            (string) $request->header('User-Agent', ''),
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(['redirect' => $this->destinationFor($result)], $result->getMessage());
    }

    private function destinationFor(Result $result): string
    {
        if ($result->isFailure()) {
            return '/login?error=' . urlencode($result->getMessage());
        }

        // 프로필 입력이 남았다면 아직 가입이 끝나지 않았다 — 돌아갈 주소는 그 단계가 쓴다.
        if ($result->get('action') === 'profile_needed') {
            return '/sns-login/profile/complete';
        }

        return SnsAuthController::consumeRedirectFrom($this->session);
    }
}
