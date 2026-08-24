<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\Service\CategoryService;
use Mublo\Packages\Shop\Service\OptionPresetService;
use Mublo\Packages\Shop\Service\ShippingService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Enum\OptionMode;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin ProductController
 *
 * 상품 관리 컨트롤러
 *
 * 라우팅:
 * - GET  /admin/shop/products              → index (상품 목록)
 * - GET  /admin/shop/products/create       → create (등록 폼)
 * - GET  /admin/shop/products/{id}/edit    → edit (수정 폼)
 * - POST /admin/shop/products/store        → store (생성/수정)
 * - POST /admin/shop/products/{id}/delete  → delete (삭제)
 * - POST /admin/shop/products/listDelete   → listDelete (일괄 삭제)
 */
class ProductController
{
    private ProductService $productService;
    private CategoryService $categoryService;
    private OptionPresetService $optionPresetService;
    private ShippingService $shippingService;
    private FileUploader $fileUploader;
    private ShopConfigService $shopConfigService;
    private MemberLevelCatalogInterface $memberLevelService;

    public function __construct(
        ProductService $productService,
        CategoryService $categoryService,
        OptionPresetService $optionPresetService,
        ShippingService $shippingService,
        FileUploader $fileUploader,
        ShopConfigService $shopConfigService,
        MemberLevelCatalogInterface $memberLevelService
    ) {
        $this->productService = $productService;
        $this->categoryService = $categoryService;
        $this->optionPresetService = $optionPresetService;
        $this->shippingService = $shippingService;
        $this->fileUploader = $fileUploader;
        $this->shopConfigService = $shopConfigService;
        $this->memberLevelService = $memberLevelService;
    }

    /**
     * 상품 목록
     *
     * GET /admin/shop/products
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = (int) ($request->get('page') ?? 1);
        $perPage = (int) ($request->get('per_page') ?? 20);

        // 검색/필터 파라미터
        $filters = [
            'keyword' => $request->get('keyword') ?? '',
            'search_field' => $request->get('search_field') ?? '',
            'category_code' => $request->get('category_code') ?? '',
            'is_active' => $request->get('is_active'),
            'option_mode' => $request->get('option_mode') ?? '',
        ];

        $result = $this->productService->getList($domainId, $filters, $page, $perPage);

        $categoriesResult = $this->categoryService->getTree($domainId);

        // 배송 템플릿 유형 맵 (shipping_id => shipping_method) + 기본 템플릿 (미지정 상품용)
        $shippingMethods = [];
        foreach ($this->shippingService->getList($domainId)->get('items', []) as $tpl) {
            $shippingMethods[(int) ($tpl['shipping_id'] ?? 0)] = $tpl['shipping_method'] ?? '';
        }
        $defaultShippingTemplateId = (int) ($this->shopConfigService->getConfig($domainId)
            ->get('config', [])['default_shipping_template_id'] ?? 0);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Product/List')
            ->withData([
                'pageTitle' => '상품 관리',
                'products' => $result->get('items', []),
                'mainImages' => $result->get('mainImages', []),
                'shippingMethods' => $shippingMethods,
                'defaultShippingTemplateId' => $defaultShippingTemplateId,
                'pagination' => [
                    'totalItems' => $result->get('totalItems', 0),
                    'perPage' => $result->get('perPage', $perPage),
                    'currentPage' => $result->get('currentPage', $page),
                    'totalPages' => $result->get('totalPages', 1),
                ],
                'categories' => $categoriesResult->get('items', []),
                'filters' => $filters,
                'optionModeOptions' => OptionMode::options(),
            ]);
    }

    /**
     * 상품 등록 폼
     *
     * GET /admin/shop/products/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $listUrl = $this->buildListUrl($context);

        $categoriesResult = $this->categoryService->getTree($domainId);
        $categoryTree = $this->categoryService->getTreeHierarchy($domainId);
        $presetsResult = $this->optionPresetService->getList($domainId);
        $shippingResult = $this->shippingService->getList($domainId);

        // 회원 레벨 목록 (등급별 할인/적립용)
        $memberLevels = $this->memberLevelService->all();

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Product/Form')
            ->withData([
                'pageTitle' => '상품 등록',
                'isEdit' => false,
                'listUrl' => $listUrl,
                'product' => [
                    // 미지정(0) → 쇼핑몰 기본 배송 템플릿을 동적으로 따름
                    'shipping_template_id' => 0,
                    'shipping_apply_type' => 'COMBINED',
                ],
                'productImages' => [],
                'productOptions' => [],
                'productDetails' => [],
                'productCombos' => [],
                'productNotice' => null,
                'noticeTemplates' => $this->productService->getCurrentNoticeTemplates(),
                'categories' => $categoriesResult->get('items', []),
                'categoryTree' => $categoryTree,
                'presets' => $presetsResult->get('items', []),
                'shippingTemplates' => $shippingResult->get('items', []),
                'discountTypeOptions' => DiscountType::productOptions(),
                'rewardTypeOptions' => RewardType::productOptions(),
                'optionModeOptions' => OptionMode::options(),
                'memberLevels' => $memberLevels,
            ]);
    }

    /**
     * 상품 수정 폼
     *
     * GET /admin/shop/products/{id}/edit
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $goodsId = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));
        $listUrl = $this->buildListUrl($context);

        if ($goodsId <= 0) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '상품을 찾을 수 없습니다.']);
        }

        $result = $this->productService->getDetail($goodsId, $domainId);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => $result->getMessage()]);
        }

        $productData = $result->get('product', []);
        $productImages = $productData['images'] ?? [];
        $productOptions = $productData['options'] ?? [];
        $productDetails = $productData['details'] ?? [];
        $productCombos = $productData['combos'] ?? [];
        $productNotice = $productData['product_notice'] ?? null;
        unset($productData['images'], $productData['options'], $productData['details'], $productData['combos'], $productData['product_notice']);

        $categoriesResult = $this->categoryService->getTree($domainId);
        $categoryTree = $this->categoryService->getTreeHierarchy($domainId);
        $presetsResult = $this->optionPresetService->getList($domainId);
        $shippingResult = $this->shippingService->getList($domainId);

        // 회원 레벨 목록 (등급별 할인/적립용)
        $memberLevels = $this->memberLevelService->all();

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Product/Form')
            ->withData([
                'pageTitle' => '상품 수정',
                'isEdit' => true,
                'listUrl' => $listUrl,
                'product' => $productData,
                'productImages' => $productImages,
                'productOptions' => $productOptions,
                'productDetails' => $productDetails,
                'productCombos' => $productCombos,
                'productNotice' => $productNotice,
                'noticeTemplates' => $this->productService->getCurrentNoticeTemplates(),
                'categories' => $categoriesResult->get('items', []),
                'categoryTree' => $categoryTree,
                'presets' => $presetsResult->get('items', []),
                'shippingTemplates' => $shippingResult->get('items', []),
                'discountTypeOptions' => DiscountType::productOptions(),
                'rewardTypeOptions' => RewardType::productOptions(),
                'optionModeOptions' => OptionMode::options(),
                'memberLevels' => $memberLevels,
            ]);
    }

    /**
     * 상품 저장 (생성/수정)
     *
     * POST /admin/shop/products/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $goodsId = (int) ($data['goods_id'] ?? 0);

        // item_code 조기 생성 (신규 상품, 미입력 시)
        if ($goodsId <= 0 && empty($data['item_code'])) {
            $data['item_code'] = $this->productService->generateItemCode();
        }

        // 상품별 저장 경로 구성: shop/product/{category_code}/{item_code}
        $categoryCode = $data['category_code'] ?? '0';
        $itemCode = $data['item_code'] ?? '';

        // 수정 시 기존 상품에서 item_code 확보 (formData에 없을 경우)
        if ($goodsId > 0 && empty($itemCode)) {
            $existing = $this->productService->getDetail($goodsId, $domainId);
            if ($existing->isSuccess()) {
                $product = $existing->get('product', []);
                $itemCode = $product['item_code'] ?? '';
                if (empty($categoryCode) || $categoryCode === '0') {
                    $categoryCode = $product['category_code'] ?? '0';
                }
            }
        }

        $storagePath = 'shop/product/' . $categoryCode . '/' . $itemCode;

        // 이미지 처리: 파일 업로드 + 기존 이미지 유지
        $imagesInput = $request->input('images') ?? [];
        $mainIdx = $request->input('images_main');
        $data['images'] = $this->processImageUploads($domainId, $imagesInput, $mainIdx, $storagePath);

        // 옵션 데이터 (options[idx][option_name], options[idx][values][...])
        $data['options'] = $request->input('options') ?? [];

        // 옵션 조합 데이터
        $data['combos'] = $request->input('combos') ?? [];

        // 상세정보 (에디터 HTML)
        $data['details'] = $request->input('details') ?? [];

        // 상품정보제공고시 (모든 항목은 선택 입력)
        $data['product_notice'] = $request->input('product_notice') ?? ['template_id' => 0, 'values' => []];

        // 저장 경로를 Service에 전달 (에디터 이미지 이동용)
        $data['_storage_path'] = $storagePath;
        $data['_domain_id'] = $domainId;

        if ($goodsId > 0) {
            $result = $this->productService->update($goodsId, $data, $domainId);
        } else {
            $result = $this->productService->create($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/shop/products'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 상품 삭제
     *
     * POST /admin/shop/products/{id}/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $goodsId = (int) ($params['id'] ?? $params[0] ?? $request->json('goods_id', 0));

        if ($goodsId <= 0) {
            return JsonResponse::error('상품 ID가 필요합니다.');
        }

        $result = $this->productService->delete($goodsId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 상품 일괄 삭제
     *
     * POST /admin/shop/products/listDelete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $chk = $request->input('chk') ?? [];
        $goodsIds = array_map('intval', array_filter($chk));

        if (empty($goodsIds)) {
            return JsonResponse::error('삭제할 상품을 선택해 주세요.');
        }

        $result = $this->productService->deleteMultiple($goodsIds, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 블록 에디터용 상품 목록 (AJAX)
     *
     * GET|POST /admin/shop/block-items
     *
     * Query params:
     *   - page: 페이지 번호 (기본 1)
     *   - category_code: 카테고리 코드 (선택)
     *   - keyword: 상품명 검색어 (선택)
     */
    public function blockItems(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $page = max(1, (int) ($request->query('page', 1)));
        $perPage = 20;

        $filters = [];
        $categoryCode = trim($request->query('category_code', ''));
        if ($categoryCode !== '') {
            $filters['category_code'] = $categoryCode;
        }
        $keyword = trim($request->query('keyword', ''));
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }

        // 첫 로드 시 카테고리 트리 포함
        if ($request->query('include_categories') === '1') {
            $filters['include_categories'] = true;
        }

        $data = $this->productService->getListForBlock($domainId, $filters, $page, $perPage);

        return JsonResponse::success($data);
    }

    /**
     * 이미지 업로드 처리
     *
     * 폼 구조:
     * - images[idx][image_id]  : 기존 이미지 ID (수정 시)
     * - images[idx][image_url] : 기존 이미지 URL (수정 시)
     * - images[idx][delete]    : 삭제 플래그
     * - fileData[images][idx]  : 새 파일 업로드
     * - images_main            : 대표 이미지 인덱스
     *
     * @param string $storagePath 상품별 저장 경로 (예: 'shop/product/FOOD/G-20260210-0001')
     * @return array Service에 전달할 이미지 배열
     */
    private function processImageUploads(int $domainId, array $imagesInput, ?string $mainIdx, string $storagePath = 'shop/product'): array
    {
        $uploadedFiles = UploadedFile::fromGlobalNested('fileData', 'images');

        $images = [];
        $autoSort = 0;

        foreach ($imagesInput as $idx => $imgData) {
            // 삭제 표시된 이미지 스킵
            if (!empty($imgData['delete'])) {
                continue;
            }

            $imageUrl = null;

            // 새 파일 업로드 확인
            if (isset($uploadedFiles[$idx]) && $uploadedFiles[$idx]->isValid()) {
                $uploadResult = $this->fileUploader->upload($uploadedFiles[$idx], $domainId, [
                    'subdirectory' => $storagePath,
                    'include_date' => false,
                    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    'max_size' => 5 * 1024 * 1024,
                ]);

                if ($uploadResult->isSuccess()) {
                    $imageUrl = $this->fileUploader->getUrl(
                        $uploadResult->getRelativePath(),
                        $uploadResult->getStoredName()
                    );
                }
            } elseif (!empty($imgData['image_url'])) {
                // 기존 이미지 유지
                $imageUrl = $imgData['image_url'];
            }

            if ($imageUrl) {
                // 폼이 드래그 순서에 맞춰 채워주는 sort_order 우선, 없으면 자동 증가값
                $sortOrder = isset($imgData['sort_order'])
                    ? (int) $imgData['sort_order']
                    : $autoSort;

                $images[] = [
                    'image_url' => $imageUrl,
                    'is_main' => ((string) $idx === (string) $mainIdx) ? 1 : 0,
                    'sort_order' => $sortOrder,
                ];
                $autoSort++;
            }
        }

        // 시각 순서대로 재정렬 (대표 자동 지정 fallback이 시각상 첫 번째를 가리키도록)
        usort($images, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        // 대표 이미지 미지정 시 첫 번째를 대표로
        if (!empty($images)) {
            $hasMain = false;
            foreach ($images as $img) {
                if ($img['is_main']) {
                    $hasMain = true;
                    break;
                }
            }
            if (!$hasMain) {
                $images[0]['is_main'] = 1;
            }
        }

        return $images;
    }

    /**
     * 목록 복귀 URL 생성 (검색/페이지 상태 유지)
     *
     * 목록 화면이 넘긴 return 쿼리스트링을 화이트리스트 키만 재구성해 안전하게 복원한다.
     * (상세 → 목록 복귀 시 검색값 유지)
     */
    private function buildListUrl(Context $context): string
    {
        $base = '/admin/shop/products';
        $return = (string) ($context->getRequest()->query('return', '') ?? '');
        if ($return === '') {
            return $base;
        }

        parse_str($return, $parsed);
        $allowed = ['keyword', 'search_field', 'category_code', 'is_active', 'page'];
        $params = array_filter(
            array_intersect_key($parsed, array_flip($allowed)),
            fn($v) => $v !== '' && $v !== null
        );

        return $params ? $base . '?' . http_build_query($params) : $base;
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => [
                'goods_id',
                'origin_price', 'display_price', 'stock_quantity',
                'discount_value', 'reward_value', 'reward_review',
                'shipping_template_id',
            ],
            'enum' => [
                'discount_type' => ['values' => ['NONE', 'DEFAULT', 'BASIC', 'LEVEL', 'PERCENTAGE', 'FIXED'], 'default' => 'DEFAULT'],
                'reward_type' => ['values' => ['NONE', 'DEFAULT', 'BASIC', 'LEVEL', 'PERCENTAGE', 'FIXED'], 'default' => 'DEFAULT'],
                'option_mode' => ['values' => ['NONE', 'SINGLE', 'COMBINATION'], 'default' => 'NONE'],
                'shipping_apply_type' => ['values' => ['COMBINED', 'SEPARATE'], 'default' => 'COMBINED'],
            ],
            'bool' => [
                'is_active', 'allowed_coupon',
            ],
            'required_string' => [
                'goods_name', 'item_code',
            ],
        ];
    }
}
