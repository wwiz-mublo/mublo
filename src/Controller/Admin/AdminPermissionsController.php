<?php
declare(strict_types=1);
/**
 * src/Controller/Admin/AdminPermissionsController.php
 *
 * 관리자 접근 권한 관리 컨트롤러
 *
 * URL: /admin/admin-permissions
 *
 * Negative ACL (블랙리스트 방식):
 * - 기본: 모든 관리자(is_admin=1)는 모든 메뉴 접근 가능
 * - member_level_denied_menus에 등록된 메뉴+액션만 차단
 * - 슈퍼관리자(is_super=1)는 권한 설정 대상에서 제외 (모든 권한)
 */

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Admin\AdminPermissionService;
use Mublo\Service\Admin\AdminMenuService;
use Mublo\Service\Auth\AuthService;

class AdminPermissionsController
{
    private AdminPermissionService $permissionService;
    private AdminMenuService $menuService;
    private AuthService $authService;

    public function __construct(
        AdminPermissionService $permissionService,
        AdminMenuService $menuService,
        AuthService $authService
    ) {
        $this->permissionService = $permissionService;
        $this->menuService = $menuService;
        $this->authService = $authService;
    }

    // =========================================================================
    // 목록/메인 (2컬럼 레이아웃)
    // =========================================================================

    /**
     * 관리자 접근 권한 관리 메인
     *
     * 좌측: 등록된 권한 제한 목록
     * 우측: 권한 설정 폼
     *
     * GET /admin/admin-permissions
     */
    public function index(array $params, Context $context): ViewResponse|JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return $this->accessDeniedResponse($context);
        }

        $domainId = $context->getDomainId();
        $request = $context->getRequest();

        // 페이지네이션 파라미터
        $page = max(1, (int) ($request->get('page') ?? 1));
        $perPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);

        // 레벨 필터
        $filterLevelValue = $request->get('level_value') ?? '';

        // 등록된 권한 제한 목록 (페이지네이션 적용)
        $result = $this->permissionService->getPaginatedList($domainId, $filterLevelValue, $page, $perPage);

        // 관리자 레벨 옵션 (최고관리자 제외)
        $adminLevelOptions = $this->permissionService->getAdminLevelOptions(true);

        // 메뉴 목록 (1차 메뉴만)
        $menus = $this->menuService->getMenus();
        $topMenus = $this->extractTopMenus($menus);

        // 레벨명 매핑
        $levelNames = $adminLevelOptions;

        return ViewResponse::view('admin-permissions/index')
            ->withData([
                'pageTitle' => '관리자 접근 권한 관리',
                'permissions' => $result['items'],
                'pagination' => $result['pagination'],
                'adminLevelOptions' => $adminLevelOptions,
                'levelNames' => $levelNames,
                'topMenus' => $topMenus,
                'allMenus' => $menus,
                'filterLevelValue' => $filterLevelValue,
                'actionGroupLabels' => AdminPermissionService::getActionGroupLabels(),
                'activeCode' => '003_004', // 회원관리 > 관리자 접근 권한 관리
            ]);
    }

    // =========================================================================
    // AJAX: 서브메뉴 조회
    // =========================================================================

    /**
     * 1차 메뉴의 서브메뉴 목록 조회
     *
     * GET /admin/admin-permissions/submenus/{menuCode}
     */
    public function submenus(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $menuCode = $params[0] ?? '';

        if (empty($menuCode)) {
            return JsonResponse::error('메뉴 코드가 필요합니다.');
        }

        $domainId = $context->getDomainId();
        $request = $context->getRequest();
        $levelValue = (int) ($request->get('level_value') ?? $request->json('level_value') ?? 0);

        // 메뉴 목록에서 해당 메뉴의 서브메뉴 찾기
        $menus = $this->menuService->getMenus();
        $submenus = $this->findSubmenus($menus, $menuCode);

        // 현재 차단된 액션 정보
        $deniedMenus = [];
        if ($levelValue > 0) {
            $deniedMenus = $this->permissionService->getDeniedMenusByLevel($domainId, $levelValue);
        }

        // 서브메뉴별 차단 정보 변환
        $submenuData = [];
        foreach ($submenus as $sub) {
            $code = $sub['code'];
            $deniedActions = $deniedMenus[$code] ?? '';
            $checkedGroups = $this->permissionService->contractToActionGroups($deniedActions);

            $submenuData[] = [
                'code' => $code,
                'label' => $sub['label'],
                'url' => $sub['url'] ?? '#',
                'checkedGroups' => $checkedGroups, // ['r', 'w', 'd', 'f'] 중 체크된 것
            ];
        }

        return JsonResponse::success([
            'submenus' => $submenuData,
            'actionGroupLabels' => AdminPermissionService::getActionGroupLabels(),
        ]);
    }

    // =========================================================================
    // 저장
    // =========================================================================

    /**
     * 권한 저장
     *
     * POST /admin/admin-permissions/store
     *
     * formData 형식:
     * - formData[level_value] = 100
     * - formData[menu_code] = '003'
     * - formData[submenu][003_001][] = 'r'
     * - formData[submenu][003_001][] = 'w'
     * - formData[submenu][003_002][] = 'r'
     */
    public function store(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $domainId = $context->getDomainId();
        $request = $context->getRequest();

        // formData 추출 (JSON 또는 POST)
        $formData = $request->json('formData') ?? $request->input('formData') ?? [];

        if (empty($formData['level_value'])) {
            return JsonResponse::error('등급을 선택해주세요.');
        }

        if (empty($formData['menu_code'])) {
            return JsonResponse::error('메뉴를 선택해주세요.');
        }

        $result = $this->permissionService->saveFromForm($domainId, $formData);

        if ($result->isSuccess()) {
            // 방금 저장한 등급으로 필터 유지 + activeCode 유지
            $levelValue = (int) $formData['level_value'];
            return JsonResponse::success(
                ['redirect' => '/admin/admin-permissions?level_value=' . $levelValue . '&activeCode=003_004'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 권한 삭제 (단일)
     *
     * DELETE /admin/admin-permissions/delete/{id}
     * POST /admin/admin-permissions/delete/{id}
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $id = (int) ($params[0] ?? 0);

        if ($id === 0) {
            return JsonResponse::error('삭제할 권한을 선택해주세요.');
        }

        $result = $this->permissionService->delete($id);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 권한 일괄 삭제
     *
     * POST /admin/admin-permissions/bulk-delete
     */
    public function bulkDelete(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $request = $context->getRequest();
        // Mublo-submit-form은 chk[] 배열로 전송, AJAX는 ids로 전송
        $ids = $request->input('chk') ?? $request->input('ids') ?? $request->json('ids') ?? [];

        if (empty($ids)) {
            return JsonResponse::error('삭제할 권한을 선택해주세요.');
        }

        // 정수 배열로 변환
        $ids = array_map('intval', (array) $ids);

        $result = $this->permissionService->deleteBulk($ids);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 레벨별 전체 삭제
     *
     * POST /admin/admin-permissions/level-delete/{levelValue}
     */
    public function levelDelete(array $params, Context $context): JsonResponse
    {
        // 슈퍼관리자 권한 체크
        if (!$this->isSuperAdmin($context)) {
            return JsonResponse::forbidden('접근 권한이 없습니다.');
        }

        $domainId = $context->getDomainId();
        $levelValue = (int) ($params[0] ?? 0);

        if ($levelValue === 0) {
            return JsonResponse::error('등급을 선택해주세요.');
        }

        $result = $this->permissionService->deleteByLevel($domainId, $levelValue);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
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
     * 메뉴에서 1차 메뉴만 추출
     *
     * @param array $menus 전체 메뉴 (AdminMenuService->getMenus() 결과)
     * @return array [['code' => '003', 'label' => '회원 관리', 'icon' => 'bi-people'], ...]
     */
    private function extractTopMenus(array $menus): array
    {
        $topMenus = [];

        foreach ($menus as $groupKey => $group) {
            foreach ($group['items'] ?? [] as $item) {
                // 서브메뉴가 있는 메뉴만 (서브메뉴가 없으면 권한 설정 의미 없음)
                if (!empty($item['submenu'])) {
                    // 플러그인/패키지 루트 메뉴는 코드가 비어있으므로 source:sourceName 형식으로 식별
                    $menuIdentifier = $item['code'];
                    if (empty($menuIdentifier) && !empty($item['source']) && $item['source'] !== 'core') {
                        $menuIdentifier = $item['source'] . ':' . ($item['sourceName'] ?? '');
                    }

                    $topMenus[] = [
                        'code' => $menuIdentifier,
                        'label' => $item['label'],
                        'icon' => $item['icon'] ?? '',
                        'group' => $group['label'] ?? $groupKey,
                        'source' => $item['source'] ?? 'core',
                        'sourceName' => $item['sourceName'] ?? '',
                    ];
                }
            }
        }

        return $topMenus;
    }

    /**
     * 특정 메뉴 코드의 서브메뉴 찾기
     *
     * 코드 형식:
     * - Core 메뉴: '003' (숫자 코드)
     * - Plugin/Package 메뉴: 'plugin:MemberPoint' 또는 'package:Shop'
     *
     * @param array $menus 전체 메뉴
     * @param string $menuCode 1차 메뉴 코드 또는 source:sourceName 식별자
     * @return array 서브메뉴 배열
     */
    private function findSubmenus(array $menus, string $menuCode): array
    {
        // source:sourceName 형식인지 확인 (plugin:MemberPoint, package:Shop)
        $isSourceFormat = str_contains($menuCode, ':') &&
            (str_starts_with($menuCode, 'plugin:') || str_starts_with($menuCode, 'package:'));

        foreach ($menus as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if ($isSourceFormat) {
                    // source:sourceName 형식으로 매칭
                    $itemIdentifier = ($item['source'] ?? 'core') . ':' . ($item['sourceName'] ?? '');
                    if ($itemIdentifier === $menuCode) {
                        return $item['submenu'] ?? [];
                    }
                } else {
                    // 일반 코드로 매칭
                    if (($item['code'] ?? '') === $menuCode) {
                        return $item['submenu'] ?? [];
                    }
                }
            }
        }

        return [];
    }
}
