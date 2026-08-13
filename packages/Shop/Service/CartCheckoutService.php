<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Session\SessionInterface;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;

/**
 * CartCheckoutService
 *
 * 체크아웃/결제 관련 장바구니 로직
 *
 * 책임:
 * - 체크아웃 데이터 준비 (prepareCheckout)
 * - 장바구니 상태 변경 (markOrdered)
 * - 주문용 cart_item_ids 세션 관리
 * - checkout용 합계 계산
 */
class CartCheckoutService
{
    private CartRepository $cartRepository;
    private ProductRepository $productRepository;
    private PriceCalculator $priceCalculator;
    private ShippingFeeCalculator $shippingFeeCalculator;
    private ProductOptionRepository $productOptionRepository;
    private ?SessionInterface $sessionManager;
    private ?ShopConfigService $shopConfigService;
    private ?MemberLevelResolver $memberLevelResolver;

    /** @var array<int,array> 도메인별 쇼핑몰 설정 캐시 */
    private array $shopConfigCache = [];

    public function __construct(
        CartRepository $cartRepository,
        ProductRepository $productRepository,
        PriceCalculator $priceCalculator,
        ShippingFeeCalculator $shippingFeeCalculator,
        ProductOptionRepository $productOptionRepository,
        ?SessionInterface $sessionManager = null,
        ?ShopConfigService $shopConfigService = null,
        ?MemberLevelResolver $memberLevelResolver = null
    ) {
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->priceCalculator = $priceCalculator;
        $this->shippingFeeCalculator = $shippingFeeCalculator;
        $this->productOptionRepository = $productOptionRepository;
        $this->sessionManager = $sessionManager;
        $this->shopConfigService = $shopConfigService;
        $this->memberLevelResolver = $memberLevelResolver;
    }

    /**
     * 도메인 쇼핑몰 설정 (요청 단위 메모이제이션)
     */
    private function shopConfig(int $domainId): array
    {
        if ($this->shopConfigService === null) {
            return [];
        }

        return $this->shopConfigCache[$domainId]
            ??= $this->shopConfigService->getConfig($domainId)->get('config', []);
    }

    /**
     * 체크아웃 준비
     *
     * 선택된 cart_item_ids의 상품 유효성, 재고, 가격 변동을 검증하고
     * 체크아웃 데이터를 반환한다.
     */
    public function prepareCheckout(string $sessionId, array $cartItemIds, int $memberId, int $domainId): Result
    {
        if (empty($cartItemIds)) {
            return Result::failure('선택된 상품이 없습니다.');
        }

        $checkoutItems = [];
        $totalPrice = 0;
        $unavailableItems = [];
        $lines = [];

        foreach ($cartItemIds as $cartItemId) {
            $cartItem = $this->cartRepository->findInDomain($domainId, $cartItemId);
            if (!$cartItem) {
                continue;
            }
            // CartRepository.getItems와 동일하게: 회원은 session OR member 매칭
            // (회원이 다른 sessionId로 담은 카트 — 재로그인·다른 기기·쿠키만료 등 — 도 결제 가능해야 함)
            $sessionMatch = $cartItem->getCartSessionId() === $sessionId;
            $memberMatch  = $memberId > 0 && $cartItem->getMemberId() === $memberId;
            if (!$sessionMatch && !$memberMatch) {
                continue;
            }

            $product = $this->productRepository->findInDomain($domainId, $cartItem->getGoodsId());
            if (!$product || !$product->isActive()) {
                $unavailableItems[] = $cartItem->getGoodsId();
                continue;
            }

            // 옵션상품은 옵션/조합 재고, NONE은 상품레벨 재고로 판정
            $mode = $cartItem->getOptionMode()->value;
            $stock = $mode === 'NONE'
                ? $product->getStockQuantity()
                : $this->productOptionRepository->getOptionStock($mode, $cartItem->getOptionId(), $cartItem->getOptionCode());
            if ($stock !== null && $stock < $cartItem->getQuantity()) {
                $unavailableItems[] = $product->getGoodsName();
                continue;
            }

            // 가격 재검증: 장바구니 저장 시점과 현재 가격 비교
            if (!$cartItem->isExtraOption()) {
                $priceResult = $this->priceCalculator->calculateSalesPrice(
                    $product->getDisplayPrice(),
                    $product->getDiscountType(),
                    $product->getDiscountValue(),
                    $this->shopConfig($domainId),
                    $this->memberLevelResolver?->levelIdFor($memberId),
                    $product->getDiscountLevelSettings()
                );
                if ($cartItem->getGoodsPrice() !== $priceResult['sales_price']) {
                    $unavailableItems[] = $product->getGoodsName() . ' (가격 변동)';
                    continue;
                }
            }

            $itemData = $cartItem->toArray();
            $itemData['product'] = $product->toArray();

            $checkoutItems[] = $itemData;
            $totalPrice += $cartItem->getTotalPrice();

            if ($domainId === 0) {
                $domainId = $product->getDomainId();
            }
            $lines[] = [
                'goods_id'             => $cartItem->getGoodsId(),
                'shipping_template_id' => $product->getShippingTemplateId(),
                'shipping_apply_type'  => $product->getShippingApplyType(),
                'total_price'          => $cartItem->getTotalPrice(),
                'quantity'             => $cartItem->getQuantity(),
            ];
        }

        if (!empty($unavailableItems)) {
            return Result::failure(
                '일부 상품이 판매 불가 상태입니다: ' . implode(', ', $unavailableItems)
            );
        }

        if (empty($checkoutItems)) {
            return Result::failure('유효한 상품이 없습니다.');
        }

        $shipping = $this->shippingFeeCalculator->calculate($domainId, $lines);
        if ($shipping['unresolved']) {
            return Result::failure('배송 정책이 설정되지 않았습니다. 관리자에게 문의해주세요.');
        }

        return Result::success('', [
            'items' => $checkoutItems,
            'totalPrice' => $totalPrice,
            'shippingFee' => $shipping['total'],
        ]);
    }

    /**
     * 장바구니 상태를 ORDERED로 변경 (결제 확인 후 호출)
     *
     * @param string $sessionId 장바구니 세션 ID
     * @param array $cartItemIds 특정 아이템만 변경 (빈 배열이면 세션 전체)
     */
    public function markOrdered(string $sessionId, array $cartItemIds = []): void
    {
        if (!empty($cartItemIds)) {
            $this->cartRepository->markOrderedByIds($cartItemIds, $sessionId);
        } else {
            $this->cartRepository->markOrdered($sessionId);
        }
    }

    /**
     * 체크아웃에서 주문할 cart_item_ids를 세션에 저장
     *
     * 장바구니에서 체크한 항목만 체크아웃 페이지에 표시하기 위해 사용한다.
     * (장바구니→체크아웃 사이에 선택 정보를 전달하는 유일한 경로)
     */
    private const CHECKOUT_SELECTION_KEY = 'checkout_selected_cart_items';

    public function saveCheckoutSelection(array $cartItemIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $cartItemIds)));
        $this->sessionManager?->set(self::CHECKOUT_SELECTION_KEY, $ids);
    }

    /**
     * 체크아웃에서 주문할 cart_item_ids 조회 (세션)
     *
     * 페이지 새로고침 대비로 읽어도 제거하지 않는다.
     * (다음 prepareCheckout 호출 시 덮어쓰기됨)
     *
     * @return int[] cart_item_id 배열 (선택 정보가 없으면 빈 배열)
     */
    public function getCheckoutSelection(): array
    {
        $ids = $this->sessionManager?->get(self::CHECKOUT_SELECTION_KEY);
        return is_array($ids) ? $ids : [];
    }

    /**
     * 주문 생성 시 사용된 cart_item_ids를 세션에 저장
     *
     * verify()에서 해당 아이템만 ORDERED로 변경하기 위해 사용
     */
    public function saveOrderCartItems(string $orderNo, array $cartItemIds): void
    {
        $this->sessionManager?->set("order_cart_items_{$orderNo}", $cartItemIds);
    }

    /**
     * 주문에 포함된 cart_item_ids 조회 (세션)
     *
     * @return array cart_item_id 배열
     */
    public function getOrderCartItems(string $orderNo): array
    {
        $ids = $this->sessionManager?->get("order_cart_items_{$orderNo}");
        if (is_array($ids)) {
            $this->sessionManager?->remove("order_cart_items_{$orderNo}");
            return $ids;
        }
        return [];
    }

    /**
     * checkout()용 합계 계산 (이미 조회된 아이템 배열 기반)
     *
     * @param ?string $zipcode 배송지 우편번호 (있으면 도서산간 추가배송비 반영)
     */
    public function calculateTotals(array $cartItems, ?string $zipcode = null): array
    {
        $totalPrice = 0;
        $totalPoint = 0;
        $totalQuantity = 0;
        $lines = [];
        $domainId = 0;

        foreach ($cartItems as $item) {
            $totalPrice += (int) ($item['total_price'] ?? 0);
            $totalPoint += (int) ($item['point_amount'] ?? 0);
            $totalQuantity += (int) ($item['quantity'] ?? 0);

            $product = $item['product'] ?? [];
            if ($domainId === 0) {
                $domainId = (int) ($product['domain_id'] ?? 0);
            }
            $lines[] = [
                'goods_id'             => (int) ($product['goods_id'] ?? $item['goods_id'] ?? 0),
                'shipping_template_id' => $product['shipping_template_id'] ?? null,
                'shipping_apply_type'  => $product['shipping_apply_type'] ?? 'COMBINED',
                'total_price'          => (int) ($item['total_price'] ?? 0),
                'quantity'             => (int) ($item['quantity'] ?? 0),
            ];
        }

        $shipping = $this->shippingFeeCalculator->calculate($domainId, $lines, $zipcode);
        $shippingFee = $shipping['total'];          // 미설정이면 null

        return [
            'totalPrice' => $totalPrice,
            'shippingFee' => $shippingFee,
            'extraShippingFee' => (int) ($shipping['extra_total'] ?? 0),
            'shippingBreakdown' => $this->buildShippingBreakdown($shipping['groups'] ?? []),
            'totalPoint' => $totalPoint,
            'totalQuantity' => $totalQuantity,
            'grandTotal' => $totalPrice + (int) ($shippingFee ?? 0),
            'unresolved' => $shipping['unresolved'],
        ];
    }

    /**
     * 배송비 분해 내역 구성 (그룹별 기본/추가비)
     *
     * 주문 저장(shipping_breakdown JSON)과 체크아웃 분해 표시에 공통으로 쓰인다.
     *
     * @param array $groups ShippingFeeCalculator::calculate()의 groups
     * 반품 정책도 주문 당시 값으로 보존한다. 이후 템플릿을 수정해도 이미 접수된
     * 주문의 반품비와 반품지가 바뀌지 않아야 한다.
     */
    public function buildShippingBreakdown(array $groups): array
    {
        $breakdown = [];
        foreach ($groups as $g) {
            if (!empty($g['unresolved'])) {
                continue;
            }
            $breakdown[] = [
                'template_id'   => $g['template_id'] ?? null,
                'template_name' => $g['template_name'] ?? '',
                'base_fee'      => (int) ($g['base_fee'] ?? $g['shipping_fee'] ?? 0),
                'extra_fee'     => (int) ($g['extra_fee'] ?? 0),
                'extra_label'   => (string) ($g['extra_label'] ?? ''),
                'goods_ids'     => array_values(array_map('intval', (array) ($g['goods_ids'] ?? []))),
                'return_cost'   => max(0, (int) ($g['return_cost'] ?? 0)),
                'exchange_cost' => max(0, (int) ($g['exchange_cost'] ?? 0)),
                'return_address' => (array) ($g['return_address'] ?? []),
            ];
        }
        return $breakdown;
    }
}
