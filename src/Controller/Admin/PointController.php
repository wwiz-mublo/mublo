<?php
declare(strict_types=1);
/**
 * src/Controller/Admin/PointController.php
 *
 * 관리자 포인트(원장) 관리 컨트롤러
 *
 * URL: /admin/point (autoResolve)
 *
 * 책임:
 * - 포인트 내역 조회
 * - 포인트 수동 조정 (지급/차감)
 * - 무결성 검증
 */

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Auth\AuthService;
use Mublo\Helper\Form\FormHelper;

class PointController
{
    private BalanceManager $balanceManager;
    private MemberService $memberService;
    private AuthService $authService;

    public function __construct(
        BalanceManager $balanceManager,
        MemberService $memberService,
        AuthService $authService
    ) {
        $this->balanceManager = $balanceManager;
        $this->memberService = $memberService;
        $this->authService = $authService;
    }

    /**
     * 포인트 내역 목록
     *
     * GET /admin/point
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        // 페이징/검색 파라미터
        $page = (int) ($request->get('page') ?? 1);
        $defaultPerPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);
        $perPage = (int) ($request->get('per_page') ?? $defaultPerPage);
        $memberId = $request->get('member_id') ? (int) $request->get('member_id') : null;
        $sourceType = $request->get('source_type') ?? '';
        $startDate = $request->get('start_date') ?? '';
        $endDate = $request->get('end_date') ?? '';

        // 필터 조건
        $filters = [];
        if ($memberId) {
            $filters['member_id'] = $memberId;
        }
        if ($sourceType) {
            $filters['source_type'] = $sourceType;
        }
        if ($startDate) {
            $filters['start_date'] = $startDate;
        }
        if ($endDate) {
            $filters['end_date'] = $endDate;
        }

        // 포인트 내역 조회
        $result = $this->balanceManager->getPaginatedLogs($domainId, $page, $perPage, $filters);

        // 회원 정보 매핑 (member_id => user_id)
        $memberIds = array_unique(array_map(fn($log) => $log->getMemberId(), $result['items']));
        $memberMap = [];
        if (!empty($memberIds)) {
            $members = $this->memberService->findByIds($memberIds);
            foreach ($members as $member) {
                $memberMap[$member->getMemberId()] = $member->getUserId();
            }
        }

        // View에 전달할 items 변환
        $items = [];
        foreach ($result['items'] as $log) {
            $items[] = [
                'log_id' => $log->getLogId(),
                'member_id' => $log->getMemberId(),
                'user_id' => $memberMap[$log->getMemberId()] ?? '(삭제된 회원)',
                'amount' => $log->getAmount(),
                'balance_before' => $log->getBalanceBefore(),
                'balance_after' => $log->getBalanceAfter(),
                'source_type' => $log->getSourceType()?->value ?? '',
                'source_name' => $log->getSourceName(),
                'action' => $log->getAction(),
                'message' => $log->getMessage(),
                'admin_id' => $log->getAdminId(),
                'created_at' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return ViewResponse::view('Point/Index')
            ->withData([
                'pageTitle' => '포인트 지갑',
                'items' => $items,
                'pagination' => $result['pagination'],
                'currentFilters' => [
                    'member_id' => $memberId,
                    'source_type' => $sourceType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'sourceTypes' => [
                    'plugin' => '플러그인',
                    'package' => '패키지',
                    'admin' => '관리자',
                    'system' => '시스템',
                ],
            ]);
    }

    /**
     * 포인트 수동 조정 폼
     *
     * GET /admin/point/adjust
     */
    public function adjust(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $memberId = $request->get('member_id') ? (int) $request->get('member_id') : null;

        $member = null;
        $currentBalance = 0;

        if ($memberId) {
            $member = $this->memberService->findById($memberId);
            if ($member) {
                $domainId = $context->getDomainId() ?? 1;
                $currentBalance = $this->balanceManager->getBalance($memberId, $domainId);
            }
        }

        return ViewResponse::view('Point/Adjust')
            ->withData([
                'pageTitle' => '포인트 수동 조정',
                'member' => $member ? $member->toArray() : null,
                'currentBalance' => $currentBalance,
            ]);
    }

    /**
     * 포인트 수동 조정 처리
     *
     * POST /admin/point/adjust
     */
    public function adjustStore(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        // 현재 관리자 정보
        $admin = $this->authService->user();
        if (!$admin) {
            return JsonResponse::error('관리자 인증이 필요합니다.');
        }

        // 폼 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 폼 nonce 를 멱등키로 사용해 더블서브밋(재클릭·새로고침) 이중 지급/차감을 막는다.
        // 폼 로드 시 발급된 nonce 라 재전송은 동일 키 → 원장 UNIQUE 로 1회만 반영된다.
        $idempotencyNonce = (string) ($formData['idempotency_nonce'] ?? '');

        $memberId = (int) ($data['member_id'] ?? 0);
        $amount = (int) ($data['amount'] ?? 0);
        $adjustType = $data['adjust_type'] ?? 'add';
        $message = trim($data['message'] ?? '');
        $memo = trim($data['memo'] ?? '');

        // 유효성 검증
        if (!$memberId) {
            return JsonResponse::error('회원을 선택해주세요.');
        }
        if (!$amount || $amount <= 0) {
            return JsonResponse::error('포인트는 1 이상의 양수여야 합니다.');
        }
        if (!$message) {
            return JsonResponse::error('조정 사유를 입력해주세요.');
        }

        // 회원 존재 여부 확인
        $member = $this->memberService->findById($memberId);
        if (!$member) {
            return JsonResponse::error('회원을 찾을 수 없습니다.');
        }

        // 차감인 경우 음수로 변환
        if ($adjustType === 'subtract') {
            $amount = -$amount;
        }

        // BalanceManager를 통한 조정
        $result = $this->balanceManager->adjust([
            'domain_id' => $domainId,
            'member_id' => $memberId,
            'amount' => $amount,
            'source_type' => 'admin',
            'source_name' => 'PointController',
            'action' => 'admin_adjust',
            'message' => $message,
            'admin_id' => $admin['member_id'],
            'memo' => $memo ?: null,
            'ip_address' => $request->getClientIp(),
            'idempotency_key' => $idempotencyNonce !== ''
                ? "admin_adjust_{$domainId}_{$idempotencyNonce}"
                : null,
        ]);

        if ($result->isSuccess()) {
            $actionText = $amount > 0 ? '지급' : '차감';
            return JsonResponse::success([
                'log_id' => $result->get('log_id'),
                'balance_before' => $result->get('balance_before'),
                'balance_after' => $result->get('balance_after'),
                'redirect' => '/admin/point',
            ], "포인트가 {$actionText}되었습니다.");
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 회원 검색 API (자동완성용)
     *
     * GET /admin/point/search-member
     */
    public function searchMember(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $keyword = trim($request->get('keyword') ?? '');

        if (mb_strlen($keyword) < 2) {
            return JsonResponse::success(['members' => []], '검색어를 2자 이상 입력해주세요.');
        }

        $members = $this->memberService->searchByUserId($domainId, $keyword, 10);

        $result = [];
        foreach ($members as $member) {
            $balance = $this->balanceManager->getBalance($member->getMemberId(), $domainId);
            $result[] = [
                'member_id' => $member->getMemberId(),
                'user_id' => $member->getUserId(),
                'balance' => $balance,
            ];
        }

        return JsonResponse::success(['members' => $result]);
    }

    /**
     * 무결성 검증
     *
     * GET /admin/point/verify/{memberId}
     */
    public function verify(array $params, Context $context): JsonResponse
    {
        $memberId = (int) ($params['memberId'] ?? $params[0] ?? 0);

        if (!$memberId) {
            return JsonResponse::error('회원 ID가 필요합니다.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->balanceManager->verifyIntegrity($memberId, $domainId);

        return JsonResponse::success($result);
    }

    /**
     * 폼 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['member_id', 'amount'],
            'required_string' => ['message'],
            'enum' => [
                'adjust_type' => [
                    'values' => ['add', 'subtract'],
                    'default' => 'add',
                ],
            ],
        ];
    }
}
