<?php
declare(strict_types=1);

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\Auth\LoginFormRenderingEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Member\RegisterFormRenderingEvent;
use Mublo\Core\Event\Member\MemberRegisterValidatingEvent;
use Mublo\Core\Http\Request;
use Mublo\Core\Session\SessionInterface;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\PolicyService;
use Mublo\Service\Auth\AuthService;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Service\CustomField\CustomFieldFileHandler;
use Mublo\Service\CustomField\CustomFieldValidator;

/**
 * Front MemberController
 *
 * 프론트 회원 컨트롤러 (회원가입 전담)
 *
 * 회원가입 3단계:
 *   Step 1: 약관 동의 (GET /member/register)
 *   Step 2: 정보 입력 (GET /member/register/form)
 *   Step 3: 가입 완료 (GET /member/register/complete)
 *
 * 마이페이지(프로필/탈퇴/포인트 등)는 MypageController 담당.
 */
class MemberController
{
    private MemberService $memberService;
    private ?MemberFieldService $fieldService;
    private PolicyService $policyService;
    private AuthService $authService;
    private SessionInterface $session;
    private ?CustomFieldFileHandler $fileHandler;

    private const SESSION_AGREEMENTS_KEY = 'register_agreements';
    private const AGREEMENT_TTL = 1800; // 30분

    public function __construct(
        MemberService $memberService,
        PolicyService $policyService,
        AuthService $authService,
        SessionInterface $session,
        ?MemberFieldService $fieldService = null,
        ?SecureFileService $secureFileService = null,
        private ?EventDispatcher $eventDispatcher = null,
        ?FileUploader $fileUploader = null,
    ) {
        $this->memberService = $memberService;
        $this->policyService = $policyService;
        $this->authService = $authService;
        $this->session = $session;
        $this->fieldService = $fieldService;
        $this->fileHandler = $secureFileService ? new CustomFieldFileHandler($secureFileService, $fileUploader) : null;
    }

    // =========================================================================
    // Step 1: 약관 동의
    // =========================================================================

    /**
     * 약관 동의 페이지
     * GET /member/register
     */
    public function registerAgree(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            return RedirectResponse::to('/');
        }

        $domainId = $context->getDomainId();
        $policies = $this->policyService->getRegisterPolicies($domainId);

        // 약관이 없으면 바로 정보 입력 단계로
        if (empty($policies)) {
            $this->session->set(self::SESSION_AGREEMENTS_KEY, [
                'agreed' => [],
                'domain_id' => $domainId,
                'timestamp' => time(),
            ]);
            return RedirectResponse::to('/member/register/form');
        }

        // 치환 변수 적용 ({#회사명}, {#사이트명} 등)
        $domainConfig = $context->getDomainInfo()->toArray();
        $renderedContents = [];
        foreach ($policies as $policy) {
            $renderedContents[$policy->getPolicyId()] = $this->policyService->replaceVariables(
                $policy->getPolicyContent(),
                $domainConfig,
                $policy
            );
        }

        $loginFormEvent = new LoginFormRenderingEvent($context);
        $this->eventDispatcher?->dispatch($loginFormEvent);

        return ViewResponse::view('member/agree')
            ->withData([
                'policies'         => $policies,
                'renderedContents' => $renderedContents,
                'loginFormExtras'  => $loginFormEvent->getHtmlSorted(),
            ]);
    }

    /**
     * 약관 동의 처리
     * POST /member/register/agree
     */
    public function registerAgreeProcess(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            if ($request->isAjax()) {
                return JsonResponse::error('이미 로그인된 상태입니다.');
            }
            return RedirectResponse::to('/');
        }

        $domainId = $context->getDomainId();
        $agreedPolicies = $request->post('agreements', []);

        $selectedPolicyIds = [];
        foreach ($agreedPolicies as $policyId => $selected) {
            if (!empty($selected)) {
                $selectedPolicyIds[] = (int) $policyId;
            }
        }
        $snapshot = $this->policyService->agreementSnapshot($domainId, $selectedPolicyIds);
        if ($snapshot->isFailure()) {
            if ($request->isAjax()) {
                return JsonResponse::error($snapshot->getMessage());
            }
            return RedirectResponse::back();
        }

        $this->session->set(self::SESSION_AGREEMENTS_KEY, [
            'agreed' => $snapshot->get('agreements', []),
            'domain_id' => $domainId,
            'timestamp' => time(),
        ]);

        if ($request->isAjax()) {
            return JsonResponse::success(['redirect' => '/member/register/form'], '약관에 동의하였습니다.');
        }

        return RedirectResponse::to('/member/register/form');
    }

    // =========================================================================
    // Step 2: 정보 입력
    // =========================================================================

    /**
     * 회원가입 정보 입력 페이지
     * GET /member/register/form
     */
    public function registerForm(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            return RedirectResponse::to('/');
        }

        // 약관 동의 세션 확인
        if (!$this->hasValidAgreements((int) $context->getDomainId())) {
            return RedirectResponse::to('/member/register');
        }

        $domainId = $context->getDomainId();
        $fields = $this->memberService->getFieldDefinitions($domainId, true);

        $useEmailAsUserId = $context->getDomainInfo()?->isUseEmailAsUserId() ?? false;

        // 플러그인 폼 확장 이벤트
        $formEvent = new RegisterFormRenderingEvent($context);
        $this->eventDispatcher?->dispatch($formEvent);

        return ViewResponse::view('member/register')
            ->withData([
                'fields' => $fields,
                'useEmailAsUserId' => $useEmailAsUserId,
                'registerFormExtras' => $formEvent->getHtmlSorted(),
                'registerFormScripts' => $formEvent->getScriptsSorted(),
            ]);
    }

    /**
     * 회원가입 처리
     * POST /member/register/form
     */
    public function register(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            if ($request->isAjax()) {
                return JsonResponse::error('이미 로그인된 상태입니다.');
            }
            return RedirectResponse::to('/');
        }

        // 약관 동의 세션 확인
        if (!$this->hasValidAgreements((int) $context->getDomainId())) {
            if ($request->isAjax()) {
                return JsonResponse::error('약관 동의가 필요합니다.');
            }
            return RedirectResponse::to('/member/register');
        }

        $domainId = $context->getDomainId();
        $formData = $request->input('formData') ?? [];

        // 비밀번호 확인
        $password = $formData['password'] ?? '';
        $passwordConfirm = $formData['password_confirm'] ?? '';
        if ($password !== $passwordConfirm) {
            if ($request->isAjax()) {
                return JsonResponse::error('비밀번호가 일치하지 않습니다.');
            }
            return RedirectResponse::back();
        }

        // 플러그인 검증 이벤트
        $validatingEvent = new MemberRegisterValidatingEvent($formData, $context);
        try {
            $this->eventDispatcher?->dispatch($validatingEvent);
        } catch (\Exception $e) {
            error_log('[MemberController] registration_validation_failed: ' . $e->getMessage());

            if ($request->isAjax()) {
                return JsonResponse::error(
                    '회원가입 정보를 확인하지 못했습니다. 잠시 후 다시 시도해 주세요.',
                    null,
                    503
                );
            }

            return RedirectResponse::back();
        }

        if ($validatingEvent->hasErrors()) {
            if ($request->isAjax()) {
                return JsonResponse::error(implode("\n", $validatingEvent->getErrors()));
            }
            return RedirectResponse::back();
        }

        // 세션에서 약관 동의 정보 가져오기
        $agreementData = $this->session->get(self::SESSION_AGREEMENTS_KEY, []);
        $agreements = $agreementData['agreed'] ?? [];

        $domainInfo = $context->getDomainInfo();
        $useEmailAsUserId = $domainInfo?->isUseEmailAsUserId() ?? false;
        $joinType = $domainInfo?->getJoinType() ?? 'immediate';
        $defaultLevel = $domainInfo?->getDefaultLevelValue() ?? 1;

        $data = [
            'domain_id' => $domainId,
            'domain_group' => $context->getDomainGroup(),
            'user_id' => trim($formData['user_id'] ?? ''),
            'password' => $password,
            'nickname' => trim($formData['nickname'] ?? ''),
            'fields' => $formData['fields'] ?? [],
            'agreements' => $agreements,
            'ip_address' => $request->getClientIp(),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'use_email_as_userid' => $useEmailAsUserId,
            'level_value' => $defaultLevel,
            'status' => ($joinType === 'approval') ? 'pending' : 'active',
        ];

        $result = $this->memberService->register($data, $context);

        $completeUrl = ($joinType === 'approval') ? '/member/register/pending' : '/member/register/complete';

        if ($request->isAjax()) {
            if ($result->isSuccess()) {
                // 세션에서 약관 동의 데이터 정리
                $this->session->remove(self::SESSION_AGREEMENTS_KEY);
                return JsonResponse::success(
                    ['redirect' => $completeUrl],
                    $result->getMessage()
                );
            }
            // 검증 실패(입력값)는 422 → 프론트에서 warning(노랑), 시스템 오류만 400 → error(빨강)
            $status = $result->get('_system') ? 400 : 422;
            return JsonResponse::error($result->getMessage(), null, $status);
        }

        if ($result->isSuccess()) {
            $this->session->remove(self::SESSION_AGREEMENTS_KEY);
            return RedirectResponse::to($completeUrl);
        }

        return RedirectResponse::back();
    }

    // =========================================================================
    // Step 3: 가입 완료
    // =========================================================================

    /**
     * 가입 완료 페이지
     * GET /member/register/complete
     */
    public function registerComplete(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            return RedirectResponse::to('/');
        }

        return ViewResponse::view('member/registerComplete');
    }

    /**
     * 가입 신청 완료 페이지 (관리자 승인 대기)
     * GET /member/register/pending
     */
    public function registerPending(Request $request, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->check()) {
            return RedirectResponse::to('/');
        }

        return ViewResponse::view('member/registerPending');
    }

    // =========================================================================
    // 아이디 중복 검사
    // =========================================================================

    /**
     * 아이디 중복 검사 (AJAX)
     * POST /member/check-userid
     */
    public function checkUserId(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $userId = $request->json('user_id', '') ?: $request->post('user_id', '');
        $useEmailAsUserId = $context->getDomainInfo()?->isUseEmailAsUserId() ?? false;

        // 중복확인은 검사가 정상 수행된 결과라 오류(error)가 아닌 경고(warning)로 안내한다.
        // 422 → 프론트 전역 핸들러가 showAlert(..., 'warning')로 매핑.
        if (empty($userId)) {
            $label = $useEmailAsUserId ? '이메일' : '아이디';
            return JsonResponse::error($label . '를 입력해주세요.', null, 422);
        }

        $result = $this->memberService->checkUserIdAvailability($domainId, $userId, $useEmailAsUserId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage(), null, 422);
    }

    /**
     * 닉네임 중복 확인
     * POST /member/check-nickname
     */
    public function checkNickname(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $nickname = trim($request->json('nickname', '') ?: $request->post('nickname', ''));

        // 중복확인 실패는 오류(error)가 아닌 경고(warning)로 안내한다(422 → showAlert 'warning').
        if (empty($nickname)) {
            return JsonResponse::error('닉네임을 입력해주세요.', null, 422);
        }

        $formatResult = $this->memberService->validateNickname($nickname);
        if ($formatResult->isFailure()) {
            return JsonResponse::error($formatResult->getMessage(), null, 422);
        }

        // 로그인 상태(프로필 수정)에서는 본인이 쓰던 닉네임을 중복으로 치지 않는다
        $excludeMemberId = $this->authService->user()['member_id'] ?? null;
        $dupResult = $this->memberService->checkDuplicate($domainId, 'nickname', $nickname, $excludeMemberId);

        return $dupResult->isSuccess()
            ? JsonResponse::success(null, $dupResult->getMessage())
            : JsonResponse::error($dupResult->getMessage(), null, 422);
    }

    // =========================================================================
    // 파일 업로드
    // =========================================================================

    /**
     * 추가 필드 파일 업로드 (AJAX)
     * POST /member/upload-field-file
     *
     * 파일을 임시 경로에 저장하고 메타 정보를 반환.
     * 회원가입/프로필 수정 시 저장된 temp_path 를 전달하면
     * MemberService.saveFieldValues()에서 최종 경로로 이동.
     */
    public function uploadFieldFile(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $fieldId = (int) ($request->post('field_id') ?? 0);

        if ($fieldId <= 0 || !$this->fieldService || !$this->fileHandler) {
            return JsonResponse::error('파일 업로드를 처리할 수 없습니다.');
        }

        // 필드 정의 조회 (도메인 스코프를 넘겨 타 도메인 field_id 조회(IDOR) 차단)
        $field = $this->fieldService->getField($fieldId, $domainId);
        if (!$field || !CustomFieldValidator::isFileType($field['field_type'])) {
            return JsonResponse::error('유효하지 않은 필드입니다.');
        }

        // $_FILES에서 파일 추출
        $file = UploadedFile::fromGlobal('file');
        if (!$file || !$file->isValid()) {
            return JsonResponse::error($file ? $file->getErrorMessage() : '파일이 업로드되지 않았습니다.');
        }

        // CustomFieldFileHandler로 임시 업로드
        $config = json_decode($field['field_config'] ?? '{}', true) ?: [];
        $result = $this->fileHandler->uploadTemp($file, $domainId, $config);

        if (!$result->isSuccess()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(
            $this->fileHandler->buildTempResponse($result),
            '파일이 업로드되었습니다.'
        );
    }

    // =========================================================================
    // Private 헬퍼
    // =========================================================================

    /**
     * 약관 동의 세션이 유효한지 확인
     */
    private function hasValidAgreements(int $domainId): bool
    {
        $data = $this->session->get(self::SESSION_AGREEMENTS_KEY);

        if ($data === null || !isset($data['timestamp'])) {
            return false;
        }

        if ((int) ($data['domain_id'] ?? 0) !== $domainId) {
            $this->session->remove(self::SESSION_AGREEMENTS_KEY);
            return false;
        }

        // 30분 만료 체크
        if (time() - $data['timestamp'] > self::AGREEMENT_TTL) {
            $this->session->remove(self::SESSION_AGREEMENTS_KEY);
            return false;
        }

        return true;
    }
}
