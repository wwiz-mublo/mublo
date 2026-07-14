<?php
namespace Mublo\Plugin\SnsLogin\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Service\Member\MemberService;

/**
 * auto_register=OFF 시 신규 가입 프로필 완성 페이지
 */
class SnsProfileController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/SnsLogin/views/Front/Profile/';

    public function __construct(
        private SnsLoginService  $loginService,
        private MemberRepository $memberRepository,
        private MemberAuthenticatorInterface $authenticator,
        private ?MemberService   $memberService = null,
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
        $fields = $this->memberService?->getFieldDefinitions($domainId, true) ?? [];

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

        if ($this->memberRepository->existsByNickname($domainId, $nickname)) {
            $this->loginService->setPendingSession($pending);
            return JsonResponse::error('이미 사용 중인 닉네임입니다. 다른 닉네임을 입력해주세요.');
        }

        // 추가 필드 유효성 검사
        $fields = $formData['fields'] ?? [];
        if (!empty($fields) && $this->memberService) {
            $fieldValidation = $this->memberService->validateFieldValues($domainId, $fields);
            if ($fieldValidation->isFailure()) {
                $this->loginService->setPendingSession($pending);
                return JsonResponse::error($fieldValidation->getMessage());
            }
        }

        // user_id 자동 생성
        $userId = 'sns_' . $pending['provider'] . '_' . substr($pending['uid'], 0, 8)
                . '_' . substr(bin2hex(random_bytes(2)), 0, 4);

        $memberId = $this->memberRepository->create([
            'domain_id'    => $domainId,
            'domain_group' => $context->getDomainGroup(),
            'user_id'      => $userId,
            'password'     => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
            'nickname'     => $nickname,
            'level_value'  => 1,
            'status'       => 'active',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        if (!$memberId) {
            return JsonResponse::error('가입 처리 중 오류가 발생했습니다.');
        }

        // 추가 필드 저장 (프론트 경로 — 현재 도메인의 비관리자 필드만 허용)
        $fields = $formData['fields'] ?? [];
        if (!empty($fields) && $this->memberService) {
            $this->memberService->saveFieldValues($memberId, $fields, $domainId);
        }

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

        $this->loginService->linkAccount($memberId, $domainId, $userInfo, $tokenData);

        if (!$this->authenticator->loginByMemberId($memberId, $request->getClientIp())) {
            return JsonResponse::error('생성된 계정으로 로그인할 수 없습니다.');
        }

        return JsonResponse::success(['redirect' => '/'], '가입이 완료되었습니다.');
    }
}
