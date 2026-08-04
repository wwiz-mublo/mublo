<?php
declare(strict_types=1);
/**
 * src/Controller/Admin/PolicyController.php
 *
 * 관리자 정책/약관 관리 컨트롤러
 *
 * URL: /admin/policy
 */

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Member\PolicyService;
use Mublo\Service\Auth\AuthService;
use Mublo\Helper\Form\FormHelper;
use Mublo\Entity\Member\Policy;
use Mublo\Enum\Policy\PolicyType;

class PolicyController
{
    private PolicyService $policyService;
    private AuthService $authService;

    public function __construct(
        PolicyService $policyService,
        AuthService $authService
    ) {
        $this->policyService = $policyService;
        $this->authService = $authService;
    }

    // =========================================================================
    // 목록
    // =========================================================================

    /**
     * 정책 목록
     *
     * GET /admin/policy
     */
    public function index(array $params, Context $context): ViewResponse|JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId();

        // 필터 파라미터
        $filters = [
            'policy_type' => $request->get('policy_type') ?? '',
            'is_active' => $request->get('is_active') ?? '',
            'keyword' => $request->get('keyword') ?? '',
        ];

        // 빈 값 필터 제거
        $filters = array_filter($filters, fn($v) => $v !== '');
        $hasFilter = !empty($filters);

        // 페이징 없음 — 정책은 소수이고 순서(드래그)가 전체 기준이라 전체를 한 화면에 노출
        $result = $this->policyService->getPaginatedList($domainId, 1, 1000, $filters);

        // 목록 상태(필터) + activeCode 쿼리스트링 — 수정 후 목록 복귀 시 유지용
        $listQuery = http_build_query(array_filter([
            'activeCode'  => $request->get('activeCode') ?: '003_006',
            'policy_type' => $request->get('policy_type') ?? '',
            'is_active'   => $request->get('is_active') ?? '',
            'keyword'     => $request->get('keyword') ?? '',
        ], fn($v) => $v !== '' && $v !== null));

        return ViewResponse::view('policy/index')
            ->withData([
                'pageTitle' => '약관/정책 관리',
                'policies' => $result['data'],
                'listQuery' => $listQuery, // 수정 링크에 붙일 복귀 쿼리
                'totalItems' => $result['total'], // "N건" 표시용
                'hasFilter' => $hasFilter, // 필터/검색 중이면 드래그 정렬 비활성
                'policyTypeOptions' => $this->policyService->getPolicyTypeOptions(),
                'currentFilters' => [
                    'policy_type' => $request->get('policy_type') ?? '',
                    'is_active' => $request->get('is_active') ?? '',
                    'keyword' => $request->get('keyword') ?? '',
                ],
                'activeCode' => '003_006', // 회원관리 > 약관/정책
            ]);
    }

    // =========================================================================
    // 생성
    // =========================================================================

    /**
     * 정책 생성 폼
     *
     * GET /admin/policy/create
     */
    public function create(array $params, Context $context): ViewResponse|JsonResponse
    {
        return ViewResponse::view('policy/form')
            ->withData([
                'pageTitle' => '정책 등록',
                'isEdit' => false,
                'policy' => null,
                'policyTypeOptions' => $this->policyService->getPolicyTypeOptions(),
                'listUrl' => '/admin/policy?activeCode=003_006', // 취소 시 목록 복귀(activeCode 유지)
                'activeCode' => '003_006',
            ]);
    }

    /**
     * 정책 생성 처리
     *
     * POST /admin/policy/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId();

        // FormData로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $result = $this->policyService->create($domainId, $data);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/policy?activeCode=003_006'], // 등록 후 목록 복귀(activeCode 유지, 필터 미유지)
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 수정
    // =========================================================================

    /**
     * 정책 수정 폼
     *
     * GET /admin/policy/edit/{id}
     */
    public function edit(array $params, Context $context): ViewResponse|JsonResponse
    {
        $policyId = (int) ($params[0] ?? 0);

        if ($policyId === 0) {
            $policyId = (int) ($context->getRequest()->get('id') ?? 0);
        }

        $policy = $this->policyService->findById($policyId);

        if (!$policy) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '정책을 찾을 수 없습니다.']);
        }

        // 도메인 체크
        if ($policy->getDomainId() !== $context->getDomainId()) {
            return ViewResponse::view('Error/403')
                ->withData(['message' => '접근 권한이 없습니다.']);
        }

        // 목록 복귀 URL (open-redirect 방지: /admin/policy 한정)
        $return = (string) ($context->getRequest()->get('return') ?? '');
        $listUrl = ($return === '/admin/policy' || str_starts_with($return, '/admin/policy?'))
            ? $return
            : '/admin/policy?activeCode=003_006';

        return ViewResponse::view('policy/form')
            ->withData([
                'pageTitle' => '정책 수정',
                'isEdit' => true,
                'policy' => $policy,
                'policyTypeOptions' => $this->policyService->getPolicyTypeOptions(),
                'listUrl' => $listUrl, // 목록/저장 후 복귀 URL (필터·activeCode 유지)
                'activeCode' => '003_006',
            ]);
    }

    /**
     * 정책 수정 처리
     *
     * POST /admin/policy/update/{id}
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $policyId = (int) ($params[0] ?? 0);
        $request = $context->getRequest();

        // 정책 존재 및 도메인 체크
        $policy = $this->policyService->findById($policyId);
        if (!$policy || $policy->getDomainId() !== $context->getDomainId()) {
            return JsonResponse::notFound('정책을 찾을 수 없거나 접근 권한이 없습니다.');
        }

        // FormData로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $result = $this->policyService->update($policyId, $data);

        if ($result->isSuccess()) {
            // redirect는 클라이언트가 목록 복귀 URL(listUrl, 필터·activeCode 유지)로 처리
            return JsonResponse::success([], $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 정책 삭제
     *
     * DELETE /admin/policy/delete/{id}
     * POST /admin/policy/delete/{id}
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $policyId = (int) ($params[0] ?? 0);

        // 정책 존재 및 도메인 체크
        $policy = $this->policyService->findById($policyId);
        if (!$policy || $policy->getDomainId() !== $context->getDomainId()) {
            return JsonResponse::notFound('정책을 찾을 수 없거나 접근 권한이 없습니다.');
        }

        $result = $this->policyService->delete($policyId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // AJAX: 인라인 수정
    // =========================================================================

    /**
     * 인라인 필드 수정 (목록에서 체크박스 토글)
     *
     * POST /admin/policy/quick-edit/{id}
     */
    public function quickEdit(array $params, Context $context): JsonResponse
    {
        $policyId = (int) ($params[0] ?? 0);
        $request = $context->getRequest();

        // 정책 존재 및 도메인 체크
        $policy = $this->policyService->findById($policyId);
        if (!$policy || $policy->getDomainId() !== $context->getDomainId()) {
            return JsonResponse::notFound('정책을 찾을 수 없거나 접근 권한이 없습니다.');
        }

        // JSON body에서 필드와 값 추출
        $field = $request->json('field') ?? '';
        $value = $request->json('value');

        $result = $this->policyService->updateField($policyId, $field, $value);

        if ($result->isSuccess()) {
            return JsonResponse::success([
                'policy_id' => $policyId,
                'field' => $field,
                'value' => $value,
            ], $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // AJAX: 정렬 순서 저장
    // =========================================================================

    /**
     * 정렬 순서 일괄 저장
     *
     * POST /admin/policy/sort
     */
    public function sort(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $orderData = $request->json('order') ?? [];

        if (empty($orderData)) {
            return JsonResponse::error('정렬 데이터가 없습니다.');
        }

        // 도메인 체크 (모든 정책이 현재 도메인 소속인지)
        $domainId = $context->getDomainId();
        foreach (array_keys($orderData) as $policyId) {
            $policy = $this->policyService->findById((int) $policyId);
            if (!$policy || $policy->getDomainId() !== $domainId) {
                return JsonResponse::error('접근 권한이 없는 정책이 포함되어 있습니다.');
            }
        }

        $result = $this->policyService->updateSortOrders($orderData);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // AJAX: 슬러그 중복 확인
    // =========================================================================

    /**
     * 슬러그 중복 확인
     *
     * POST /admin/policy/check-slug
     */
    public function checkSlug(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId();

        $slug = $request->input('slug') ?? $request->json('slug') ?? '';
        $excludeId = $request->input('exclude_id') ?? $request->json('exclude_id') ?? null;

        if (empty($slug)) {
            return JsonResponse::error('슬러그를 입력해주세요.');
        }

        // 슬러그 형식 검증
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return JsonResponse::error('슬러그는 영문 소문자, 숫자, 하이픈(-)만 사용 가능합니다.');
        }

        $exists = $excludeId
            ? $this->policyService->findBySlug($domainId, $slug)?->getPolicyId() !== (int) $excludeId
                && $this->policyService->findBySlug($domainId, $slug) !== null
            : $this->policyService->findBySlug($domainId, $slug) !== null;

        if (!$exists) {
            return JsonResponse::success(null, '사용 가능한 슬러그입니다.');
        }

        return JsonResponse::error('이미 사용 중인 슬러그입니다.');
    }

    // =========================================================================
    // Helper 메서드
    // =========================================================================

    /**
     * 정책 폼 데이터 스키마
     *
     * FormHelper::normalizeFormData()에서 사용
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['sort_order'],
            'bool' => ['is_required', 'is_active', 'show_in_register'],
            'required_string' => ['title'],
            'html' => ['content'],  // 에디터 콘텐츠 (HTML 태그 유지)
            'enum' => [
                'policy_type' => [
                    'values' => array_keys(PolicyType::options()),
                    'default' => PolicyType::CUSTOM->value,
                ],
            ],
        ];
    }
}
