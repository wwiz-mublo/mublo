<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Registry\CategoryProviderRegistry;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\Service\MemberLevelResolver;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\WishlistService;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Service\InquiryService;
use Mublo\Packages\Shop\Service\ProductInfoTemplateService;
use Mublo\Packages\Shop\Helper\ProductPresenter;
use Mublo\Packages\Shop\Helper\ProductSeoPresenter;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * Front 상품 컨트롤러
 *
 * 라우팅:
 * - GET /shop/products        → index (상품 목록)
 * - GET /shop/products/{id}   → view (상품 상세)
 */
class ProductController
{
    private ProductService $productService;
    private CategoryProviderRegistry $categoryRegistry;
    private ShopConfigService $shopConfigService;
    private ReviewService $reviewService;
    private InquiryService $inquiryService;
    private ProductInfoTemplateService $templateService;
    private WishlistService $wishlistService;
    private AuthContextInterface $authService;
    private ?MemberLevelResolver $memberLevelResolver;

    public function __construct(
        ProductService $productService,
        CategoryProviderRegistry $categoryRegistry,
        ShopConfigService $shopConfigService,
        ReviewService $reviewService,
        InquiryService $inquiryService,
        ProductInfoTemplateService $templateService,
        WishlistService $wishlistService,
        AuthContextInterface $authService,
        ?MemberLevelResolver $memberLevelResolver = null
    ) {
        $this->productService = $productService;
        $this->categoryRegistry = $categoryRegistry;
        $this->shopConfigService = $shopConfigService;
        $this->reviewService = $reviewService;
        $this->inquiryService = $inquiryService;
        $this->templateService = $templateService;
        $this->wishlistService = $wishlistService;
        $this->authService = $authService;
        $this->memberLevelResolver = $memberLevelResolver;
    }

    /**
     * 보고 있는 회원의 등급 ID (비회원이면 null)
     *
     * 로그인 사용자는 levelValue 를 이미 들고 있으므로 회원 조회 없이 등급 ID만 해석한다.
     */
    private function viewerLevelId(): ?int
    {
        $levelValue = $this->authService->currentUser()?->levelValue;

        return $levelValue === null
            ? null
            : $this->memberLevelResolver?->levelIdForValue($levelValue);
    }

    /**
     * 상품 목록
     *
     * 레거시 쿼리 URL 을 경로 URL 로 정규화할 때만 RedirectResponse.
     */
    public function index(array $params, Context $context): AbstractResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 카테고리 URL 정규화 — canonical 이 쿼리스트링을 버리므로(FrontViewRenderer),
        // ?category_code=X 로 들어온 요청은 /shop/category/X 로 모은다. 그래야 카테고리
        // 페이지가 자기 자신을 canonical 로 선언하고, 사이트맵이 제출하는 URL 과도 맞는다.
        $pathCategory = trim((string) ($params['categoryCode'] ?? ''));
        $queryCategory = trim((string) ($request->get('category_code') ?? ''));
        if ($pathCategory === '' && $queryCategory !== '') {
            $query = $request->getQuery();
            unset($query['category_code']);
            return RedirectResponse::permanent(
                '/shop/category/' . rawurlencode($queryCategory)
                . ($query !== [] ? '?' . http_build_query($query) : '')
            );
        }

        // 필터 파라미터 (path param 우선, 없으면 query string)
        $categoryCode = trim((string) ($params['categoryCode'] ?? $request->get('category_code') ?? ''));
        $keyword = trim($request->get('keyword') ?? '');
        $sort = trim($request->get('sort') ?? 'newest');
        $page = max(1, (int) ($request->get('page') ?? 1));
        $perPage = 12;

        $filters = ['is_active' => true];
        if ($categoryCode !== '') {
            $filters['category_code'] = $categoryCode;
        }
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }
        if ($sort !== '') {
            $filters['sort'] = $sort;
        }

        // 상품 목록 조회
        $result = $this->productService->getList($domainId, $filters, $page, $perPage);

        $items = $result->get('items', []);
        $mainImages = $result->get('mainImages', []);
        $pagination = [
            'totalItems'  => $result->get('totalItems', 0),
            'perPage'     => $result->get('perPage', $perPage),
            'currentPage' => $result->get('currentPage', $page),
            'totalPages'  => $result->get('totalPages', 1),
            'pageNums'    => 10,
        ];

        // 구매후기 통계 배치 조회
        $goodsIds = array_column($items, 'goods_id');
        $reviewStats = !empty($goodsIds)
            ? $this->reviewService->getStatsByGoodsIds($domainId, $goodsIds)
            : [];

        // ProductPresenter 적용
        $shopConfig = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $presenter = new ProductPresenter($shopConfig, null, $this->viewerLevelId());
        $products = $presenter->toList($items, $mainImages, $reviewStats);

        // 회원 찜 상태 매핑
        $memberId = $this->authService->id() ?? 0;
        $wishlistedIds = $memberId > 0
            ? array_map('intval', $this->wishlistService->getMemberGoodsIds($domainId, $memberId))
            : [];

        // 카테고리 트리 (표준 규격: code, label, link, children)
        $categoryTree = $this->categoryRegistry->getTree('shop', $domainId);

        // 카테고리·검색어를 title 에 반영해야 목록 페이지들이 서로 구분된다
        $categoryPath = $categoryCode !== ''
            ? $this->resolveCategoryPath($categoryTree, $categoryCode)
            : [];
        $currentCrumb = $categoryPath !== [] ? end($categoryPath) : null;
        $categoryName = is_array($currentCrumb) ? (string) ($currentCrumb['label'] ?? '') : '';

        $seo = (new ProductSeoPresenter($request->getSchemeAndHost()))
            ->list($categoryName, $keyword, (int) $pagination['totalItems']);

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Product/List'))
            ->withData(array_merge([
                'products'      => $products,
                'pagination'    => $pagination,
                'categoryTree'  => $categoryTree,
                'wishlistedIds' => $wishlistedIds,
                'categoryName'  => $categoryName,
                'filters'       => [
                    'category_code' => $categoryCode,
                    'keyword'       => $keyword,
                    'sort'          => $sort,
                ],
            ], $seo));
    }

    /**
     * 상품 목록 AJAX (필터/페이지 변경 시)
     */
    public function list(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $categoryCode = trim($request->get('category_code') ?? '');
        $keyword = trim($request->get('keyword') ?? '');
        $sort = trim($request->get('sort') ?? 'newest');
        $page = max(1, (int) ($request->get('page') ?? 1));
        $perPage = (int) ($request->get('per_page') ?? 12);
        if ($perPage <= 0 || $perPage > 100) {
            $perPage = 12;
        }

        $filters = ['is_active' => true];
        if ($categoryCode !== '') {
            $filters['category_code'] = $categoryCode;
        }
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }
        if ($sort !== '') {
            $filters['sort'] = $sort;
        }

        $result = $this->productService->getList($domainId, $filters, $page, $perPage);

        $items = $result->get('items', []);
        $mainImages = $result->get('mainImages', []);

        $goodsIds = array_column($items, 'goods_id');
        $reviewStats = !empty($goodsIds)
            ? $this->reviewService->getStatsByGoodsIds($domainId, $goodsIds)
            : [];

        $shopConfig = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $presenter = new ProductPresenter($shopConfig, null, $this->viewerLevelId());
        $products = $presenter->toList($items, $mainImages, $reviewStats);

        $memberId = $this->authService->id() ?? 0;
        $wishlistedIds = $memberId > 0
            ? array_map('intval', $this->wishlistService->getMemberGoodsIds($domainId, $memberId))
            : [];

        return JsonResponse::success([
            'products'      => $products,
            'wishlistedIds' => $wishlistedIds,
            'pagination'    => [
                'totalItems'  => $result->get('totalItems', 0),
                'perPage'     => $result->get('perPage', $perPage),
                'currentPage' => $result->get('currentPage', $page),
                'totalPages'  => $result->get('totalPages', 1),
            ],
        ]);
    }

    /**
     * 상품 상세
     */
    /** 슬러그 URL 로 정규화할 때만 RedirectResponse, 그 외에는 ViewResponse. */
    public function view(array $params, Context $context): AbstractResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $goodsId = (int) ($params['id'] ?? $params[0] ?? 0);

        if ($goodsId <= 0) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Product/View'))
                ->withStatusCode(404)
                ->withData(['product' => null, 'message' => '상품을 찾을 수 없습니다.']);
        }

        $result = $this->productService->getDetail($goodsId, $domainId);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Product/View'))
                ->withStatusCode(404)
                ->withData(['product' => null, 'message' => $result->getMessage()]);
        }

        $productData = $result->get('product', []);

        // 비활성 상품은 Front에서 노출하지 않음 (Admin은 별도 라우트로 접근)
        if (empty($productData['is_active'])) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Product/View'))
                ->withStatusCode(404)
                ->withData(['product' => null, 'message' => '상품을 찾을 수 없습니다.']);
        }

        // URL 정규화 — /products/{id} 와 /products/{id}/{slug} 가 같은 상품을 200 으로 내면
        // 두 URL 이 각자 자기를 canonical 로 선언해 중복 콘텐츠가 된다. 슬러그가 있는
        // 상품은 슬러그 URL 하나만 정답으로 두고 나머지는 301 로 모은다.
        $slug = trim((string) ($productData['goods_slug'] ?? ''));
        if ($slug !== '') {
            $canonicalPath = '/shop/products/' . $goodsId . '/' . $slug;
            if ($context->getRequest()->getPath() !== $canonicalPath) {
                return RedirectResponse::permanent($canonicalPath);
            }
        }

        // 조회수 증가
        $this->productService->incrementHit($goodsId, $domainId);

        // 구매후기 통계
        $reviewStats = $this->reviewService->getStatsByGoodsIds($domainId, [$goodsId]);
        $reviewStat = $reviewStats[$goodsId] ?? [];

        // 상품문의 수 (노출 기준)
        $inquiryCount = $this->inquiryService->countByGoodsId($domainId, $goodsId);

        // ProductPresenter 적용
        $shopConfig = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $presenter = new ProductPresenter($shopConfig, null, $this->viewerLevelId());
        $product = $presenter->toView($productData, $reviewStat, 0, $inquiryCount);

        $memberId = $this->authService->id() ?? 0;
        $isWishlisted = $memberId > 0 && $this->wishlistService->isWishlisted($domainId, $memberId, $goodsId);

        // 카테고리 트리 (브레드크럼 + 상품정보 템플릿 매칭에 공용)
        $tree = $this->categoryRegistry->getTree('shop', $domainId);
        $catCode = (string) ($productData['category_code'] ?? '');
        $categoryPath = $catCode !== '' ? $this->resolveCategoryPath($tree, $catCode) : [];
        $pathCodes = $catCode !== '' ? $this->resolveCategoryCodePath($tree, $catCode) : [];

        // 상세 탭 구성 (순서 = detail_tab_order 설정 반영, 상품정보 템플릿 배선)
        $tabs = $this->buildDetailTabs($domainId, $product, $pathCodes);

        // 검색엔진 메타 — 없으면 사이트 기본값으로 폴백되어 모든 상품이 같은 title 을 갖는다
        $seo = (new ProductSeoPresenter($context->getRequest()->getSchemeAndHost()))
            ->detail($product, $categoryPath);

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Product/View'))
            ->withData(array_merge([
                'product' => $product,
                'isWishlisted' => $isWishlisted,
                'categoryPath' => $categoryPath,
                'tabs' => $tabs,
            ], $seo));
    }

    /**
     * 상세 탭 목록 구성.
     *
     * 순서는 shop_config.detail_tab_order(CSV)를 따르며, 'template' 위치에는
     * 상품 카테고리에 해당하는 활성 상품정보 템플릿을 tab_id 그룹별로 삽입한다.
     * 구매후기·상품문의는 기존과 동일하게 항상 노출(콘텐츠는 프론트에서 lazy-load).
     *
     * @param array<string,mixed> $product   프리젠터 적용된 상품 데이터
     * @param string[] $pathCodes 상품 카테고리의 조상+자기 코드 목록
     * @return array<int,array<string,mixed>>
     */
    private function buildDetailTabs(int $domainId, array $product, array $pathCodes): array
    {
        $known = ['detail', 'template', 'review', 'inquiry'];

        // 저장된 순서 정규화: 레거시 qna→inquiry, 미지 토큰(faq 등) 제거, 누락 토큰 보정
        $config = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $order = array_values(array_filter(
            array_map(
                fn($t) => trim($t) === 'qna' ? 'inquiry' : trim($t),
                explode(',', (string) ($config['detail_tab_order'] ?? ''))
            ),
            fn($t) => in_array($t, $known, true)
        ));
        foreach ($known as $k) {
            if (!in_array($k, $order, true)) {
                $order[] = $k;
            }
        }

        // 상품정보 템플릿: 카테고리 매칭 후 tab_id 그룹핑 (getActive는 sort_order, template_id 정렬)
        $templateGroups = [];
        foreach ($this->templateService->getActive($domainId) as $tpl) {
            $tc = (string) ($tpl['category_code'] ?? '');
            if ($tc !== '' && !in_array($tc, $pathCodes, true)) {
                continue; // 전체(NULL)가 아니고, 상품 카테고리 경로에 없으면 제외
            }
            $tabId = !empty($tpl['tab_id']) ? $tpl['tab_id'] : ('tpl_' . $tpl['template_id']);
            if (!isset($templateGroups[$tabId])) {
                $templateGroups[$tabId] = [
                    'label' => !empty($tpl['tab_name']) ? $tpl['tab_name'] : ($tpl['subject'] ?? '상품정보'),
                    'items' => [],
                ];
            }
            $templateGroups[$tabId]['items'][] = [
                'subject' => (string) ($tpl['subject'] ?? ''),
                'content' => (string) ($tpl['content'] ?? ''),
            ];
        }

        $tabs = [];
        foreach ($order as $type) {
            switch ($type) {
                case 'detail':
                    $tabs[] = ['type' => 'detail', 'key' => 'detail', 'label' => '상세설명'];
                    break;
                case 'template':
                    foreach ($templateGroups as $tabId => $grp) {
                        $tabs[] = [
                            'type'  => 'template',
                            'key'   => 'tpl-' . $tabId,
                            'label' => $grp['label'],
                            'items' => $grp['items'],
                        ];
                    }
                    break;
                case 'review':
                    $tabs[] = [
                        'type'  => 'review',
                        'key'   => 'reviews',
                        'label' => '구매후기',
                        'has'   => (bool) ($product['has_reviews'] ?? false),
                        'count' => $product['review_count_formatted'] ?? '',
                    ];
                    break;
                case 'inquiry':
                    $tabs[] = [
                        'type'  => 'inquiry',
                        'key'   => 'inquiry',
                        'label' => '상품문의',
                        'has'   => (bool) ($product['has_inquiries'] ?? false),
                        'count' => $product['inquiry_count_formatted'] ?? '',
                    ];
                    break;
            }
        }

        return $tabs;
    }

    /**
     * 카테고리 트리에서 code 노드까지의 경로(조상+자기) 반환.
     * @return array<int,array{label:string,link:string}>
     */
    private function resolveCategoryPath(array $nodes, string $code, array $trail = []): array
    {
        foreach ($nodes as $node) {
            $here = array_merge($trail, [[
                'label' => (string) ($node['label'] ?? ''),
                'link'  => (string) ($node['link'] ?? ''),
            ]]);
            if ((string) ($node['code'] ?? '') === $code) {
                return $here;
            }
            if (!empty($node['children'])) {
                $found = $this->resolveCategoryPath($node['children'], $code, $here);
                if (!empty($found)) {
                    return $found;
                }
            }
        }
        return [];
    }

    /**
     * 카테고리 트리에서 code 노드까지의 코드 경로(조상+자기) 반환.
     * 상품정보 템플릿의 category_code(카테고리+하위 적용) 매칭에 사용.
     * @return string[]
     */
    private function resolveCategoryCodePath(array $nodes, string $code, array $trail = []): array
    {
        foreach ($nodes as $node) {
            $nodeCode = (string) ($node['code'] ?? '');
            $here = array_merge($trail, [$nodeCode]);
            if ($nodeCode === $code) {
                return $here;
            }
            if (!empty($node['children'])) {
                $found = $this->resolveCategoryCodePath($node['children'], $code, $here);
                if (!empty($found)) {
                    return $found;
                }
            }
        }
        return [];
    }
}
