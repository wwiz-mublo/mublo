<?php

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\ExhibitionService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\WishlistService;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Helper\ProductPresenter;
use Mublo\Contract\Auth\AuthContextInterface;

class ExhibitionController
{
    /** 카테고리 아이템 1건을 상품으로 펼칠 때의 최대 노출 개수 */
    private const CATEGORY_ITEM_LIMIT = 100;

    private ExhibitionService $exhibitionService;
    private ShopConfigService $shopConfigService;
    private ReviewService $reviewService;
    private WishlistService $wishlistService;
    private ProductService $productService;
    private AuthContextInterface $authService;

    public function __construct(
        ExhibitionService $exhibitionService,
        ShopConfigService $shopConfigService,
        ReviewService $reviewService,
        WishlistService $wishlistService,
        ProductService $productService,
        AuthContextInterface $authService
    ) {
        $this->exhibitionService = $exhibitionService;
        $this->shopConfigService = $shopConfigService;
        $this->reviewService     = $reviewService;
        $this->wishlistService   = $wishlistService;
        $this->productService    = $productService;
        $this->authService       = $authService;
    }

    /** 기획전 목록 페이지 */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId    = $context->getDomainId() ?? 1;
        $exhibitions = $this->exhibitionService->getActiveList($domainId);

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Exhibition/List'))
            ->withData([
                'pageTitle'   => '기획전',
                'exhibitions' => $exhibitions,
            ]);
    }

    /** 기획전 상세 페이지 */
    public function view(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        // 숫자 → id 라우트, 그 외 → slug 라우트 (id 접근 유지)
        if (isset($params['id'])) {
            $exhibition = $this->exhibitionService->getDetail($domainId, (int) $params['id']);
        } else {
            $exhibition = $this->exhibitionService->getDetailBySlug($domainId, (string) ($params['slug'] ?? ''));
        }

        // 없거나 비활성이거나 기간 외인 경우 404
        if (!$exhibition || !$this->isVisible($exhibition)) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Exhibition/List'))
                ->withData([
                    'pageTitle'   => '기획전',
                    'exhibitions' => $this->exhibitionService->getActiveList($domainId),
                    'error'       => '기획전을 찾을 수 없습니다.',
                ]);
        }

        $items    = $exhibition['items'] ?? [];
        $products = $this->buildProducts($domainId, $items);

        $memberId      = $this->authService->id() ?? 0;
        $wishlistedIds = $memberId > 0
            ? array_map('intval', $this->wishlistService->getMemberGoodsIds($domainId, $memberId))
            : [];

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Exhibition/View'))
            ->withData([
                'pageTitle'     => $exhibition['title'],
                'exhibition'    => $exhibition,
                'items'         => $items,
                'products'      => $products,
                'wishlistedIds' => $wishlistedIds,
            ]);
    }

    /**
     * 기획전 연결 아이템을 상품 목록과 동일한 ProductPresenter 카드 데이터로 변환.
     * - goods 아이템: 지정 상품
     * - category 아이템: 카테고리(하위 포함) 상품을 상한선까지 펼침
     * 활성 상품만 노출하며 기획전에 지정된 순서를 유지한다.
     */
    private function buildProducts(int $domainId, array $items): array
    {
        // 아이템 순서를 유지하며 노출할 goods_id 수집
        $goodsIds = [];
        foreach ($items as $item) {
            $type = $item['target_type'] ?? '';
            if ($type === 'goods' && !empty($item['goods_id'])) {
                $goodsIds[] = (int) $item['goods_id'];
            } elseif ($type === 'category' && !empty($item['category_code'])) {
                foreach ($this->resolveCategoryGoodsIds($domainId, (string) $item['category_code']) as $gid) {
                    $goodsIds[] = $gid;
                }
            }
        }

        // 중복 제거(첫 등장 순서 유지)
        $goodsIds = array_values(array_unique($goodsIds));
        if (empty($goodsIds)) {
            return [];
        }

        // 활성 상품 조회(순서 미보장) 후 지정 순서대로 재정렬
        $productData = $this->productService->getActiveProductsByIds($domainId, $goodsIds);
        $ordered = $productData['items'];
        if (empty($ordered)) {
            return [];
        }

        $orderedIds  = array_column($ordered, 'goods_id');
        $mainImages  = $productData['mainImages'];
        $reviewStats = $this->reviewService->getStatsByGoodsIds($domainId, $orderedIds);

        $shopConfig = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $presenter  = new ProductPresenter($shopConfig);

        return $presenter->toList($ordered, $mainImages, $reviewStats);
    }

    /**
     * 카테고리(하위 포함) 활성 상품의 goods_id 목록을 상한선까지 조회.
     * ProductService::getList가 category_code → 하위 카테고리 코드 확장을 처리한다.
     *
     * @return int[]
     */
    private function resolveCategoryGoodsIds(int $domainId, string $categoryCode): array
    {
        $result = $this->productService->getList(
            $domainId,
            ['category_code' => $categoryCode, 'is_active' => true],
            1,
            self::CATEGORY_ITEM_LIMIT
        );

        return array_map(
            fn($it) => (int) ($it['goods_id'] ?? 0),
            $result->get('items', [])
        );
    }

    private function isVisible(array $exhibition): bool
    {
        if (!$exhibition['is_active']) {
            return false;
        }
        $now = time();
        if (!empty($exhibition['start_date']) && strtotime($exhibition['start_date']) > $now) {
            return false;
        }
        if (!empty($exhibition['end_date']) && strtotime($exhibition['end_date']) < $now) {
            return false;
        }
        return true;
    }
}
