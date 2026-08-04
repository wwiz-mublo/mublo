<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Service\Extension\ExtensionService;

/**
 * Admin MenuController
 *
 * 메뉴 관리 컨트롤러
 * - 메뉴 아이템 CRUD
 * - 메뉴 트리 구성
 * - 유틸리티/푸터 메뉴 관리
 *
 * 자동 라우팅:
 * - GET  /admin/menu               → index (탭 기반 메인)
 * - GET  /admin/menu/item-view     → itemView (아이템 조회)
 * - POST /admin/menu/item-store    → itemStore (아이템 저장)
 * - POST /admin/menu/item-delete   → itemDelete (아이템 삭제)
 * - POST /admin/menu/tree-update   → treeUpdate
 * - POST /admin/menu/utility-update → utilityUpdate
 * - POST /admin/menu/footer-update  → footerUpdate
 * - POST /admin/menu/mypage-update  → mypageUpdate
 */
class MenuController
{
    private MenuService $menuService;
    private MemberLevelService $levelService;
    private ExtensionService $extensionService;

    public function __construct(
        MenuService $menuService,
        MemberLevelService $levelService,
        ExtensionService $extensionService
    ) {
        $this->menuService = $menuService;
        $this->levelService = $levelService;
        $this->extensionService = $extensionService;
    }

    /**
     * 메뉴 관리 메인 (탭 기반)
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $tab = $request->query('tab', 'items');

        // 페이징/검색 파라미터
        $page = (int) ($request->get('page') ?? 1);
        $defaultPerPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);
        $perPage = (int) ($request->get('per_page') ?? $defaultPerPage);
        $searchField = $request->get('search_field') ?? '';
        $searchKeyword = $request->get('search_keyword') ?? '';

        // 제공자 필터: "plugin", "plugin:Mshop" 형식
        $filterRaw = $request->get('provider_filter') ?? '';
        $filterProviderType = '';
        $filterProviderName = '';
        if ($filterRaw !== '') {
            if (str_contains($filterRaw, ':')) {
                [$filterProviderType, $filterProviderName] = explode(':', $filterRaw, 2);
            } else {
                $filterProviderType = $filterRaw;
            }
        }

        // 검색 조건 구성
        $search = [];
        if ($searchKeyword) {
            $search['keyword'] = $searchKeyword;
            $search['field'] = $searchField;
        }
        if ($filterProviderType) {
            $search['provider_type'] = $filterProviderType;
        }
        if ($filterProviderName) {
            $search['provider_name'] = $filterProviderName;
        }

        // 정렬 (기본 item_id DESC)
        $sort = $request->get('sort') ?? '';
        $order = $request->get('order') ?? '';

        // 메뉴 아이템 목록 (검색/정렬/페이징 적용)
        $itemsData = $this->menuService->getItemsPaginated($domainId, $page, $perPage, $search, $sort, $order);
        $items = $itemsData['items'];
        $pagination = $itemsData['pagination'];

        // 현재 정렬 상태 (헤더 표시/링크 생성용)
        [$sortField, $sortOrder] = $this->menuService->normalizeSort($sort, $order);

        // 검색 필드 옵션
        $searchFields = $this->menuService->getSearchFields();

        // 현재 검색 조건
        $currentSearch = [
            'field' => $searchField,
            'keyword' => $searchKeyword,
        ];

        // 메뉴 트리용 전체 아이템 (활성만)
        $allActiveItems = $this->menuService->getItems($domainId, true);

        // 메뉴 트리 (계층형)
        $tree = $this->menuService->getTreeHierarchy($domainId, false);

        // 유틸리티/푸터/마이페이지 메뉴
        $utilityMenus = $this->menuService->getUtilityMenus($domainId);
        $footerMenus = $this->menuService->getFooterMenus($domainId);
        $mypageMenus = $this->menuService->getMypageMenus($domainId);

        return ViewResponse::view('menu/index')
            ->withData([
                'pageTitle' => '메뉴 관리',
                'activeTab' => $tab,
                'items' => $items,
                'allActiveItems' => $allActiveItems,
                'pagination' => $pagination,
                'searchFields' => $searchFields,
                'currentSearch' => $currentSearch,
                'currentSort' => [
                    'field' => $sortField,
                    'order' => $sortOrder,
                ],
                'tree' => $tree,
                'flatTree' => $this->menuService->getTree($domainId, false),
                'utilityMenus' => $utilityMenus,
                'footerMenus' => $footerMenus,
                'mypageMenus' => $mypageMenus,
                'filterRaw' => $filterRaw,
                'providerOptions' => $this->menuService->getProviderOptions($domainId),
                'enabledPlugins' => $this->extensionService->getEnabledPlugins($domainId),
                'enabledPackages' => $this->extensionService->getEnabledPackages($domainId),
                'levelOptions' => $this->levelService->getOptionsForSelect(),
                'targetOptions' => $this->menuService->getTargetOptions(),
            ]);
    }

    /**
     * 메뉴 아이템 저장 (생성/수정)
     *
     * POST /admin/menu/item-store
     */
    public function itemStore(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $itemId = (int) $request->json('item_id', 0);
        $providerType = $request->json('provider_type', 'core');
        $allowedProviderTypes = ['core', 'plugin', 'package'];
        if (!in_array($providerType, $allowedProviderTypes, true)) {
            $providerType = 'core';
        }

        $data = [
            'label' => $request->json('label', ''),
            'url' => $request->json('url', ''),
            'target' => $request->json('target', '_self'),
            'visibility' => $request->json('visibility', 'all'),
            'pair_code' => $request->json('pair_code', '') ?: null,
            'min_level' => $request->json('min_level', 0),
            // 레이아웃 오버라이드: 빈 문자열/미전송 = 상속(NULL). 서비스가 ''→NULL 로 정규화한다.
            'layout_type' => $request->json('layout_type', null),
            'show_on_pc' => $request->json('show_on_pc', 1),
            'show_on_mobile' => $request->json('show_on_mobile', 1),
            'show_in_utility' => $request->json('show_in_utility', 0),
            'show_in_footer' => $request->json('show_in_footer', 0),
            'is_active' => $request->json('is_active', 1),
            'provider_type' => $providerType,
            'provider_name' => $providerType !== 'core' ? ($request->json('provider_name', '') ?: null) : null,
        ];

        // 빈 문자열을 null로 변환
        foreach (['url'] as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        if ($itemId > 0) {
            $result = $this->menuService->updateItem($itemId, $data, $domainId);
        } else {
            $result = $this->menuService->createItem($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 메뉴 아이템 삭제
     *
     * POST /admin/menu/item-delete
     */
    public function itemDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $itemId = (int) $request->json('item_id', 0);

        if ($itemId <= 0) {
            return JsonResponse::error('메뉴 ID가 필요합니다.');
        }

        $result = $this->menuService->deleteItem($itemId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 메뉴 아이템 단건 조회 (AJAX)
     *
     * GET /admin/menu/item-view?item_id={id}
     */
    public function itemView(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $itemId = (int) $request->query('item_id', 0);

        if ($itemId <= 0) {
            return JsonResponse::error('메뉴 ID가 필요합니다.');
        }

        $item = $this->menuService->getItem($itemId, $domainId);

        if (!$item) {
            return JsonResponse::error('메뉴를 찾을 수 없습니다.');
        }

        return JsonResponse::success($item->toArray());
    }

    /**
     * 메뉴 트리 저장
     */
    public function treeUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $treeData = $request->json('tree', []);

        if (!is_array($treeData)) {
            return JsonResponse::error('잘못된 트리 데이터입니다.');
        }

        $result = $this->menuService->saveTree($domainId, $treeData);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 트리에 메뉴 추가
     */
    public function treeAdd(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $menuCode = $request->json('menu_code', '');
        $parentCode = $request->json('parent_code', null);

        if (empty($menuCode)) {
            return JsonResponse::error('메뉴 코드가 필요합니다.');
        }

        $result = $this->menuService->addToTree($domainId, $menuCode, $parentCode);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 트리에서 메뉴 제거
     */
    public function treeRemove(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $nodeId = (int) $request->json('node_id', 0);

        if ($nodeId <= 0) {
            return JsonResponse::error('노드 ID가 필요합니다.');
        }

        $result = $this->menuService->removeFromTree($nodeId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 유틸리티 메뉴 순서 저장
     */
    public function utilityUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $itemIds = $request->json('item_ids', []);

        if (!is_array($itemIds)) {
            return JsonResponse::error('잘못된 데이터입니다.');
        }

        $result = $this->menuService->saveUtilityOrder($domainId, $itemIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 푸터 메뉴 순서 저장
     */
    public function footerUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $itemIds = $request->json('item_ids', []);

        if (!is_array($itemIds)) {
            return JsonResponse::error('잘못된 데이터입니다.');
        }

        $result = $this->menuService->saveFooterOrder($domainId, $itemIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 마이페이지 메뉴 순서 저장
     */
    public function mypageUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $itemIds = $request->json('item_ids', []);

        if (!is_array($itemIds)) {
            return JsonResponse::error('잘못된 데이터입니다.');
        }

        $result = $this->menuService->saveMypageOrder($domainId, $itemIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 메뉴 아이템 일괄 수정
     *
     * POST /admin/menu/list-modify
     *
     * 목록에서 select box로 변경된 값들을 일괄 저장
     */
    public function listModify(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 폼에서 전송된 데이터: chk[] = [item_id, ...], visibility[item_id] = value, ...
        $checkedIds = $request->input('chk') ?? [];

        if (empty($checkedIds)) {
            return JsonResponse::error('수정할 항목을 선택해주세요.');
        }

        // 각 필드별 데이터 수집
        $minLevelData = $request->input('min_level') ?? [];
        $showOnPcData = $request->input('show_on_pc') ?? [];
        $showOnMobileData = $request->input('show_on_mobile') ?? [];
        $isActiveData = $request->input('is_active') ?? [];
        $layoutTypeData = $request->input('layout_type') ?? [];

        // 일괄 수정 실행 (도메인 소유권 경계 강제)
        $result = $this->menuService->bulkUpdateItems($domainId, $checkedIds, [
            'min_level' => $minLevelData,
            'show_on_pc' => $showOnPcData,
            'show_on_mobile' => $showOnMobileData,
            'is_active' => $isActiveData,
            'layout_type' => $layoutTypeData,
        ]);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }
}
