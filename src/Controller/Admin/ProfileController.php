<?php

namespace Mublo\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Member\MemberService;

/**
 * Admin ProfileController
 *
 * 관리자 본인 정보 수정
 *
 * 라우트 (autoResolve):
 *   GET  /admin/profile         → index()
 *   POST /admin/profile/update  → update()
 */
class ProfileController
{
    public function __construct(
        private AuthService $authService,
        private MemberService $memberService,
    ) {}

    /**
     * GET /admin/profile
     */
    public function index(Request $request, Context $context): ViewResponse
    {
        $user = $this->authService->user();

        // 비밀번호 규칙(최소 길이/복잡도)은 도메인 설정을 반영 (하드코딩 금지)
        $passwordPolicy = $this->memberService->getPasswordPolicy($context->getDomainId());

        return ViewResponse::view('Profile/Index')
            ->withData([
                'pageTitle' => '내 정보',
                'user' => $user,
                'passwordHint' => $passwordPolicy->describe(),
                'passwordMinLength' => $passwordPolicy->getMinLength(),
                'passwordRequireNumber' => $passwordPolicy->requiresNumber(),
                'passwordRequireSpecial' => $passwordPolicy->requiresSpecial(),
            ]);
    }

    /**
     * 닉네임 중복/형식 확인 (본인 제외)
     *
     * POST /admin/profile/check-nickname
     */
    public function checkNickname(Request $request, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $nickname = trim($request->input('value') ?? $request->json('value') ?? '');

        if ($nickname === '') {
            return JsonResponse::error('닉네임을 입력해주세요.');
        }

        // 형식 검증
        $formatCheck = $this->memberService->validateNickname($nickname);
        if ($formatCheck->isFailure()) {
            return JsonResponse::error($formatCheck->getMessage());
        }

        // 중복 검증 (본인 제외)
        $domainId = $context->getDomainId() ?? (int) ($user['domain_id'] ?? 1);
        $dup = $this->memberService->checkDuplicate(
            $domainId,
            'nickname',
            $nickname,
            (int) ($user['member_id'] ?? 0)
        );

        return JsonResponse::success(['duplicate' => $dup->isFailure()], $dup->getMessage());
    }

    /**
     * POST /admin/profile/update
     */
    public function update(Request $request, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $formData = $request->input('formData') ?? [];

        $data = [];

        $nickname = trim($formData['nickname'] ?? '');
        if ($nickname !== '') {
            $data['nickname'] = $nickname;
        }

        $newPassword = $formData['new_password'] ?? '';
        if ($newPassword !== '') {
            // 현재 비밀번호 재확인 — 관리자 세션 탈취·미인증 단말에서 비번 재설정으로 계정을
            // 영구 장악하는 것을 막는다(Front 마이페이지와 대칭 — 본인 비번 변경 불변식).
            $currentPassword = $formData['current_password'] ?? '';
            if (!$this->memberService->verifyPassword($user['member_id'], $currentPassword)) {
                return JsonResponse::error('현재 비밀번호가 일치하지 않습니다.', null, 422);
            }

            $confirmPassword = $formData['new_password_confirm'] ?? '';
            if ($newPassword !== $confirmPassword) {
                return JsonResponse::error('새 비밀번호가 일치하지 않습니다.', null, 422);
            }
            $data['password'] = $newPassword;
        }

        if (empty($data)) {
            return JsonResponse::error('변경할 항목이 없습니다.', null, 422);
        }

        $result = $this->memberService->update($user['member_id'], $data);

        // 검증/업무 규칙 실패는 422(→ 프론트에서 warning으로 안내), 시스템 오류가 아님
        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage(), null, 422);
    }
}
