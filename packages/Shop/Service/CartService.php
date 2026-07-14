<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Session\SessionManager;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Service\DirectBuyService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;

/**
 * CartService
 *
 * 장바구니 비즈니스 로직
 *
 * 책임:
 * - 장바구니 아이템 추가/수정/삭제
 * - 멀티옵션 처리 (NONE/SINGLE/COMBINATION + EXTRA)
 * - 중복 처리 (upsert: 같은 상품+옵션 → 수량 증가)
 * - 장바구니 요약 정보 계산
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class CartService
{
    private CartRepository $cartRepository;
    private ProductRepository $productRepository;
    private ProductOptionRepository $productOptionRepository;
    private PriceCalculator $priceCalculator;
    private ShippingFeeCalculator $shippingFeeCalculator;
    private DirectBuyService $directBuyService;
    private ?SessionManager $sessionManager;

    public function __construct(
        CartRepository $cartRepository,
        ProductRepository $productRepository,
        ProductOptionRepository $productOptionRepository,
        PriceCalculator $priceCalculator,
        ShippingFeeCalculator $shippingFeeCalculator,
        DirectBuyService $directBuyService,
        ?SessionManager $sessionManager = null
    ) {
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->productOptionRepository = $productOptionRepository;
        $this->priceCalculator = $priceCalculator;
        $this->shippingFeeCalculator = $shippingFeeCalculator;
        $this->directBuyService = $directBuyService;
        $this->sessionManager = $sessionManager;
    }

    // =========================================================================
    // 장바구니 조회
    // =========================================================================

    /**
     * 장바구니 목록 조회
     */
    public function getCartList(string $sessionId, int $memberId, int $domainId): Result
    {
        $cartItems = $this->cartRepository->getItems($sessionId, $memberId, $domainId);

        $items = [];
        $goodsIds = [];
        foreach ($cartItems as $cartItem) {
            $goodsIds[] = $cartItem->getGoodsId();
        }

        $uniqueIds = array_unique($goodsIds);

        // 상품 + 이미지 배치 로드 (N+1 방지)
        $mainImages = !empty($uniqueIds)
            ? $this->productRepository->getMainImages($uniqueIds)
            : [];
        $products = [];
        foreach ($this->productRepository->findByIdsForDomain($domainId, $uniqueIds) as $p) {
            $products[$p->getGoodsId()] = $p;
        }

        foreach ($cartItems as $cartItem) {
            $product = $products[$cartItem->getGoodsId()] ?? null;
            if (!$product) {
                continue;
            }

            $itemData = $cartItem->toArray();
            $itemData['product'] = $product->toArray();
            $itemData['product_image'] = $mainImages[$cartItem->getGoodsId()] ?? null;

            // 옵션 라벨
            if ($cartItem->hasOption() && $cartItem->getOptionId() > 0) {
                $optionModeValue = $cartItem->getOptionMode()->value ?? (string) $cartItem->getOptionMode();
                $itemData['option_label'] = $this->resolveOptionLabel(
                    $cartItem->getGoodsId(),
                    $cartItem->getOptionId(),
                    $optionModeValue,
                    $cartItem->getOptionCode()
                );
            }

            $stock = $this->resolveCartItemStock($cartItem, $product);
            $itemData['is_available'] = $product->isActive()
                && ($stock === null || $stock >= $cartItem->getQuantity());

            $items[] = $itemData;
        }

        return Result::success('', ['items' => $items]);
    }

    /**
     * getItems() 별칭 (Controller 호환)
     */
    public function getItems(string $sessionId, int $memberId, int $domainId): Result
    {
        return $this->getCartList($sessionId, $memberId, $domainId);
    }

    // =========================================================================
    // 그룹화된 장바구니 조회 (배송템플릿별 → 상품별)
    // =========================================================================

    /**
     * 배송 템플릿별 → 상품별 2단계 그룹핑된 장바구니 목록 조회
     *
     * 반환 구조:
     * - groups[groupKey] = { template_id, template_name, shipping_fee, goods[goodsId] = { goods_info, options[], extras[] } }
     * - totals = { itemTotal, shippingTotal, pointTotal, grandTotal }
     * - productData[goodsId] = { 옵션 모달용 상품 데이터 }
     */
    public function getGroupedCartList(string $sessionId, int $memberId, int $domainId): Result
    {
        $cartItems = $this->cartRepository->getItems($sessionId, $memberId, $domainId);

        if (empty($cartItems)) {
            return Result::success('', [
                'groups' => [],
                'totals' => ['itemTotal' => 0, 'shippingTotal' => 0, 'pointTotal' => 0, 'grandTotal' => 0],
                'productData' => [],
            ]);
        }

        // 상품 ID 수집 및 상품 정보/이미지 로드
        $goodsIds = array_unique(array_map(fn($item) => $item->getGoodsId(), $cartItems));
        $mainImages = $this->productRepository->getMainImages($goodsIds);

        $products = [];
        foreach ($this->productRepository->findByIdsForDomain($domainId, $goodsIds) as $p) {
            $products[$p->getGoodsId()] = $p;
        }

        // 도메인 ID (첫 상품 기준) + 쇼핑몰 기본 배송 템플릿
        $defaultTemplateId = $this->shippingFeeCalculator->resolveDefaultTemplateId($domainId);

        // 배송비 계산 (단일 진실 원천) — 라인 구성 후 위임
        $lines = [];
        foreach ($cartItems as $cartItem) {
            $gid = $cartItem->getGoodsId();
            $product = $products[$gid] ?? null;
            if (!$product) {
                continue;
            }
            $lines[] = [
                'goods_id'             => $gid,
                'shipping_template_id' => $product->getShippingTemplateId(),
                'shipping_apply_type'  => $product->getShippingApplyType(),
                'total_price'          => $cartItem->getTotalPrice(),
                'quantity'             => $cartItem->getQuantity(),
            ];
        }
        $shippingResult = $this->shippingFeeCalculator->calculate($domainId, $lines);
        $shippingGroups = $shippingResult['groups'];

        // Phase 1: 배송 그룹별 1차 그룹화 (계산기와 동일한 그룹 키 사용)
        $rawGroups = [];
        foreach ($cartItems as $cartItem) {
            $gid = $cartItem->getGoodsId();
            $product = $products[$gid] ?? null;
            if (!$product) {
                continue;
            }

            // 상품 템플릿 → (없으면) 기본 템플릿
            $templateId = (int) ($product->getShippingTemplateId() ?? 0);
            if ($templateId <= 0) {
                $templateId = $defaultTemplateId;
            }
            $groupKey = ShippingFeeCalculator::groupKey($templateId, $product->getShippingApplyType(), $gid);

            if (!isset($rawGroups[$groupKey])) {
                $rawGroups[$groupKey] = ['items' => []];
            }
            $rawGroups[$groupKey]['items'][] = $cartItem;
        }

        // Phase 2: 그룹별 상품 세분화 + 배송비(계산기 결과) 매핑
        $groups = [];
        $totalItemPrice = 0;
        $totalPoint = 0;
        $productDataIds = [];
        $salesPriceCache = []; // goodsId => 현재 판매가 (가격변동 감지용, 상품당 1회 계산)

        foreach ($rawGroups as $groupKey => $rawGroup) {
            $groupInfo = $shippingGroups[$groupKey] ?? null;

            // 그룹 내 상품별 정리
            $goodsMap = [];

            foreach ($rawGroup['items'] as $cartItem) {
                $gid = $cartItem->getGoodsId();
                $product = $products[$gid] ?? null;
                if (!$product) {
                    continue;
                }

                $productDataIds[$gid] = true;

                if (!isset($goodsMap[$gid])) {
                    $goodsMap[$gid] = [
                        'goods_info' => [
                            'goods_id' => $gid,
                            'goods_name' => $product->getGoodsName(),
                            'product_image' => $mainImages[$gid] ?? null,
                            'base_price' => $product->getDisplayPrice(),
                            'option_mode' => $product->getOptionMode()->value,
                            // 옵션상품은 상품레벨 재고로 품절 판정하지 않음(옵션/조합 단위 재고는 별도) → 헛품절 방지
                            'is_available' => $product->isActive()
                                && ($product->getOptionMode()->hasOptions() || $product->isInStock()),
                        ],
                        'options' => [],
                        'extras' => [],
                        'total_quantity' => 0,
                    ];
                }

                // 옵션 라벨 해석
                $optionLabel = null;
                $optionModeValue = $cartItem->getOptionMode()->value;
                if ($cartItem->hasOption() && $cartItem->getOptionId() > 0) {
                    $optionLabel = $this->resolveOptionLabel(
                        $gid, $cartItem->getOptionId(),
                        $optionModeValue, $cartItem->getOptionCode()
                    );
                }

                // 가격변동 감지: 담을 당시 박제가(goods_price) vs 현재 판매가.
                // 추가옵션(EXTRA)은 상품 본가가 아니므로 대상에서 제외(prepareCheckout과 동일 기준).
                $isExtra = $cartItem->getOptionType()->value === 'EXTRA';
                $currentGoodsPrice = $cartItem->getGoodsPrice();
                $priceChanged = false;
                if (!$isExtra) {
                    $currentGoodsPrice = $salesPriceCache[$gid]
                        ??= $this->priceCalculator->calculateSalesPrice(
                            $product->getDisplayPrice(),
                            $product->getDiscountType(),
                            $product->getDiscountValue()
                        )['sales_price'];
                    $priceChanged = $cartItem->getGoodsPrice() !== $currentGoodsPrice;
                }

                $itemRow = [
                    'cart_item_id' => $cartItem->getCartItemId(),
                    'option_label' => $optionLabel,
                    'option_code' => $cartItem->getOptionCode(),
                    'option_id' => $cartItem->getOptionId(),
                    'option_mode' => $optionModeValue,
                    'option_type' => $cartItem->getOptionType()->value,
                    'quantity' => $cartItem->getQuantity(),
                    'goods_price' => $cartItem->getGoodsPrice(),
                    'option_price' => $cartItem->getOptionPrice(),
                    'total_price' => $cartItem->getTotalPrice(),
                    'point_amount' => $cartItem->getPointAmount(),
                    'price_changed' => $priceChanged,
                    'current_goods_price' => $currentGoodsPrice,
                    'current_total_price' => ($currentGoodsPrice + $cartItem->getOptionPrice()) * $cartItem->getQuantity(),
                ];

                if ($isExtra) {
                    $goodsMap[$gid]['extras'][] = $itemRow;
                } else {
                    $goodsMap[$gid]['options'][] = $itemRow;
                }

                $goodsMap[$gid]['total_quantity'] += $cartItem->getQuantity();
                $totalItemPrice += $cartItem->getTotalPrice();
                $totalPoint += $cartItem->getPointAmount();
            }

            $groups[$groupKey] = [
                'template_id'     => $groupInfo['template_id'] ?? null,
                'template_name'   => $groupInfo['template_name'] ?? '기본배송',
                'shipping_fee'    => $groupInfo['shipping_fee'] ?? null,
                'unresolved'      => $groupInfo['unresolved'] ?? false,
                'shipping_method' => $groupInfo['shipping_method'] ?? null,
                'free_threshold'  => $groupInfo['free_threshold'] ?? 0,
                'group_total'     => $groupInfo['group_total'] ?? 0,
                'goods'           => $goodsMap,
            ];
        }

        // 옵션 모달용 상품 데이터 준비
        $productData = [];
        foreach (array_keys($productDataIds) as $gid) {
            $product = $products[$gid] ?? null;
            if (!$product) {
                continue;
            }

            $optionMode = $product->getOptionMode()->value;
            if ($optionMode === 'NONE') {
                continue;
            }

            $priceResult = $this->priceCalculator->calculateSalesPrice(
                $product->getDisplayPrice(),
                $product->getDiscountType(),
                $product->getDiscountValue()
            );

            // getByProduct()는 [{ option: ProductOption entity, values: [...] }] 형태
            // ShopProductOption.js는 [{ option_id, option_name, ..., values: [...] }] 형태 필요
            $rawOptions = $this->productOptionRepository->getByProduct($gid);
            $jsOptions = [];
            foreach ($rawOptions as $raw) {
                $opt = $raw['option'] instanceof \Mublo\Packages\Shop\Entity\ProductOption
                    ? $raw['option']->toArray()
                    : (array) $raw['option'];
                $opt['values'] = $raw['values'] ?? [];
                $jsOptions[] = $opt;
            }

            $productData[$gid] = [
                'goods_id' => $gid,
                'goods_name' => $product->getGoodsName(),
                'sales_price' => $priceResult['sales_price'],
                'option_mode' => $optionMode,
                'options' => $jsOptions,
                'combos' => $optionMode === 'COMBINATION'
                    ? $this->productOptionRepository->getCombos($gid)
                    : [],
            ];
        }

        return Result::success('', [
            'groups' => $groups,
            'totals' => [
                'itemTotal' => $totalItemPrice,
                'shippingTotal' => $shippingResult['total'],          // 미설정이면 null
                'pointTotal' => $totalPoint,
                'grandTotal' => $totalItemPrice + (int) ($shippingResult['total'] ?? 0),
                'unresolved' => $shippingResult['unresolved'],
            ],
            'productData' => $productData,
        ]);
    }

    // =========================================================================
    // 옵션 수정 (장바구니 내에서)
    // =========================================================================

    /**
     * 장바구니 내 단일 항목의 옵션 변경
     *
     * 지정 cart_item만 삭제 후 새 옵션으로 재삽입.
     * 동일 상품의 다른 옵션 행에는 영향을 주지 않음.
     */
    public function updateOptionByCartItem(
        int $cartItemId,
        string $cartSessionId,
        int $memberId,
        int $domainId,
        array $data
    ): Result {
        if ($cartItemId <= 0) {
            return Result::failure('잘못된 요청입니다.');
        }

        $existing = $this->cartRepository->findInDomain($domainId, $cartItemId);
        if (!$existing) {
            return Result::failure('장바구니 항목을 찾을 수 없습니다.');
        }

        $isOwner = ($cartSessionId !== '' && $existing->getCartSessionId() === $cartSessionId)
            || ($memberId > 0 && $existing->getMemberId() === $memberId);
        if (!$isOwner) {
            return Result::failure('장바구니에 접근할 수 없습니다.');
        }

        $goodsId = $existing->getGoodsId();
        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('존재하지 않는 상품입니다.');
        }
        if (!$product->isActive()) {
            return Result::failure('현재 판매 중인 상품이 아닙니다.');
        }

        $priceResult = $this->priceCalculator->calculateSalesPrice(
            $product->getDisplayPrice(),
            $product->getDiscountType(),
            $product->getDiscountValue()
        );
        $salesPrice = $priceResult['sales_price'];

        $data['goods_id'] = $goodsId;
        $newItems = $this->buildCartItems($data, $product, $salesPrice);
        if (empty($newItems)) {
            return Result::failure('옵션을 선택해주세요.');
        }

        $sessionForInsert = $existing->getCartSessionId() !== ''
            ? $existing->getCartSessionId()
            : $cartSessionId;
        $memberForInsert = $existing->getMemberId() > 0
            ? $existing->getMemberId()
            : $memberId;

        $db = $this->cartRepository->getDb();
        $db->beginTransaction();
        try {
            $this->cartRepository->removeItem($cartItemId, $domainId);

            foreach ($newItems as $item) {
                $item['cart_session_id'] = $sessionForInsert;
                $item['member_id'] = $memberForInsert;
                $item['domain_id'] = $domainId;
                $item['cart_status'] = 'PENDING';

                $this->cartRepository->addItem($item);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('옵션 변경 중 오류가 발생했습니다.');
        }

        $this->saveCartShippingFee($cartSessionId, $memberId, $domainId, $goodsId, $product);

        return Result::success('옵션이 변경되었습니다.');
    }

    /**
     * 장바구니 내 옵션 수정 (상품 단위 일괄 교체)
     *
     * 기존 상품 항목 전체 삭제 → 새 옵션으로 재삽입
     */
    public function updateOption(string $cartSessionId, int $memberId, int $domainId, array $data): Result
    {
        $goodsId = (int) ($data['goods_id'] ?? 0);

        if ($goodsId <= 0) {
            return Result::failure('상품 정보가 올바르지 않습니다.');
        }

        // 소유권 검증: 식별자 없으면 거부
        if ($cartSessionId === '' && $memberId <= 0) {
            return Result::failure('장바구니에 접근할 수 없습니다.');
        }

        // 소유권 사전 확인: 해당 상품이 내 장바구니에 있는지
        $existingItems = $this->cartRepository->getItems($cartSessionId, $memberId, $domainId);
        $hasOwned = false;
        foreach ($existingItems as $item) {
            if ($item->getGoodsId() === $goodsId) {
                $hasOwned = true;
                break;
            }
        }
        if (!$hasOwned) {
            return Result::failure('장바구니에 해당 상품이 없습니다.');
        }

        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('존재하지 않는 상품입니다.');
        }
        if (!$product->isActive()) {
            return Result::failure('현재 판매 중인 상품이 아닙니다.');
        }

        $priceResult = $this->priceCalculator->calculateSalesPrice(
            $product->getDisplayPrice(),
            $product->getDiscountType(),
            $product->getDiscountValue()
        );
        $salesPrice = $priceResult['sales_price'];

        // 새 옵션 데이터로 DB 행 빌드
        $cartItems = $this->buildCartItems($data, $product, $salesPrice);
        if (empty($cartItems)) {
            return Result::failure('옵션을 선택해주세요.');
        }

        // 트랜잭션: 기존 항목 삭제 → 새 항목 삽입 (원자성 보장)
        $db = $this->cartRepository->getDb();
        $db->beginTransaction();
        try {
            $this->cartRepository->removeByGoodsId($cartSessionId, $memberId, $goodsId);

            foreach ($cartItems as $item) {
                $item['cart_session_id'] = $cartSessionId;
                $item['member_id'] = $memberId;
                $item['domain_id'] = $domainId;
                $item['cart_status'] = 'PENDING';

                $this->cartRepository->addItem($item);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('옵션 변경 중 오류가 발생했습니다.');
        }

        // 배송비 정보 갱신
        $this->saveCartShippingFee($cartSessionId, $memberId, $domainId, $goodsId, $product);

        return Result::success('옵션이 변경되었습니다.');
    }

    // =========================================================================
    // 장바구니 담기 (cart) / 바로구매 (direct)
    // =========================================================================

    /**
     * 장바구니에 아이템 추가 또는 바로구매
     *
     * JS ShopProductOption.getSubmitData() 반환 구조:
     * {
     *   optionMode: 'NONE'|'SINGLE'|'COMBINATION',
     *   quantity: int,
     *   selectedOptions: [{ optionCode, quantity, optionId?, valueId?, comboId? }],
     *   selectedExtras:  [{ optionCode, quantity, optionId?, valueId? }]
     * }
     *
     * Controller가 추가: cart_session_id, member_id, domain_id, goods_id, action
     */
    public function addToCart(array $data): Result
    {
        $sessionId = $data['cart_session_id'] ?? '';
        $memberId = (int) ($data['member_id'] ?? 0);
        $domainId = (int) ($data['domain_id'] ?? 0);
        $goodsId = (int) ($data['goods_id'] ?? 0);
        $action = trim($data['action'] ?? 'cart');

        if ($goodsId <= 0) {
            return Result::failure('상품 정보가 올바르지 않습니다.');
        }

        // 상품 유효성 검증
        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('존재하지 않는 상품입니다.');
        }
        if (!$product->isActive()) {
            return Result::failure('현재 판매 중인 상품이 아닙니다.');
        }
        // 옵션상품은 재고가 옵션/조합에 있으므로 상품레벨 재고로 막지 않는다.
        // (옵션별 재고는 아래 buildCartItems에서 선택 옵션 단위로 검증)
        if (!$product->getOptionMode()->hasOptions() && !$product->isInStock()) {
            return Result::failure('상품의 재고가 부족합니다.');
        }

        // 할인 적용된 판매가
        $priceResult = $this->priceCalculator->calculateSalesPrice(
            $product->getDisplayPrice(),
            $product->getDiscountType(),
            $product->getDiscountValue()
        );
        $salesPrice = $priceResult['sales_price'];

        // JS getSubmitData() → DB 행 배열 변환
        $cartItems = $this->buildCartItems($data, $product, $salesPrice);
        if (empty($cartItems)) {
            // 옵션상품인데 결과가 비면 실제 원인은 '옵션 미선택' → 안내를 명확히.
            // (장바구니/바로구매 공통. 다른 경로(415·500)와 문구 일치)
            // 비옵션은 재고/수량 등 다른 원인이므로 일반 문구 유지.
            return Result::failure(
                $product->getOptionMode()->hasOptions()
                    ? '옵션을 선택해주세요.'
                    : '추가할 상품이 없습니다.'
            );
        }

        // 바로구매
        if ($action === 'direct') {
            return $this->directBuyService->processDirectBuy($sessionId, $memberId, $cartItems, $product);
        }

        // 장바구니 담기 (upsert)
        $addedCount = 0;
        foreach ($cartItems as $item) {
            $item['cart_session_id'] = $sessionId;
            $item['member_id'] = $memberId;
            $item['domain_id'] = $domainId;
            $item['cart_status'] = 'PENDING';

            $existingId = $this->cartRepository->findDuplicate(
                $sessionId, $memberId, $domainId, $goodsId,
                $item['option_id'], $item['option_code']
            );

            if ($existingId) {
                $existing = $this->cartRepository->findInDomain($domainId, $existingId);
                if ($existing) {
                    $newQty = $existing->getQuantity() + $item['quantity'];
                    $this->cartRepository->updateQuantity($existingId, $newQty, $domainId);
                }
            } else {
                $this->cartRepository->addItem($item);
            }
            $addedCount++;
        }

        // 배송비 정보 저장 (shop_cart_fees)
        $this->saveCartShippingFee($sessionId, $memberId, $domainId, $goodsId, $product);

        $summary = $this->getCartSummary($sessionId, $memberId, $domainId);
        $cartCount = $summary->isSuccess() ? $summary->get('totalItems', 0) : 0;

        return Result::success('장바구니에 추가되었습니다.', [
            'addedCount' => $addedCount,
            'cartCount' => $cartCount,
        ]);
    }

    // =========================================================================
    // 수량 변경 / 삭제
    // =========================================================================

    /**
     * 장바구니 아이템 수량 변경
     */
    public function updateQuantity(
        int $cartItemId,
        int $quantity,
        string $cartSessionId,
        int $memberId,
        int $domainId
    ): Result
    {
        if ($quantity <= 0) {
            return Result::failure('수량은 1개 이상이어야 합니다.');
        }

        $cartItem = $this->cartRepository->findInDomain($domainId, $cartItemId);
        if (!$cartItem) {
            return Result::failure('장바구니 아이템을 찾을 수 없습니다.');
        }

        // 소유권 검증: 식별자 없으면 거부, 항상 검증
        if ($cartSessionId === '' && $memberId <= 0) {
            return Result::failure('장바구니 아이템에 접근할 수 없습니다.');
        }
        if (!$this->isCartItemOwner($cartItem, $cartSessionId, $memberId)) {
            return Result::failure('장바구니 아이템에 접근할 수 없습니다.');
        }

        $product = $this->productRepository->findInDomain($domainId, $cartItem->getGoodsId());
        if ($product) {
            $stock = $this->resolveCartItemStock($cartItem, $product);
            if ($stock !== null && $stock < $quantity) {
                return Result::failure(
                    '요청 수량이 재고를 초과합니다. (재고: ' . $stock . '개)'
                );
            }
        }

        $updated = $this->cartRepository->updateQuantity($cartItemId, $quantity, $domainId);
        if (!$updated) {
            return Result::failure('수량 변경에 실패했습니다.');
        }

        return Result::success('수량이 변경되었습니다.');
    }

    /**
     * 장바구니 아이템 삭제
     */
    public function removeItem(int $cartItemId, string $cartSessionId, int $memberId, int $domainId): Result
    {
        $cartItem = $this->cartRepository->findInDomain($domainId, $cartItemId);
        if (!$cartItem) {
            return Result::failure('장바구니 아이템을 찾을 수 없습니다.');
        }

        // 소유권 검증: 식별자 없으면 거부, 항상 검증
        if ($cartSessionId === '' && $memberId <= 0) {
            return Result::failure('장바구니 아이템에 접근할 수 없습니다.');
        }
        if (!$this->isCartItemOwner($cartItem, $cartSessionId, $memberId)) {
            return Result::failure('장바구니 아이템에 접근할 수 없습니다.');
        }

        $removed = $this->cartRepository->removeItem($cartItemId, $domainId);
        if (!$removed) {
            return Result::failure('아이템 삭제에 실패했습니다.');
        }

        return Result::success('장바구니에서 삭제되었습니다.');
    }

    /**
     * 장바구니 아이템 가격을 현재 상품 판매가로 갱신 ("현재가로 담기")
     *
     * 가격변동 항목을 고객이 현재 금액으로 다시 담을 때 사용. 옵션·수량은 유지.
     * 추가옵션(EXTRA)/비활성·품절 상품은 거부한다.
     */
    public function refreshItemPrice(int $cartItemId, string $cartSessionId, int $memberId, int $domainId): Result
    {
        $cartItem = $this->cartRepository->findInDomain($domainId, $cartItemId);
        if (!$cartItem) {
            return Result::failure('장바구니 아이템을 찾을 수 없습니다.');
        }

        // 소유권 검증: 식별자 없으면 거부, 항상 검증
        if ($cartSessionId === '' && $memberId <= 0) {
            return Result::failure('장바구니 아이템에 접근할 수 없습니다.');
        }
        if (!$this->isCartItemOwner($cartItem, $cartSessionId, $memberId)) {
            return Result::failure('장바구니 아이템에 접근할 수 없습니다.');
        }

        if ($cartItem->getOptionType()->value === 'EXTRA') {
            return Result::failure('갱신할 수 없는 항목입니다.');
        }

        $product = $this->productRepository->findInDomain($domainId, $cartItem->getGoodsId());
        if (!$product || !$product->isActive()) {
            return Result::failure('판매 중인 상품이 아닙니다.');
        }
        $stock = $this->resolveCartItemStock($cartItem, $product);
        if ($stock !== null && $stock < $cartItem->getQuantity()) {
            return Result::failure('상품의 재고가 부족합니다.');
        }

        $currentPrice = $this->priceCalculator->calculateSalesPrice(
            $product->getDisplayPrice(),
            $product->getDiscountType(),
            $product->getDiscountValue()
        )['sales_price'];

        if ($currentPrice === $cartItem->getGoodsPrice()) {
            return Result::success('이미 최신 가격입니다.');
        }

        if (!$this->cartRepository->updatePrice($cartItemId, $currentPrice, $domainId)) {
            return Result::failure('가격 갱신에 실패했습니다.');
        }

        return Result::success('현재 가격으로 갱신되었습니다.');
    }

    // =========================================================================
    // 요약 / 합계
    // =========================================================================

    /**
     * 장바구니 요약 정보 조회
     */
    public function getCartSummary(string $sessionId, int $memberId, int $domainId): Result
    {
        $cartItems = $this->cartRepository->getItems($sessionId, $memberId, $domainId);

        $totalItems = 0;
        $totalPrice = 0;
        foreach ($cartItems as $cartItem) {
            $totalItems += $cartItem->getQuantity();
            $totalPrice += $cartItem->getTotalPrice();
        }

        // 배송비 계산용 상품 로드 (템플릿/적용방식 해석)
        $goodsIds = array_unique(array_map(fn($item) => $item->getGoodsId(), $cartItems));
        $products = [];
        foreach ($this->productRepository->findByIdsForDomain($domainId, $goodsIds) as $p) {
            $products[$p->getGoodsId()] = $p;
        }
        $lines = [];
        foreach ($cartItems as $cartItem) {
            $product = $products[$cartItem->getGoodsId()] ?? null;
            if (!$product) {
                continue;
            }
            $lines[] = [
                'goods_id'             => $cartItem->getGoodsId(),
                'shipping_template_id' => $product->getShippingTemplateId(),
                'shipping_apply_type'  => $product->getShippingApplyType(),
                'total_price'          => $cartItem->getTotalPrice(),
                'quantity'             => $cartItem->getQuantity(),
            ];
        }
        $shippingResult = $this->shippingFeeCalculator->calculate($domainId, $lines);

        return Result::success('', [
            'totalItems' => $totalItems,
            'totalPrice' => $totalPrice,
            'shippingFee' => $shippingResult['total'],          // 미설정이면 null
            'unresolved' => $shippingResult['unresolved'],
        ]);
    }

    /**
     * 선택된 장바구니 아이템(cart_item_ids) 기준 배송비/합계 재계산
     *
     * 체크박스 선택 변경 시 AJAX로 호출. getGroupedCartList와 동일하게
     * ShippingFeeCalculator를 사용하여(단일 진실 원천) 그룹 배송비·합계·무료배송 부족액을 반환.
     *
     * @return Result groups[groupKey] = {shipping_fee, unresolved, free_remain}, totals
     */
    public function recalculateSelection(string $sessionId, int $memberId, int $domainId, array $selectedIds): Result
    {
        $selected = array_map('intval', $selectedIds);
        $cartItems = array_filter(
            $this->cartRepository->getItems($sessionId, $memberId, $domainId),
            fn($ci) => in_array($ci->getCartItemId(), $selected, true)
        );

        if (empty($cartItems)) {
            return Result::success('', [
                'groups' => [],
                'totals' => ['itemTotal' => 0, 'shippingTotal' => 0, 'grandTotal' => 0, 'unresolved' => false],
            ]);
        }

        $goodsIds = array_unique(array_map(fn($ci) => $ci->getGoodsId(), $cartItems));
        $products = [];
        foreach ($this->productRepository->findByIdsForDomain($domainId, $goodsIds) as $p) {
            $products[$p->getGoodsId()] = $p;
        }
        $lines = [];
        $itemTotal = 0;
        foreach ($cartItems as $ci) {
            $product = $products[$ci->getGoodsId()] ?? null;
            if (!$product) {
                continue;
            }
            $lines[] = [
                'goods_id'             => $ci->getGoodsId(),
                'shipping_template_id' => $product->getShippingTemplateId(),
                'shipping_apply_type'  => $product->getShippingApplyType(),
                'total_price'          => $ci->getTotalPrice(),
                'quantity'             => $ci->getQuantity(),
            ];
            $itemTotal += $ci->getTotalPrice();
        }

        $shipping = $this->shippingFeeCalculator->calculate($domainId, $lines);

        $groups = [];
        foreach ($shipping['groups'] as $key => $g) {
            $remain = 0;
            if (empty($g['unresolved'])
                && ($g['shipping_method'] ?? '') === 'COND'
                && (int) ($g['free_threshold'] ?? 0) > 0
                && (int) ($g['shipping_fee'] ?? 0) > 0
            ) {
                $remain = max(0, (int) $g['free_threshold'] - (int) ($g['group_total'] ?? 0));
            }
            $groups[$key] = [
                'shipping_fee' => $g['shipping_fee'],
                'unresolved'   => (bool) $g['unresolved'],
                'free_remain'  => $remain,
            ];
        }

        return Result::success('', [
            'groups' => $groups,
            'totals' => [
                'itemTotal'     => $itemTotal,
                'shippingTotal' => $shipping['total'],
                'grandTotal'    => $itemTotal + (int) ($shipping['total'] ?? 0),
                'unresolved'    => $shipping['unresolved'],
            ],
        ]);
    }

    // =========================================================================
    // Private — 장바구니 아이템 빌드
    // =========================================================================

    /**
     * JS getSubmitData() 구조를 DB 행 배열로 변환
     */
    private function buildCartItems(array $data, Product $product, int $salesPrice): array
    {
        $optionMode = $data['option_mode'] ?? 'NONE';
        $goodsId = $product->getGoodsId();
        $items = [];

        if ($optionMode === 'NONE') {
            $quantity = max(1, (int) ($data['quantity'] ?? 1));

            if ($product->getStockQuantity() !== null && $product->getStockQuantity() < $quantity) {
                return [];
            }

            $rewardResult = $this->priceCalculator->calculateRewardPoints(
                $salesPrice, $product->getRewardType(), $product->getRewardValue()
            );

            $items[] = [
                'goods_id' => $goodsId,
                'option_mode' => 'NONE',
                'option_id' => 0,
                'option_code' => null,
                'option_type' => 'BASIC',
                'goods_price' => $salesPrice,
                'option_price' => 0,
                'total_price' => $salesPrice * $quantity,
                'quantity' => $quantity,
                'point_amount' => $rewardResult['point_amount'] * $quantity,
            ];

            return $items;
        }

        // BASIC 옵션 (selectedOptions)
        foreach (($data['selectedOptions'] ?? []) as $opt) {
            $item = $this->resolveOptionCartItem($product, $salesPrice, $optionMode, $opt, 'BASIC');
            if ($item) {
                $items[] = $item;
            }
        }

        // EXTRA 옵션 (selectedExtras)
        foreach (($data['selectedExtras'] ?? []) as $ext) {
            $item = $this->resolveOptionCartItem($product, $salesPrice, 'SINGLE', $ext, 'EXTRA');
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * 단일 옵션 항목을 DB 행으로 변환
     */
    private function resolveOptionCartItem(Product $product, int $salesPrice, string $optionMode, array $opt, string $optionType): ?array
    {
        $optionCode = $opt['optionCode'] ?? '';
        $quantity = max(1, (int) ($opt['quantity'] ?? 1));
        $optionPrice = 0;
        $optionId = 0;

        if ($optionMode === 'COMBINATION') {
            $comboId = (int) ($opt['comboId'] ?? 0);
            if ($comboId <= 0) {
                return null;
            }

            $combo = $this->productOptionRepository->findCombo($comboId);
            if (!$combo) {
                return null;
            }

            $optionPrice = (int) ($combo['extra_price'] ?? 0);
            $optionId = $comboId;

            $stock = $combo['stock_quantity'] ?? null;
            if ($stock !== null && $stock !== '' && (int) $stock < $quantity) {
                return null;
            }
        } else {
            $valueId = (int) ($opt['valueId'] ?? 0);
            $optId = (int) ($opt['optionId'] ?? 0);

            if ($valueId > 0 && $optId > 0) {
                $val = $this->productOptionRepository->findValue($valueId);
                if (!$val) {
                    return null;
                }

                $optionPrice = (int) ($val['extra_price'] ?? 0);
                $optionId = $optId;

                $stock = $val['stock_quantity'] ?? null;
                if ($stock !== null && $stock !== '' && (int) $stock < $quantity) {
                    return null;
                }
            }
        }

        if ($optionType === 'EXTRA') {
            $goodsPrice = 0;
            $unitPrice = $optionPrice;
        } else {
            $goodsPrice = $salesPrice;
            $unitPrice = $salesPrice + $optionPrice;
        }
        $totalPrice = $unitPrice * $quantity;

        $pointAmount = 0;
        if ($optionType !== 'EXTRA') {
            $rewardResult = $this->priceCalculator->calculateRewardPoints(
                $unitPrice, $product->getRewardType(), $product->getRewardValue()
            );
            $pointAmount = $rewardResult['point_amount'] * $quantity;
        }

        return [
            'goods_id' => $product->getGoodsId(),
            'option_mode' => $optionMode,
            'option_id' => $optionId,
            'option_code' => $optionCode ?: null,
            'option_type' => $optionType,
            'goods_price' => $goodsPrice,
            'option_price' => $optionPrice,
            'total_price' => $totalPrice,
            'quantity' => $quantity,
            'point_amount' => $pointAmount,
        ];
    }

    // =========================================================================
    // Private — 헬퍼
    // =========================================================================

    /**
     * 장바구니 아이템의 가용 재고 반환 (옵션상품은 옵션/조합 재고, NONE은 상품 재고)
     *
     * @return int|null 재고 수량, NULL=미관리(무한)
     */
    private function resolveCartItemStock($cartItem, Product $product): ?int
    {
        $mode = $cartItem->getOptionMode()->value;
        if ($mode === 'NONE') {
            return $product->getStockQuantity();
        }

        return $this->productOptionRepository->getOptionStock(
            $mode,
            $cartItem->getOptionId(),
            $cartItem->getOptionCode()
        );
    }

    /**
     * 장바구니 아이템 소유권 확인
     *
     * @param CartItem $cartItem 장바구니 아이템 엔티티
     * @param string $cartSessionId 요청자의 세션 ID
     * @param int $memberId 요청자의 회원 ID
     * @return bool 소유자이면 true
     */
    private function isCartItemOwner($cartItem, string $cartSessionId, int $memberId): bool
    {
        // 회원: member_id 일치
        if ($memberId > 0 && $cartItem->getMemberId() === $memberId) {
            return true;
        }
        // 비회원/세션: cart_session_id 일치
        if ($cartSessionId !== '' && $cartItem->getCartSessionId() === $cartSessionId) {
            return true;
        }
        return false;
    }

    /**
     * 옵션 라벨 해석 (장바구니 목록 표시용)
     */
    private function resolveOptionLabel(int $goodsId, int $optionId, string $optionMode, ?string $optionCode): ?string
    {
        if ($optionMode === 'COMBINATION') {
            $combo = $this->productOptionRepository->findCombo($optionId);
            return $combo ? ($combo['combination_key'] ?? null) : null;
        }

        // SINGLE: optionCode 형식 opt-{optionId}-{valueId}
        if ($optionCode && preg_match('/^opt-\d+-(\d+)$/', $optionCode, $m)) {
            $valueId = (int) $m[1];
            $val = $this->productOptionRepository->findValue($valueId);
            return $val ? ($val['value_name'] ?? null) : null;
        }

        return null;
    }

    /**
     * 장바구니 배송비 정보를 shop_cart_fees에 저장
     *
     * 장바구니 담기/옵션 수정 시 호출.
     * 실제 배송비는 getGroupedCartList()에서 그룹 합산 후 계산하므로
     * 여기서는 shipping_fee=0으로 저장하고 템플릿/적용 방식 정보만 기록한다.
     */
    private function saveCartShippingFee(
        string $sessionId,
        int $memberId,
        int $domainId,
        int $goodsId,
        Product $product
    ): void {
        $templateId = $product->getShippingTemplateId();
        $applyType = $product->getShippingApplyType();

        $this->cartRepository->saveShippingFee([
            'domain_id'            => $domainId,
            'cart_session_id'      => $sessionId,
            'member_id'            => $memberId,
            'goods_id'             => $goodsId,
            'shipping_template_id' => $templateId,
            'shipping_apply_type'  => $applyType,
            'shipping_fee'         => 0,
        ]);
    }
}
