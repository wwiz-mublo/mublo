<?php
declare(strict_types=1);

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Service\Mypage\MypageMenuBuilder;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Session\SessionInterface;
use Mublo\Service\CustomField\CustomFieldFileHandler;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Service\CustomField\CustomFieldValidator;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Notification\MemberNotificationService;

/**
 * Front MypageController
 *
 * 마이페이지 전담 컨트롤러.
 *
 * 라우트:
 *   GET  /mypage                → index()       (→ /mypage/profile 리다이렉트)
 *   GET  /mypage/profile        → profile()
 *   POST /mypage/profile        → updateProfile()
 *   GET  /mypage/balance        → balance()      (포인트 내역)
 *   GET  /mypage/withdraw       → withdraw()
 *
 * 내가 쓴 글/댓글은 Board 패키지의 마이페이지 섹션(/mypage/board/*)으로 이관됨.
 *   POST /mypage/withdraw       → withdraw()
 */
class MypageController
{
    private ?CustomFieldFileHandler $fileHandler;

    public function __construct(
        private MemberService          $memberService,
        private AuthService            $authService,
        private BalanceManager         $balanceManager,
        private EventDispatcher        $eventDispatcher,
        private SessionInterface       $session,
        private MypageMenuBuilder      $menuBuilder,
        private ?MemberFieldService    $fieldService = null,
        private ?SecureFileService      $secureFileService = null,
        private ?FileUploader           $fileUploader = null,
        private ?MemberNotificationService $notificationService = null,
    ) {
        $this->fileHandler = $secureFileService ? new CustomFieldFileHandler($secureFileService, $fileUploader) : null;
    }

    // =========================================================================
    // 진입점
    // =========================================================================

    /**
     * GET /mypage
     */
    public function index(Request $request, Context $context): RedirectResponse
    {
        return RedirectResponse::to('/mypage/profile');
    }

    // =========================================================================
    // 회원정보수정
    // =========================================================================

    /**
     * GET /mypage/profile
     */
    public function profile(Request $request, Context $context): ViewResponse
    {
        $user     = $this->authService->user();
        $domainId = $context->getDomainId();

        $fieldDefinitions = $this->memberService->getFieldDefinitions($domainId);
        $fieldValues      = $this->memberService->getFieldValues($user['member_id']);

        $fieldValuesMap = [];
        foreach ($fieldValues as $fv) {
            $fieldValuesMap[$fv['field_id']] = $fv['field_value'];
        }

        $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];

        return $this->mypageView('Profile', 'profile', $context, [
            'user'             => $user,
            'fieldDefinitions' => $fieldDefinitions,
            'fieldValues'      => $fieldValuesMap,
            'passwordPolicy'   => [
                'min'     => (int) ($siteConfig['password_min_length'] ?? 6),
                'lower'   => !empty($siteConfig['password_require_lower']),
                'number'  => !empty($siteConfig['password_require_number']),
                'special' => !empty($siteConfig['password_require_special']),
            ],
        ]);
    }

    /**
     * POST /mypage/profile
     */
    public function updateProfile(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        $user = $this->authService->user();

        $data = [
            'nickname' => $request->post('nickname', ''),
            'fields'   => $request->post('fields', []),
        ];

        $newPassword = $request->post('new_password', '');
        if (!empty($newPassword)) {
            // 현재 비밀번호 재확인 — 세션 탈취·미인증 단말에서 비밀번호 재설정으로 계정을
            // 영구 장악하는 것을 막는다(탈퇴가 비밀번호를 요구하는 것과 대칭).
            $currentPassword = $request->post('current_password', '');
            if (!$this->memberService->verifyPassword($user['member_id'], $currentPassword)) {
                $message = '현재 비밀번호가 일치하지 않습니다.';
                return $request->isAjax()
                    ? JsonResponse::error($message)
                    : RedirectResponse::back();
            }

            $newPasswordConfirm = $request->post('new_password_confirm', '');
            if ($newPassword !== $newPasswordConfirm) {
                if ($request->isAjax()) {
                    return JsonResponse::error('새 비밀번호가 일치하지 않습니다.');
                }
                return RedirectResponse::back();
            }
            $data['password'] = $newPassword;
        }

        $result = $this->memberService->update($user['member_id'], $data);

        // 본인 정보 수정 성공 시 로그인 세션을 DB 기준으로 재동기화
        // (닉네임 등 변경분이 프론트 전역에 즉시 반영되도록)
        if ($result->isSuccess()) {
            $this->authService->refreshSession();
        }

        if ($request->isAjax()) {
            return $result->isSuccess()
                ? JsonResponse::success(null, $result->getMessage())
                : JsonResponse::error($result->getMessage());
        }

        return RedirectResponse::back();
    }

    // =========================================================================
    // 포인트 내역
    // =========================================================================

    /**
     * GET /mypage/balance
     */
    public function balance(Request $request, Context $context): ViewResponse
    {
        $user = $this->authService->user();
        $page = max(1, (int) $request->query('page', 1));

        $history = $this->balanceManager->getHistory(
            memberId: $user['member_id'],
            filters: [],
            page: $page,
            perPage: 20,
            domainId: $context->getDomainId()
        );

        // BalanceManager::getHistory()의 pagination 키를 표준 키로 정규화
        $raw = $history['pagination'];
        $pagination = [
            'totalItems'  => $raw['total'],
            'perPage'     => $raw['per_page'],
            'currentPage' => $raw['current_page'],
            'totalPages'  => $raw['total_pages'],
        ];

        return $this->mypageView('Balance', 'balance', $context, [
            'logs'       => $history['items'],
            'pagination' => $pagination,
            'balance'    => $this->balanceManager->getBalance($user['member_id'], $context->getDomainId()),
        ]);
    }

    // =========================================================================
    // 회원 알림 센터
    // =========================================================================

    public function notifications(Request $request, Context $context): ViewResponse
    {
        $service = $this->requireNotificationService();
        $user = $this->authService->user();
        $result = $service->paginate(
            $context->getDomainId(),
            (int) $user['member_id'],
            max(1, (int) $request->query('page', 1)),
            20
        );

        return $this->mypageView('Notifications', 'notifications', $context, [
            'notifications' => $result['items'],
            'pagination' => $result['pagination'],
            'unreadCount' => $service->unreadCount($context->getDomainId(), (int) $user['member_id']),
        ]);
    }

    public function openNotification(Request $request, Context $context): RedirectResponse
    {
        $service = $this->requireNotificationService();
        $user = $this->authService->user();
        $notificationId = (int) $request->post('notification_id', 0);
        if ($notificationId < 1) {
            return RedirectResponse::to('/mypage/notifications');
        }

        $target = $service->open(
            $context->getDomainId(),
            (int) $user['member_id'],
            $notificationId
        );
        return RedirectResponse::to($target ?? '/mypage/notifications');
    }

    public function markAllNotificationsRead(Request $request, Context $context): RedirectResponse
    {
        $user = $this->authService->user();
        $this->requireNotificationService()->markAllRead(
            $context->getDomainId(),
            (int) $user['member_id']
        );
        return RedirectResponse::to('/mypage/notifications');
    }

    // =========================================================================
    // 내가 쓴 글/댓글 → Board 패키지 마이페이지 섹션으로 이관
    //   /mypage/board/articles, /mypage/board/comments
    //   (MypageSectionRegistry + Board\Subscriber\MypageSectionSubscriber)
    // =========================================================================

    // =========================================================================
    // 회원 탈퇴
    // =========================================================================

    /**
     * GET /mypage/withdraw  — 탈퇴 폼
     * POST /mypage/withdraw — 탈퇴 처리
     */
    public function withdraw(Request $request, Context $context): ViewResponse|JsonResponse|RedirectResponse
    {
        if ($request->getMethod() === 'POST') {
            return $this->processWithdraw($request, $context);
        }

        return $this->mypageView('Withdraw', 'withdraw', $context);
    }

    private function processWithdraw(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        $user     = $this->authService->user();
        // 마이페이지 뷰는 평면 필드로 전송한다(updateProfile 과 동일 계약).
        $password = (string) $request->post('password', '');
        $reason   = trim((string) $request->post('reason', ''));

        if (empty($password)) {
            if ($request->isAjax()) {
                return JsonResponse::error('비밀번호를 입력해주세요.');
            }
            return RedirectResponse::back();
        }

        $result = $this->memberService->withdraw($user['member_id'], $password, $reason);

        if ($request->isAjax()) {
            if ($result->isSuccess()) {
                $this->authService->logout();
                return JsonResponse::success(['redirect' => '/'], $result->getMessage());
            }
            return JsonResponse::error($result->getMessage());
        }

        if ($result->isSuccess()) {
            $this->authService->logout();
            return RedirectResponse::to('/');
        }

        return RedirectResponse::back();
    }

    // =========================================================================
    // 파일 업로드 (프로필 추가 필드용)
    // =========================================================================

    /**
     * POST /member/upload-field-file
     */
    public function uploadFieldFile(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $fieldId  = (int) ($request->post('field_id') ?? 0);

        if ($fieldId <= 0 || !$this->fieldService || !$this->fileHandler) {
            return JsonResponse::error('파일 업로드를 처리할 수 없습니다.');
        }

        // 도메인 스코프를 넘겨 타 도메인 field_id 조회(IDOR)를 차단한다.
        $field = $this->fieldService->getField($fieldId, $domainId);
        if (!$field || !CustomFieldValidator::isFileType($field['field_type'])) {
            return JsonResponse::error('유효하지 않은 필드입니다.');
        }

        $file = UploadedFile::fromGlobal('file');
        if (!$file || !$file->isValid()) {
            return JsonResponse::error($file ? $file->getErrorMessage() : '파일이 업로드되지 않았습니다.');
        }

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
    // 공통 헬퍼
    // =========================================================================

    /**
     * 사이드바 메뉴 목록 빌드 (MypageMenuBuilder 위임)
     */
    private function buildMenus(string $currentUrl, Context $context): array
    {
        return $this->menuBuilder->buildMenus($currentUrl, $context->getDomainId());
    }

    private function requireNotificationService(): MemberNotificationService
    {
        if ($this->notificationService === null) {
            throw new \RuntimeException('회원 알림 서비스를 사용할 수 없습니다.');
        }
        return $this->notificationService;
    }

    /**
     * Mypage ViewResponse 생성 헬퍼
     * ($user는 _layout.php의 사이드바 사용자 정보용으로 항상 포함)
     *
     * 코어 마이페이지 페이지는 모두 /mypage/{section} URL이므로 active 판정용 전체 경로를 구성한다.
     */
    private function mypageView(string $view, string $section, Context $context, array $data = []): ViewResponse
    {
        return ViewResponse::view("mypage/{$view}")
            ->withData(array_merge([
                'user'           => $this->authService->user(),
                'mypageMenus'    => $this->buildMenus('/mypage/' . $section, $context),
                'currentSection' => $section,
            ], $data));
    }
}
