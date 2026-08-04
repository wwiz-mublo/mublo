<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\CategoryService;
use Mublo\Packages\Shop\Helper\CategoryUrl;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;

/**
 * Admin CategoryController
 *
 * Core 메뉴 관리와 동일한 패턴:
 * - 탭1: 카테고리 아이템 CRUD (shop_category_items)
 * - 탭2: 카테고리 트리 빌더 (shop_category_tree)
 *
 * 라우팅:
 * - GET  /admin/shop/categories               → index
 * - POST /admin/shop/categories/item-store    → itemStore
 * - GET  /admin/shop/categories/item-view     → itemView
 * - POST /admin/shop/categories/item-delete   → itemDelete
 * - POST /admin/shop/categories/tree-update   → treeUpdate
 * - POST /admin/shop/categories/menu-register   → menuRegister
 * - POST /admin/shop/categories/menu-unregister → menuUnregister
 */
class CategoryController
{
    private CategoryService $categoryService;
    private MemberLevelCatalogInterface $levelService;
    private SiteProvisioningInterface $menuProvisioning;
    private MenuManagementInterface $menuManagement;

    private const MENU_URL_PREFIX = CategoryUrl::MENU_PREFIX;

    public function __construct(
        CategoryService $categoryService,
        MemberLevelCatalogInterface $levelService,
        SiteProvisioningInterface $menuProvisioning,
        MenuManagementInterface $menuManagement
    ) {
        $this->categoryService = $categoryService;
        $this->levelService = $levelService;
        $this->menuProvisioning = $menuProvisioning;
        $this->menuManagement = $menuManagement;
    }

    /**
     * 카테고리 관리 메인 (탭 기반)
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $tab = $request->query('tab', 'items');

        // 탭1: 아이템 목록
        $itemsResult = $this->categoryService->getItems($domainId);
        $items = $itemsResult->get('items', []);

        // 탭2: 트리 (계층형)
        $treeHierarchy = $this->categoryService->getTreeHierarchy($domainId);

        // 트리 flat (아이템 정보 포함)
        $treeResult = $this->categoryService->getTree($domainId);
        $flatTree = $treeResult->get('items', []);

        // 트리에 있는 카테고리 코드 목록 (아이템 풀에서 제외 표시용)
        $usedCodes = array_column($flatTree, 'category_code');

        // 이미 메뉴에 등록된 카테고리 코드 → item_id 맵
        $menuItems = $this->menuManagement->findMenusByUrlPrefix($domainId, self::MENU_URL_PREFIX);
        $registeredMap = [];
        foreach ($menuItems as $mi) {
            if ($mi->url === null) {
                continue;
            }
            $code = substr($mi->url, strlen(self::MENU_URL_PREFIX));
            $registeredMap[$code] = $mi->itemId;
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Category/Index')
            ->withData([
                'pageTitle' => '카테고리 관리',
                'activeTab' => $tab,
                'items' => $items,
                'tree' => $treeHierarchy,
                'flatTree' => $flatTree,
                'usedCodes' => $usedCodes,
                'registeredMap' => $registeredMap,
                'levelOptions' => [0 => '비회원'] + array_column(
                    array_map(fn($level) => ['value' => $level->levelValue, 'name' => $level->name], $this->levelService->all()),
                    'name',
                    'value'
                ),
            ]);
    }

    /**
     * 카테고리 아이템 저장 (생성/수정)
     */
    public function itemStore(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $categoryId = (int) $request->json('category_id', 0);
        $data = [
            'name' => $request->json('name', ''),
            'description' => $request->json('description', ''),
            'allow_member_level' => (int) $request->json('allow_member_level', 0),
            'allow_coupon' => $request->json('allow_coupon', 1),
            'is_adult' => $request->json('is_adult', 0),
            'is_active' => $request->json('is_active', 1),
        ];

        if ($categoryId > 0) {
            $result = $this->categoryService->updateItem($domainId, $categoryId, $data);
        } else {
            $result = $this->categoryService->createItem($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 카테고리 아이템 조회 (AJAX)
     */
    public function itemView(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $categoryId = (int) $request->query('category_id', 0);

        if ($categoryId <= 0) {
            return JsonResponse::error('카테고리 ID가 필요합니다.');
        }

        $item = $this->categoryService->getItem($domainId, $categoryId);

        if (!$item) {
            return JsonResponse::error('카테고리를 찾을 수 없습니다.');
        }

        return JsonResponse::success($item);
    }

    /**
     * 카테고리 아이템 삭제
     */
    public function itemDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $categoryId = (int) $request->json('category_id', 0);

        if ($categoryId <= 0) {
            return JsonResponse::error('카테고리 ID가 필요합니다.');
        }

        $result = $this->categoryService->deleteItem($domainId, $categoryId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 카테고리 트리 저장
     */
    public function treeUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $treeData = $request->json('tree', []);

        if (!is_array($treeData)) {
            return JsonResponse::error('잘못된 트리 데이터입니다.');
        }

        $result = $this->categoryService->saveTree($domainId, $treeData);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    // ── 메뉴 아이템 등록/해제 ──

    /**
     * 카테고리를 메뉴 아이템으로 등록
     * POST /admin/shop/categories/menu-register
     *
     * body: { category_codes: ['xK9mL3nR', ...] }
     */
    public function menuRegister(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $codes = $request->json('category_codes', []);

        if (empty($codes) || !is_array($codes)) {
            return JsonResponse::error('등록할 카테고리를 선택해주세요.');
        }

        // 이미 등록된 카테고리 확인
        $existing = $this->menuManagement->findMenusByUrlPrefix($domainId, self::MENU_URL_PREFIX);
        $registeredUrls = [];
        foreach ($existing as $item) {
            if ($item->url !== null) {
                $registeredUrls[$item->url] = true;
            }
        }

        // 카테고리 정보 조회 (라벨용)
        $itemsResult = $this->categoryService->getItems($domainId);
        $allItems = $itemsResult->get('items', []);
        $categoryMap = [];
        foreach ($allItems as $cat) {
            $categoryMap[$cat['category_code']] = $cat['name'] ?? $cat['category_code'];
        }

        $created = 0;
        $skipped = 0;

        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }

            $url = self::MENU_URL_PREFIX . $code;

            if (isset($registeredUrls[$url])) {
                $skipped++;
                continue;
            }

            $label = $categoryMap[$code] ?? $code;

            $result = $this->menuProvisioning->createMenuItem($domainId, [
                'label' => $label,
                'url' => $url,
                'provider_type' => 'package',
                'provider_name' => 'Shop',
            ]);

            if ($result->isSuccess()) {
                $created++;
                $registeredUrls[$url] = true;
            }
        }

        $msg = "{$created}건 메뉴 등록 완료";
        if ($skipped > 0) {
            $msg .= " ({$skipped}건 이미 등록됨)";
        }

        return JsonResponse::success(['created' => $created, 'skipped' => $skipped], $msg);
    }

    /**
     * 카테고리 메뉴 아이템 해제
     * POST /admin/shop/categories/menu-unregister
     *
     * body: { category_codes: ['xK9mL3nR', ...] }
     */
    public function menuUnregister(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $codes = $request->json('category_codes', []);

        if (empty($codes) || !is_array($codes)) {
            return JsonResponse::error('해제할 카테고리를 선택해주세요.');
        }

        // 등록된 메뉴 아이템 조회
        $existing = $this->menuManagement->findMenusByUrlPrefix($domainId, self::MENU_URL_PREFIX);
        $urlToItemId = [];
        foreach ($existing as $item) {
            if ($item->url !== null) {
                $urlToItemId[$item->url] = $item->itemId;
            }
        }

        $deleted = 0;

        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }

            $url = self::MENU_URL_PREFIX . $code;
            $itemId = $urlToItemId[$url] ?? null;

            if ($itemId === null) {
                continue;
            }

            $result = $this->menuManagement->removeMenu($domainId, $itemId);
            if ($result->isSuccess()) {
                $deleted++;
            }
        }

        return JsonResponse::success(['deleted' => $deleted], "{$deleted}건 메뉴 해제 완료");
    }
}
