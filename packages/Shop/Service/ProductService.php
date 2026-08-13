<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Helper\Editor\EditorHelper;
use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Event\ProductChangedEvent;

/**
 * Product Service
 *
 * 상품 비즈니스 로직 담당
 *
 * 책임:
 * - 상품 CRUD
 * - 상품 이미지 관리
 * - 상품 옵션 관리
 * - 가격 계산 (PriceCalculator 위임)
 * - 목록 조회 + 페이지네이션
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class ProductService
{
    private ProductRepository $productRepository;
    private ProductOptionRepository $productOptionRepository;
    private CategoryRepository $categoryRepository;
    private PriceCalculator $priceCalculator;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        ProductRepository $productRepository,
        ProductOptionRepository $productOptionRepository,
        CategoryRepository $categoryRepository,
        PriceCalculator $priceCalculator,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->productRepository = $productRepository;
        $this->productOptionRepository = $productOptionRepository;
        $this->categoryRepository = $categoryRepository;
        $this->priceCalculator = $priceCalculator;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 이벤트 발행 헬퍼
     *
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 커밋 이후 이벤트 발행 헬퍼
     *
     * 반드시 트랜잭션 try/catch 를 닫은 뒤에 부른다. 커밋이 끝났으면 업무는 이미 성립했고
     * 캐시 무효화 같은 사후 처리가 실패해도 그 사실을 뒤집을 수 없다. 이 호출이 트랜잭션
     * catch 안에 있으면 리스너 예외가 롤백 경로로 흘러들어(이미 커밋돼 롤백할 것도 없는데)
     * 저장 실패로 둔갑한다. EventDispatcher 는 \Error 와 FailFastEventInterface 예외를
     * 환경과 무관하게 재throw 하므로 운영에서도 재현된다.
     */
    private function dispatchAfterCommit(EventInterface $event, string $context): void
    {
        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            error_log('[Shop ProductService::' . $context . '] post_commit_event_failed '
                . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * 상품 목록 조회 (페이지네이션 포함)
     *
     * @param int $domainId 도메인 ID
     * @param array $filters 필터 조건 (category_code, keyword, is_active 등)
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @return Result
     */
    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): Result
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        // 상위 카테고리 선택 시 하위 카테고리 상품 포함
        if (!empty($filters['category_code'])) {
            $filters['category_codes'] = $this->categoryRepository->getDescendantCodes(
                $domainId,
                $filters['category_code']
            );
            unset($filters['category_code']);
        }

        $result = $this->productRepository->getList($domainId, $filters, $page, $perPage);

        // Entity[] → array[] 변환
        $items = array_map(
            fn($entity) => $entity instanceof \Mublo\Packages\Shop\Entity\Product
                ? $entity->toArray()
                : (array) $entity,
            $result['items']
        );

        // 메인 이미지 배치 로드
        $goodsIds = array_column($items, 'goods_id');
        $mainImages = $this->productRepository->getMainImages($goodsIds);

        // 판매수량/후기/문의 집계 배치 로드 (N+1 방지) → 각 행에 병합
        $soldCounts = $this->productRepository->getSoldCounts($goodsIds);
        $reviewCounts = $this->productRepository->getReviewCounts($goodsIds);
        $inquiryCounts = $this->productRepository->getInquiryCounts($goodsIds);
        $ratingAvgs = $this->productRepository->getRatingAverages($goodsIds);
        $wishlistCounts = $this->productRepository->getWishlistCounts($goodsIds);
        foreach ($items as &$item) {
            $gid = (int) ($item['goods_id'] ?? 0);
            $item['sold_count'] = $soldCounts[$gid] ?? 0;
            $item['review_count'] = $reviewCounts[$gid] ?? 0;
            $item['inquiry_count'] = $inquiryCounts[$gid] ?? 0;
            $item['rating_avg'] = $ratingAvgs[$gid] ?? 0;
            $item['wishlist_count'] = $wishlistCounts[$gid] ?? 0;
        }
        unset($item);

        return Result::success('상품 목록을 조회했습니다.', [
            'items' => $items,
            'mainImages' => $mainImages,
            'totalItems' => $result['pagination']['totalItems'],
            'perPage' => $result['pagination']['perPage'],
            'currentPage' => $result['pagination']['currentPage'],
            'totalPages' => $result['pagination']['totalPages'],
        ]);
    }

    /**
     * 현재 도메인의 활성 상품을 입력 순서대로 조회하고 대표 이미지를 함께 반환한다.
     *
     * @param int[] $goodsIds
     * @return array{items:array<int,array>,mainImages:array}
     */
    public function getActiveProductsByIds(int $domainId, array $goodsIds): array
    {
        $goodsIds = array_values(array_unique(array_filter(array_map('intval', $goodsIds))));
        if ($goodsIds === []) {
            return ['items' => [], 'mainImages' => []];
        }

        $byId = [];
        foreach ($this->productRepository->findByIdsForDomain($domainId, $goodsIds) as $product) {
            $row = $product->toArray();
            $byId[(int) $row['goods_id']] = $row;
        }

        $items = [];
        foreach ($goodsIds as $goodsId) {
            if (isset($byId[$goodsId])) {
                $items[] = $byId[$goodsId];
            }
        }

        $scopedIds = array_map('intval', array_column($items, 'goods_id'));
        return [
            'items' => $items,
            'mainImages' => $this->productRepository->getMainImages($scopedIds),
        ];
    }

    /**
     * 상품 상세 조회 (이미지 + 옵션 + 조합 포함)
     *
     * @param int $goodsId 상품 ID
     * @param int $domainId 현재 도메인 ID
     * @return Result
     */
    public function getDetail(int $goodsId, int $domainId): Result
    {
        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('상품을 찾을 수 없습니다.');
        }

        $productData = $product instanceof \Mublo\Packages\Shop\Entity\Product
            ? $product->toArray()
            : (array) $product;

        // 이미지 목록
        $images = $this->productRepository->getImages($goodsId);
        $productData['images'] = $images;

        // 옵션 목록 + 각 옵션의 값 (getByProduct가 값까지 포함하여 반환)
        $rawOptions = $this->productOptionRepository->getByProduct($goodsId);
        $options = [];
        foreach ($rawOptions as $raw) {
            $opt = $raw['option'] instanceof \Mublo\Packages\Shop\Entity\ProductOption
                ? $raw['option']->toArray()
                : (array) $raw['option'];
            $opt['values'] = $raw['values'] ?? [];
            $options[] = $opt;
        }
        $productData['options'] = $options;

        // 옵션 조합 목록
        $combos = $this->productOptionRepository->getCombos($goodsId);
        $productData['combos'] = $combos;

        // 상세정보 목록
        $details = $this->productRepository->getDetails($goodsId);
        $productData['details'] = $details;

        return Result::success('상품 상세를 조회했습니다.', ['product' => $productData]);
    }

    /**
     * 현재 도메인 상품의 조회수 증가
     */
    public function incrementHit(int $goodsId, int $domainId): void
    {
        $this->productRepository->incrementHit($domainId, $goodsId);
    }

    /**
     * 상품 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 상품 데이터
     * @return Result
     */
    public function create(int $domainId, array $data): Result
    {
        // 필수 필드 검증
        if (empty($data['goods_name'])) {
            return Result::failure('상품명을 입력해주세요.');
        }

        if (!isset($data['display_price']) || (int) $data['display_price'] < 0) {
            return Result::failure('판매가를 올바르게 입력해주세요.');
        }

        // 카테고리 존재 확인
        if (!empty($data['category_code'])) {
            if (!$this->categoryRepository->existsByCategoryCode($domainId, $data['category_code'])) {
                return Result::failure('선택한 카테고리가 존재하지 않습니다.');
            }
        }

        $db = $this->productRepository->getDb();

        try {
            $db->beginTransaction();

            // 1) 상품 기본 정보 생성
            $productData = $this->normalizeProductData($data);
            $productData['domain_id'] = $domainId;

            // item_code 자동 생성 (미입력 시)
            if (empty($productData['item_code'])) {
                $productData['item_code'] = $this->generateItemCode();
            }

            $goodsId = $this->productRepository->create($productData);

            if (!$goodsId) {
                $db->rollBack();
                return Result::failure('상품 생성에 실패했습니다.');
            }

            // 2) 이미지 저장
            $images = $data['images'] ?? [];
            if (!empty($images)) {
                $this->saveImages($goodsId, $images);
            }

            // 3) 옵션 저장
            $options = $data['options'] ?? [];
            if (!empty($options)) {
                $this->saveOptions($goodsId, $options);
            }

            // 4) 옵션 조합 저장
            $combos = $data['combos'] ?? [];
            if (!empty($combos)) {
                $this->saveCombos($goodsId, $combos);
            }

            // 5) 상세정보 저장 (에디터 이미지 처리 포함)
            $details = $data['details'] ?? [];
            if (!empty($details)) {
                $storagePath = $data['_storage_path'] ?? '';
                $this->saveDetails($goodsId, $details, $storagePath, $domainId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Shop ProductService::create] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Result::failure('상품 등록 중 오류가 발생했습니다: ' . $e->getMessage());
        }

        $this->dispatchAfterCommit(
            new ProductChangedEvent($domainId, [(int) $goodsId], ProductChangedEvent::CREATED),
            'create'
        );

        return Result::success('상품이 등록되었습니다.', ['goods_id' => $goodsId]);
    }

    /**
     * 상품 수정
     *
     * @param int $goodsId 상품 ID
     * @param array $data 수정 데이터
     * @param int $domainId 현재 도메인 ID
     * @return Result
     */
    public function update(int $goodsId, array $data, int $domainId): Result
    {
        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('상품을 찾을 수 없습니다.');
        }

        // 카테고리 변경 시 존재 확인
        if (!empty($data['category_code'])) {
            if (!$this->categoryRepository->existsByCategoryCode($domainId, $data['category_code'])) {
                return Result::failure('선택한 카테고리가 존재하지 않습니다.');
            }
        }

        $db = $this->productRepository->getDb();

        try {
            $db->beginTransaction();

            // 1) 상품 기본 정보 수정
            $productData = $this->normalizeProductData($data);
            unset($productData['domain_id']); // 도메인 변경 불가

            if (!empty($productData)) {
                $this->productRepository->updateInDomain($domainId, $goodsId, $productData);
            }

            // 2) 이미지 수정 (전달된 경우만)
            if (isset($data['images'])) {
                $this->productRepository->deleteImages($goodsId);
                if (!empty($data['images'])) {
                    $this->saveImages($goodsId, $data['images']);
                }
            }

            // 3) 옵션 + 조합 수정 (전달된 경우만, delete + recreate)
            if (isset($data['options']) || isset($data['combos'])) {
                // 기존 옵션 + 값 + 조합 일괄 삭제
                $this->productOptionRepository->deleteProductOptions($goodsId);

                // 새 옵션 생성
                if (!empty($data['options'])) {
                    $this->saveOptions($goodsId, $data['options']);
                }

                // 새 조합 생성
                if (!empty($data['combos'])) {
                    $this->saveCombos($goodsId, $data['combos']);
                }
            }

            // 5) 상세정보 수정 (전달된 경우만, 에디터 이미지 처리 포함)
            if (isset($data['details'])) {
                $this->productRepository->deleteDetails($goodsId);
                if (!empty($data['details'])) {
                    $storagePath = $data['_storage_path'] ?? '';
                    $this->saveDetails($goodsId, $data['details'], $storagePath, $domainId);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Shop ProductService::update] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Result::failure('상품 수정 중 오류가 발생했습니다: ' . $e->getMessage());
        }

        $this->dispatchAfterCommit(
            new ProductChangedEvent($domainId, [$goodsId], ProductChangedEvent::UPDATED),
            'update'
        );

        return Result::success('상품이 수정되었습니다.');
    }

    /**
     * 상품 삭제
     *
     * @param int $goodsId 상품 ID
     * @param int $domainId 현재 도메인 ID
     * @return Result
     */
    public function delete(int $goodsId, int $domainId): Result
    {
        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('상품을 찾을 수 없습니다.');
        }

        $db = $this->productRepository->getDb();

        try {
            $db->beginTransaction();

            // 1) 옵션 + 값 + 조합 일괄 삭제
            $this->productOptionRepository->deleteProductOptions($goodsId);

            // 2) 이미지 삭제
            $this->productRepository->deleteImages($goodsId);

            // 5) 상세정보 삭제
            $this->productRepository->deleteDetails($goodsId);

            // 6) 상품 삭제
            $this->productRepository->deleteInDomain($domainId, $goodsId);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Result::failure('상품 삭제 중 오류가 발생했습니다.');
        }

        $this->dispatchAfterCommit(
            new ProductChangedEvent($domainId, [$goodsId], ProductChangedEvent::DELETED),
            'delete'
        );

        return Result::success('상품이 삭제되었습니다.');
    }

    /**
     * 상품 일괄 삭제
     *
     * @param array $goodsIds 삭제할 상품 ID 배열
     * @param int $domainId 도메인 ID (도메인 경계 검증용)
     * @return Result
     */
    public function deleteMultiple(array $goodsIds, int $domainId): Result
    {
        if (empty($goodsIds)) {
            return Result::failure('삭제할 상품이 선택되지 않았습니다.');
        }

        $goodsIds = array_map('intval', $goodsIds);

        // 도메인 경계 검증: 모든 상품이 해당 도메인에 속하는지 확인
        $verifiedIds = $this->productRepository->filterByDomain($goodsIds, $domainId);
        if (count($verifiedIds) !== count($goodsIds)) {
            return Result::failure('삭제 권한이 없는 상품이 포함되어 있습니다.');
        }

        $db = $this->productRepository->getDb();

        try {
            $db->beginTransaction();

            // 연관 데이터 삭제 (옵션값 + 옵션 + 조합)
            foreach ($goodsIds as $goodsId) {
                $this->productOptionRepository->deleteProductOptions($goodsId);
            }

            // 이미지/상세 일괄 삭제
            $db->table('shop_product_images')->whereIn('goods_id', $goodsIds)->delete();
            $db->table('shop_product_details')->whereIn('goods_id', $goodsIds)->delete();

            // 상품 일괄 삭제
            $deleted = $this->productRepository->deleteMultiple($domainId, $goodsIds);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Result::failure('상품 삭제 중 오류가 발생했습니다.');
        }

        $this->dispatchAfterCommit(
            new ProductChangedEvent($domainId, $goodsIds, ProductChangedEvent::DELETED),
            'deleteMultiple'
        );

        return Result::success("{$deleted}개 상품이 삭제되었습니다.");
    }

    /**
     * 상품 가격 계산 (할인/적립 포함)
     *
     * PriceCalculator를 사용하여 계산
     *
     * @param array $product 상품 데이터 배열 (display_price, discount_type, discount_value, reward_type, reward_value)
     * @param array $shopConfig 쇼핑몰 설정 배열
     * @return array ['sales_price', 'discount_amount', 'discount_percent', 'point_amount', 'reward_percent']
     */
    public function calculateProductPrice(array $product, array $shopConfig = []): array
    {
        $displayPrice = (int) ($product['display_price'] ?? 0);
        $discountType = DiscountType::tryFrom($product['discount_type'] ?? 'NONE') ?? DiscountType::NONE;
        $discountValue = (float) ($product['discount_value'] ?? 0);
        $rewardType = RewardType::tryFrom($product['reward_type'] ?? 'NONE') ?? RewardType::NONE;
        $rewardValue = (float) ($product['reward_value'] ?? 0);

        // 할인 계산
        $priceResult = $this->priceCalculator->calculateSalesPrice(
            $displayPrice,
            $discountType,
            $discountValue,
            $shopConfig
        );

        // 적립 계산 (할인 적용 후 가격 기준)
        $rewardResult = $this->priceCalculator->calculateRewardPoints(
            $priceResult['sales_price'],
            $rewardType,
            $rewardValue,
            $shopConfig
        );

        return array_merge($priceResult, $rewardResult);
    }

    // =========================================================================
    // Private Helper Methods
    // =========================================================================

    /**
     * 상품 데이터 정규화
     *
     * @param array $data 입력 데이터
     * @return array 정규화된 데이터
     */
    private function normalizeProductData(array $data): array
    {
        $normalized = [];

        // 문자열 필드
        $stringFields = [
            'category_code', 'category_code_extra', 'item_code',
            'goods_name', 'goods_slug', 'goods_origin', 'goods_manufacturer',
            'goods_code', 'goods_badge', 'goods_icon', 'goods_filter', 'goods_tags',
            'shipping_apply_type', 'seller_id', 'supply_id',
        ];
        foreach ($stringFields as $field) {
            // array_key_exists 사용: 값이 null(빈 선택)이어도 UPDATE에 NULL로 반영되도록.
            // isset()은 null을 false 처리해 컬럼이 쿼리에서 누락 → 기존 값이 유지되는 버그가 있었음.
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $normalized[$field] = ($value === '') ? null : $value;
            }
        }

        // 숫자 필드
        $numericFields = [
            'origin_price', 'display_price', 'discount_value', 'reward_value',
            'reward_review', 'shipping_template_id',
        ];
        foreach ($numericFields as $field) {
            if (isset($data[$field])) {
                $normalized[$field] = is_float($data[$field]) || str_contains((string) $data[$field], '.')
                    ? (float) $data[$field]
                    : (int) $data[$field];
            }
        }

        // stock_quantity: 빈값/미입력 → NULL (미관리), 숫자 → 해당 값
        if (array_key_exists('stock_quantity', $data)) {
            $val = $data['stock_quantity'];
            $normalized['stock_quantity'] = ($val === '' || $val === null) ? null : (int) $val;
        }

        // Enum 필드
        if (isset($data['discount_type'])) {
            $discountType = DiscountType::tryFrom($data['discount_type']);
            $normalized['discount_type'] = $discountType ? $discountType->value : DiscountType::NONE->value;
        }
        if (isset($data['reward_type'])) {
            $rewardType = RewardType::tryFrom($data['reward_type']);
            $normalized['reward_type'] = $rewardType ? $rewardType->value : RewardType::NONE->value;
        }
        if (isset($data['option_mode'])) {
            $normalized['option_mode'] = $data['option_mode'];
        }

        // 등급별 할인/적립 설정 (JSON)
        if (isset($data['discount_level_settings'])) {
            $normalized['discount_level_settings'] = is_string($data['discount_level_settings'])
                ? $data['discount_level_settings']
                : json_encode($data['discount_level_settings'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['reward_level_settings'])) {
            $normalized['reward_level_settings'] = is_string($data['reward_level_settings'])
                ? $data['reward_level_settings']
                : json_encode($data['reward_level_settings'], JSON_UNESCAPED_UNICODE);
        }

        // Boolean 필드
        if (isset($data['allowed_coupon'])) {
            $normalized['allowed_coupon'] = (int) (bool) $data['allowed_coupon'];
        }
        if (isset($data['is_active'])) {
            $normalized['is_active'] = (int) (bool) $data['is_active'];
        }

        // 슬러그 자동 생성: 비어있으면 상품명으로 생성
        if (empty($normalized['goods_slug']) && !empty($normalized['goods_name'] ?? $data['goods_name'] ?? '')) {
            $name = $normalized['goods_name'] ?? $data['goods_name'];
            $normalized['goods_slug'] = $this->generateSlug($name);
        }

        return $normalized;
    }

    /**
     * 상품 이미지 저장
     *
     * @param int $goodsId 상품 ID
     * @param array $images 이미지 배열
     */
    private function saveImages(int $goodsId, array $images): void
    {
        foreach ($images as $sortIndex => $image) {
            $imageData = [
                'goods_id' => $goodsId,
                'image_url' => $image['image_url'] ?? '',
                'thumbnail_url' => $image['thumbnail_url'] ?? null,
                'webp_url' => $image['webp_url'] ?? null,
                'is_main' => isset($image['is_main']) ? (int) (bool) $image['is_main'] : ($sortIndex === 0 ? 1 : 0),
                'sort_order' => (int) ($image['sort_order'] ?? $sortIndex),
            ];
            $this->productRepository->createImage($imageData);
        }
    }

    /**
     * 상품 옵션 + 값 저장
     *
     * @param int $goodsId 상품 ID
     * @param array $options 옵션 배열
     */
    private function saveOptions(int $goodsId, array $options): void
    {
        foreach ($options as $sortIndex => $option) {
            $optionData = [
                'option_name' => $option['option_name'] ?? '',
                'option_type' => $option['option_type'] ?? 'BASIC',
                'is_required' => isset($option['is_required']) ? (int) (bool) $option['is_required'] : 1,
                'sort_order' => (int) ($option['sort_order'] ?? $sortIndex),
            ];
            $optionId = $this->productOptionRepository->createOption($goodsId, $optionData);

            if ($optionId && !empty($option['values'])) {
                foreach ($option['values'] as $valIndex => $value) {
                    $valStock = $value['stock_quantity'] ?? null;
                    $valueData = [
                        'value_name' => $value['value_name'] ?? '',
                        'extra_price' => (int) ($value['extra_price'] ?? 0),
                        'stock_quantity' => ($valStock === '' || $valStock === null) ? null : (int) $valStock,
                        'is_active' => isset($value['is_active']) ? (int) (bool) $value['is_active'] : 1,
                        'sort_order' => (int) ($value['sort_order'] ?? $valIndex),
                    ];
                    $this->productOptionRepository->createValue($optionId, $valueData);
                }
            }
        }
    }

    /**
     * 상품 옵션 조합 저장
     *
     * @param int $goodsId 상품 ID
     * @param array $combos 조합 배열
     */
    private function saveCombos(int $goodsId, array $combos): void
    {
        foreach ($combos as $combo) {
            $comboStock = $combo['stock_quantity'] ?? null;
            $comboData = [
                'combination_key' => $combo['combination_key'] ?? '',
                'extra_price' => (int) ($combo['extra_price'] ?? 0),
                'stock_quantity' => ($comboStock === '' || $comboStock === null) ? null : (int) $comboStock,
                'is_active' => isset($combo['is_active']) ? (int) (bool) $combo['is_active'] : 1,
            ];
            $this->productOptionRepository->createCombo($goodsId, $comboData);
        }
    }

    /**
     * 상품 상세정보 저장 (에디터 이미지 처리 포함)
     *
     * 에디터 내 이미지를 temp → storage/D{domainId}/{storagePath}/ 로 이동
     * 갤러리 이미지와 동일한 폴더에 통합 저장
     *
     * @param int $goodsId 상품 ID
     * @param array $details 상세정보 배열 [idx => ['detail_type' => ..., 'detail_value' => ...]]
     * @param string $storagePath 상품별 저장 경로 (예: 'shop/product/FOOD/G-20260210-0001')
     * @param int $domainId 도메인 ID
     */
    private function saveDetails(int $goodsId, array $details, string $storagePath = '', int $domainId = 0): void
    {
        // 에디터 이미지 경로: configureForDomain()에 의해 D{domainId} 기반
        // 커스텀 storagePath가 있으면 targetFolder로 사용, 없으면 날짜 기반 폴더
        $targetBasePath = null;
        $targetBaseUrl = null;

        if ($storagePath) {
            $targetFolder = $storagePath;
        } else {
            $targetFolder = 'shop/product/' . date('Y/m');
        }

        foreach ($details as $sortIndex => $detail) {
            $detailValue = $detail['detail_value'] ?? '';

            // 빈 상세정보 스킵
            if (trim(strip_tags($detailValue)) === '' && !preg_match('/<img\s/i', $detailValue)) {
                continue;
            }

            // 에디터 이미지 처리: temp → 영구 저장소 이동
            $detailValue = EditorHelper::processImages($detailValue, $targetFolder, $targetBasePath, $targetBaseUrl);

            $detailData = [
                'detail_type' => $detail['detail_type'] ?? 'description',
                'detail_value' => $detailValue,
                'lang_code' => $detail['lang_code'] ?? 'ko',
                'sort_order' => (int) ($detail['sort_order'] ?? $sortIndex),
            ];

            $this->productRepository->saveDetail($goodsId, $detailData);
        }
    }

    /**
     * 상품명으로 URL 슬러그 생성
     *
     * 한글/영문/숫자 유지, 공백→하이픈, 특수문자 제거
     * 예: "프리미엄 코튼 티셔츠" → "프리미엄-코튼-티셔츠"
     * 예: "Mublo Book Pro 15" → "mublo-book-pro-15"
     */
    private function generateSlug(string $name): string
    {
        $slug = mb_strtolower(trim($name));

        // 한글, 영문, 숫자, 공백, 하이픈만 유지
        $slug = preg_replace('/[^\p{Hangul}a-z0-9\s\-]/u', '', $slug);

        // 공백 → 하이픈
        $slug = preg_replace('/\s+/', '-', $slug);

        // 연속 하이픈 → 단일 하이픈
        $slug = preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    /** 상품코드 충돌 시 다음 번호를 시도하는 횟수 */
    private const ITEM_CODE_MAX_ATTEMPTS = 20;

    /**
     * 상품 코드 자동 생성
     *
     * 상품을 만들기 전에 저장 경로(shop/product/{category}/{item_code})를 정해야 하는
     * 화면이 있어 public 이다 — 그 자리에서 만든 코드를 그대로 create() 에 넘기면
     * 경로와 저장된 코드가 어긋나지 않는다.
     *
     * item_code 는 전역 유니크라 충돌하면 상품 생성이 통째로 실패한다. 4자리 난수로
     * 뽑던 때는 생일 문제로 하루 118건이면 충돌 확률이 50%에 달했으므로, 그날의
     * 마지막 번호 다음을 쓰고 이미 있으면 다음 번호로 넘어간다.
     *
     * @return string 상품 코드 (예: G-20260207-0001)
     */
    public function generateItemCode(): string
    {
        $prefix = 'G-' . date('Ymd') . '-';
        $next = $this->nextItemCodeSequence($prefix);

        for ($i = 0; $i < self::ITEM_CODE_MAX_ATTEMPTS; $i++) {
            $candidate = $prefix . str_pad((string) ($next + $i), 4, '0', STR_PAD_LEFT);
            if (!$this->productRepository->itemCodeExists($candidate)) {
                return $candidate;
            }
        }

        // 여기까지 왔다면 동시 생성이 몰린 상황 — 겹칠 자리를 넓혀 한 번 더 시도한다
        return $prefix . str_pad((string) ($next + random_int(self::ITEM_CODE_MAX_ATTEMPTS, 99999)), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 그날 마지막 상품코드의 다음 시퀀스 (없으면 1)
     */
    private function nextItemCodeSequence(string $prefix): int
    {
        $last = $this->productRepository->maxItemCodeWithPrefix($prefix);
        if ($last === null) {
            return 1;
        }

        $tail = substr($last, strlen($prefix));

        // 옛 난수 코드가 섞여 있어도 숫자로 읽히면 그 다음부터 이어 간다
        return ctype_digit($tail) ? ((int) $tail) + 1 : 1;
    }

    /**
     * 블록 에디터용 활성 상품 목록 (페이징 + 카테고리 + 검색)
     *
     * @param int $domainId 도메인 ID
     * @param array $filters ['category_code' => string, 'keyword' => string]
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['items' => [...], 'pagination' => [...], 'categories' => [...]]
     */
    public function getListForBlock(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        // include_categories는 Service 전용 플래그 → Repository에 전달하지 않음
        $includeCategories = !empty($filters['include_categories']);
        unset($filters['include_categories']);

        // 카테고리 필터: 선택된 카테고리 + 하위 카테고리 모두 포함
        if (!empty($filters['category_code'])) {
            $filters['category_codes'] = $this->categoryRepository->getDescendantCodes(
                $domainId,
                $filters['category_code']
            );
            unset($filters['category_code']);
        }

        $result = $this->productRepository->getActiveListPaginated($domainId, $filters, $page, $perPage);
        $rows = $result['items'] ?? [];

        // 메인 이미지 배치 로드
        $goodsIds = array_column($rows, 'goods_id');
        $mainImages = $this->productRepository->getMainImages($goodsIds);

        $items = array_map(function ($row) use ($mainImages) {
            $goodsId = (int) $row['goods_id'];
            $img = $mainImages[$goodsId] ?? null;

            return [
                'id' => (string) $goodsId,
                'label' => $row['goods_name'] ?? '',
                'main_image_url' => $img['image_url'] ?? '',
                'price' => number_format((int) ($row['display_price'] ?? 0)) . '원',
            ];
        }, $rows);

        // 카테고리 트리 (includeCategories 플래그가 있을 때)
        $categories = [];
        if ($includeCategories) {
            $categories = $this->getCategoryTreeForBlock($domainId);
        }

        return [
            'items' => $items,
            'pagination' => $result['pagination'],
            'categories' => $categories,
        ];
    }

    /**
     * 블록 에디터용 카테고리 트리
     *
     * 단계별 연동 셀렉트를 위해 path_code, parent_code 포함
     * - parent_code: 부모의 path_code (루트는 null)
     * - path_code: 자신의 경로 코드 (자식 매칭에 사용)
     *
     * @return array [['code' => string, 'name' => string, 'path_code' => string, 'parent_code' => string|null], ...]
     */
    private function getCategoryTreeForBlock(int $domainId): array
    {
        $tree = $this->categoryRepository->getTreeWithItems($domainId);

        return array_map(function ($node) {
            return [
                'code' => $node['category_code'] ?? '',
                'name' => $node['name'] ?? '',
                'path_code' => $node['path_code'] ?? '',
                'parent_code' => $node['parent_code'] ?? null,
            ];
        }, $tree);
    }
}
