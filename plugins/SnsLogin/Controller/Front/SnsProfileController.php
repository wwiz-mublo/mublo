<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;

/**
 * auto_register=OFF 시 신규 가입 프로필 완성 페이지
 */
class SnsProfileController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/SnsLogin/views/Front/Profile/';

    public function __construct(
        private SnsLoginService  $loginService,
        private MemberAccountGatewayInterface $memberAccounts,
        private MemberAuthenticatorInterface $authenticator,
        private SnsLoginConfigService $configService,
    ) {}

    /**
     * 프로필 완성 폼 표시
     * GET /sns-login/profile/complete
     */
    public function form(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $pending = $this->loginService->getPendingSession();

        if (!$pending) {
            return RedirectResponse::to('/login');
        }

        $error = $context->getRequest()->get('error', '');
        $domainId = $pending['domain_id'] ?? $context->getDomainId();
        $fields = $this->memberAccounts->customFieldDefinitions($domainId);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Complete')
            ->withData([
                'pageTitle' => 'SNS 로그인 - 프로필 설정',
                'pending'   => $pending,
                'error'     => $error,
                'fields'    => $fields,
            ]);
    }

    /**
     * 프로필 완성 처리
     * POST /sns-login/profile/complete
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $request  = $context->getRequest();
        $formData = $request->input('formData') ?? [];
        $pending  = $this->loginService->consumePendingSession();

        if (!$pending) {
            return JsonResponse::error('세션이 만료되었습니다. 다시 로그인해주세요.');
        }

        $domainId = $pending['domain_id'];
        $nickname = trim($formData['nickname'] ?? $pending['nickname'] ?? '');

        if (empty($nickname)) {
            $this->loginService->setPendingSession($pending);
            return JsonResponse::error('닉네임을 입력해주세요.');
        }

        if ($this->memberAccounts->nicknameExists($domainId, $nickname)) {
            $this->loginService->setPendingSession($pending);
            return JsonResponse::error('이미 사용 중인 닉네임입니다. 다른 닉네임을 입력해주세요.');
        }

        // 추가 필드 유효성 검사
        $fields = $formData['fields'] ?? [];
        if (!empty($fields)) {
            $fieldValidation = $this->memberAccounts->validateCustomFields($domainId, $fields);
            if ($fieldValidation->isFailure()) {
                $this->loginService->setPendingSession($pending);
                return JsonResponse::error($fieldValidation->getMessage());
            }
        }

        $credentials = $this->loginService->generateCredentials($pending['provider'], $pending['uid']);

        $userInfo  = new SnsUserInfo(
            provider:     $pending['provider'],
            uid:          $pending['uid'],
            email:        $pending['email'],
            nickname:     $nickname,
            profileImage: $pending['profile_image'],
        );
        $tokenData = [
            'access_token'  => $pending['access_token'],
            'refresh_token' => $pending['refresh_token'],
            'expires_in'    => $pending['expires_in'],
        ];

        try {
            $memberId = $this->memberAccounts->create(new MemberRegistrationRequest(
                domainId: $domainId,
                userId: $credentials['user_id'],
                passwordHash: $credentials['password_hash'],
                nickname: $nickname,
                // 바로 가입과 같은 값을 쓴다. 종전에는 이 경로만 기본 레벨로 가입해
                // 관리자가 정한 가입 레벨이 가입 방식에 따라 갈렸다.
                levelValue: $this->configService->getRegisterLevel($domainId),
                originDomainId: $domainId,
                domainGroup: $context->getDomainGroup(),
            ), function (int $memberId) use ($domainId, $fields, $userInfo, $tokenData): void {
                if (!empty($fields)) {
                    $this->memberAccounts->saveCustomFields($memberId, $domainId, $fields);
                }
                $this->loginService->linkAccount($memberId, $domainId, $userInfo, $tokenData);
            });
        } catch (DatabaseException $e) {
            // 저장 실패만 사용자에게 재시도로 안내한다. 코어는 회원·추가 필드·SNS 연결을
            // 한 트랜잭션에 묶으므로 이 시점에 남은 회원 행은 없다.
            // LogicException(외부 트랜잭션)과 \Error 는 프로그래밍 오류라 잡지 않고 올려보낸다.
            error_log('[SnsProfileController::store] ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->loginService->setPendingSession($pending);
            return JsonResponse::error('가입 처리 중 오류가 발생했습니다.');
        }

        if (!$this->authenticator->loginByMemberId($memberId, $request->getClientIp())) {
            return JsonResponse::error('생성된 계정으로 로그인할 수 없습니다.');
        }

        return JsonResponse::success(['redirect' => '/'], '가입이 완료되었습니다.');
    }
}
