<?php
declare(strict_types=1);
/**
 * src/Controller/Admin/MemberLevelsController.php
 *
 * 관리자 회원 등급 관리 컨트롤러
 *
 * URL: /admin/member-levels
 *
 * Note: 등급은 전역 테이블로, 슈퍼관리자만 접근 가능합니다.
 */

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Service\Auth\AuthService;
use Mublo\Helper\Form\FormHelper;
use Mublo\Entity\Member\MemberLevel;

class MemberLevelsController
{
    private MemberLevelService $levelService;
    private AuthService $authService;

    public function __construct(
        MemberLevelService $levelService,
        AuthService $authService
    ) {
        $this->levelService = $levelService;
        $this->authService = $authService;
    }

    // =========================================================================
    // 목록
    // =========================================================================

    /**
     * 등급 목록
     *
     * GET /admin/member-levels
     */
    public function index(array $params, Context $context): ViewResponse|JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return $this->accessDeniedResponse($context);
        }

        $request = $context->getRequest();

        // 페이징 파라미터
        $page = (int) ($request->get('page') ?? 1);
        $defaultPerPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);
        $perPage = (int) ($request->get('per_page') ?? $defaultPerPage);

        // 필터 파라미터
        $levelTypeFilter = $request->get('level_type') ?? '';

        // 필터 조건
        $filters = [];
        if ($levelTypeFilter) {
            $filters['level_type'] = $levelTypeFilter;
        }

        // 등급 목록 조회
        $result = $this->levelService->getPaginatedList($page, $perPage, $filters);

        // 목록 상태(필터/페이지) + activeCode 쿼리스트링 — 수정 후 목록 복귀 시 유지용
        $listQuery = http_build_query(array_filter([
            'activeCode' => $request->get('activeCode') ?: '003_003',
            'level_type' => $levelTypeFilter,
            'page'       => $page > 1 ? $page : '',
        ], fn($v) => $v !== '' && $v !== null));

        return ViewResponse::view('member-levels/index')
            ->withData([
                'pageTitle' => '회원 등급 관리',
                'levels' => $result['data'],
                'listQuery' => $listQuery, // 수정 링크에 붙일 복귀 쿼리
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'total_pages' => $result['total_pages'],
                ],
                'levelTypeOptions' => $this->levelService->getLevelTypeOptions(),
                'currentFilters' => [
                    'level_type' => $levelTypeFilter,
                ],
                'activeCode' => '003_003', // 회원관리 > 회원 등급 관리
            ]);
    }

    // =========================================================================
    // 생성
    // =========================================================================

    /**
     * 등급 생성 폼
     *
     * GET /admin/member-levels/create
     */
    public function create(array $params, Context $context): ViewResponse|JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return $this->accessDeniedResponse($context);
        }

        return ViewResponse::view('member-levels/form')
            ->withData([
                'pageTitle' => '등급 등록',
                'isEdit' => false,
                'level' => null,
                'levelTypeOptions' => $this->levelService->getLevelTypeOptions(),
                'listUrl' => '/admin/member-levels?activeCode=003_003', // 취소 시 목록 복귀(activeCode 유지)
                'activeCode' => '003_003',
            ]);
    }

    /**
     * 등급 생성 처리
     *
     * POST /admin/member-levels/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $request = $context->getRequest();

        // FormData로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $result = $this->levelService->create($data);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/member-levels?activeCode=003_003'], // 등록 후 목록 복귀(activeCode 유지, 필터 미유지)
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 수정
    // =========================================================================

    /**
     * 등급 수정 폼
     *
     * GET /admin/member-levels/edit/{id}
     */
    public function edit(array $params, Context $context): ViewResponse|JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return $this->accessDeniedResponse($context);
        }

        $levelId = (int) ($params[0] ?? 0);

        if ($levelId === 0) {
            $levelId = (int) ($context->getRequest()->get('id') ?? 0);
        }

        $level = $this->levelService->findById($levelId);

        if (!$level) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '등급을 찾을 수 없습니다.']);
        }

        // 해당 레벨을 사용하는 회원 수
        $memberCount = $this->levelService->countMembersUsingLevel($level->getLevelValue());

        // 목록 복귀 URL (open-redirect 방지: /admin/member-levels 한정)
        $return = (string) ($context->getRequest()->get('return') ?? '');
        $listUrl = ($return === '/admin/member-levels' || str_starts_with($return, '/admin/member-levels?'))
            ? $return
            : '/admin/member-levels?activeCode=003_003';

        return ViewResponse::view('member-levels/form')
            ->withData([
                'pageTitle' => '등급 수정',
                'isEdit' => true,
                'level' => $level,
                'levelTypeOptions' => $this->levelService->getLevelTypeOptions(),
                'memberCount' => $memberCount,
                'listUrl' => $listUrl, // 목록/저장 후 복귀 URL (필터·activeCode 유지)
                'activeCode' => '003_003',
            ]);
    }

    /**
     * 등급 수정 처리
     *
     * POST /admin/member-levels/update/{id}
     */
    public function update(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $levelId = (int) ($params[0] ?? 0);
        $request = $context->getRequest();

        // FormData로 전송된 데이터 정제
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $result = $this->levelService->update($levelId, $data);

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
     * 등급 삭제
     *
     * DELETE /admin/member-levels/delete/{id}
     * POST /admin/member-levels/delete/{id}
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $levelId = (int) ($params[0] ?? 0);

        $result = $this->levelService->delete($levelId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 목록 일괄 수정/삭제
    // =========================================================================

    /**
     * 목록에서 선택한 등급의 권한 일괄 수정
     *
     * POST /admin/member-levels/list-modify
     */
    public function listModify(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $request = $context->getRequest();

        // 선택된 등급 ID 목록
        $checkedIds = $request->input('chk') ?? [];

        if (empty($checkedIds)) {
            return JsonResponse::error('수정할 등급을 선택해주세요.');
        }

        // 권한 필드 목록
        $permissionFields = [
            'is_admin',
            'can_operate_domain',
        ];

        // 각 필드별 데이터 수집
        $fieldsData = [];
        foreach ($permissionFields as $field) {
            $fieldsData[$field] = $request->input($field) ?? [];
        }

        $updated = 0;
        $failed = 0;

        foreach ($checkedIds as $levelId) {
            $levelId = (int) $levelId;

            // 등급 조회
            $level = $this->levelService->findById($levelId);
            if (!$level || $level->isSuper()) {
                $failed++;
                continue;
            }

            // 해당 등급의 권한 데이터 수집
            $updateData = [];
            foreach ($permissionFields as $field) {
                // 체크박스가 체크되면 is_admin[level_id] = "1", 체크 해제되면 키 자체가 없음
                $updateData[$field] = isset($fieldsData[$field][$levelId]);
            }

            $result = $this->levelService->updatePermissions($levelId, $updateData);
            if ($result->isSuccess()) {
                $updated++;
            } else {
                $failed++;
            }
        }

        if ($updated > 0) {
            $message = "{$updated}개 등급이 수정되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개 실패)";
            }
            return JsonResponse::success(['updated' => $updated, 'failed' => $failed], $message);
        }

        return JsonResponse::error('수정된 등급이 없습니다.');
    }

    /**
     * 목록에서 선택한 등급 일괄 삭제
     *
     * POST /admin/member-levels/list-delete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $request = $context->getRequest();
        $checkedIds = $request->input('chk') ?? [];

        if (empty($checkedIds)) {
            return JsonResponse::error('삭제할 등급을 선택해주세요.');
        }

        $deleted = 0;
        $failed = 0;

        foreach ($checkedIds as $levelId) {
            $levelId = (int) $levelId;

            $result = $this->levelService->delete($levelId);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 등급이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개 실패)";
            }
            return JsonResponse::success(['deleted' => $deleted, 'failed' => $failed], $message);
        }

        return JsonResponse::error('삭제할 수 있는 등급이 없습니다.');
    }

    // =========================================================================
    // AJAX: 인라인 권한 수정 (deprecated - listModify 사용 권장)
    // =========================================================================

    /**
     * 인라인 권한 수정 (목록에서 체크박스 토글)
     *
     * POST /admin/member-levels/quick-edit/{id}
     *
     * @param array $params [0] => level_id
     * @param Context $context
     * @return JsonResponse
     */
    public function quickEdit(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $levelId = (int) ($params[0] ?? 0);
        $request = $context->getRequest();

        // JSON body에서 필드와 값 추출
        $field = $request->json('field') ?? '';
        $value = $request->json('value');

        // 허용된 필드만 수정 가능
        $allowedFields = [
            'is_admin',
            'can_operate_domain',
        ];

        if (!in_array($field, $allowedFields, true)) {
            return JsonResponse::error('수정할 수 없는 필드입니다.');
        }

        // 현재 등급 조회
        $level = $this->levelService->findById($levelId);
        if (!$level) {
            return JsonResponse::error('등급을 찾을 수 없습니다.');
        }

        // 최고관리자 등급은 권한 수정 불가 (is_super=true인 경우)
        if ($level->isSuper()) {
            return JsonResponse::error('최고관리자 등급의 권한은 수정할 수 없습니다.');
        }

        // 해당 필드만 업데이트
        $result = $this->levelService->updateField($levelId, $field, (bool) $value);

        if ($result->isSuccess()) {
            return JsonResponse::success([
                'level_id' => $levelId,
                'field' => $field,
                'value' => (bool) $value,
            ], $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // AJAX: 일괄 권한 수정
    // =========================================================================

    /**
     * 일괄 권한 수정
     *
     * POST /admin/member-levels/bulk-edit
     */
    public function bulkEdit(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $request = $context->getRequest();

        $levelIds = $request->json('level_ids') ?? [];
        $permissions = $request->json('permissions') ?? [];

        if (empty($levelIds)) {
            return JsonResponse::error('수정할 등급을 선택해주세요.');
        }

        if (empty($permissions)) {
            return JsonResponse::error('수정할 권한을 선택해주세요.');
        }

        $result = $this->levelService->bulkUpdatePermissions($levelIds, $permissions);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // AJAX: 레벨값 중복 확인
    // =========================================================================

    /**
     * 레벨값 중복 확인
     *
     * POST /admin/member-levels/check-value
     */
    public function checkValue(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $request = $context->getRequest();
        $levelValue = (int) ($request->input('level_value') ?? $request->json('level_value') ?? 0);
        $excludeId = $request->input('exclude_id') ?? $request->json('exclude_id') ?? null;

        if ($levelValue < 1 || $levelValue > 255) {
            return JsonResponse::error('레벨값은 1~255 사이여야 합니다.');
        }

        $level = $this->levelService->findByValue($levelValue);

        if ($level === null) {
            return JsonResponse::success(null, '사용 가능한 레벨값입니다.');
        }

        // 수정 모드에서 자기 자신은 제외
        if ($excludeId !== null && $level->getLevelId() === (int) $excludeId) {
            return JsonResponse::success(null, '사용 가능한 레벨값입니다.');
        }

        return JsonResponse::error('이미 사용 중인 레벨값입니다.');
    }

    // =========================================================================
    // Helper 메서드
    // =========================================================================

    /**
     * 슈퍼관리자 권한 체크
     */
    private function isSuperAdmin(Context $context): bool
    {
        return $this->authService->isSuper();
    }

    /**
     * 접근 거부 응답
     */
    private function accessDeniedResponse(Context $context): ViewResponse|JsonResponse
    {
        $request = $context->getRequest();

        if ($request->isAjax() || $request->isJson()) {
            return JsonResponse::forbidden('접근 권한이 없습니다. 최고관리자만 접근할 수 있습니다.');
        }

        return ViewResponse::view('Error/403')
            ->withData(['message' => '접근 권한이 없습니다. 최고관리자만 접근할 수 있습니다.']);
    }

    /**
     * 등급 폼 데이터 스키마
     *
     * FormHelper::normalizeFormData()에서 사용
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['level_value'],
            'bool' => [
                'is_super',
                'is_admin',
                'can_operate_domain',
            ],
            'required_string' => ['level_name'],
            'enum' => [
                'level_type' => [
                    'values' => array_keys(MemberLevel::LEVEL_TYPES),
                    'default' => MemberLevel::TYPE_BASIC,
                ],
            ],
        ];
    }
}
