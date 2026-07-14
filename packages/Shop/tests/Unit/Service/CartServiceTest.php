<?php
/**
 * packages/Shop/tests/Unit/Service/CartServiceTest.php
 *
 * CartService 단위 테스트
 *
 * 검증 항목:
 * - addToCart()    — 상품 없음 처리, 비활성/재고 없음 처리, 추가 성공, 중복 upsert
 * - updateQuantity() — 0 이하 수량 거부, 재고 초과 거부, 세션 불일치 거부, 성공
 * - removeItem()   — 성공/실패/세션 불일치
 * - getCartList()  — 빈 장바구니, 아이템 포함
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\CartService;
use Mublo\Packages\Shop\Service\DirectBuyService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Entity\CartItem;
use Mublo\Packages\Shop\Entity\Product;

class CartServiceTest extends TestCase
{
    private CartRepository $cartRepo;
    private ProductRepository $productRepo;
    private ProductOptionRepository $optionRepo;
    private ShippingFeeCalculator $shippingFeeCalc;
    private DirectBuyService $directBuy;
    private PriceCalculator $priceCalc;
    private CartService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartRepo        = $this->createMock(CartRepository::class);
        $this->productRepo     = $this->createMock(ProductRepository::class);
        $this->optionRepo      = $this->createMock(ProductOptionRepository::class);
        $this->shippingFeeCalc = $this->createMock(ShippingFeeCalculator::class);
        $this->directBuy       = $this->createMock(DirectBuyService::class);
        $this->priceCalc       = new PriceCalculator();

        // 기본 스텁: 배송 정책 해석 성공(배송비 0)
        $this->shippingFeeCalc->method('calculate')->willReturn([
            'groups' => [], 'total' => 0, 'unresolved' => false,
        ]);

        $this->service = new CartService(
            $this->cartRepo,
            $this->productRepo,
            $this->optionRepo,
            $this->priceCalc,
            $this->shippingFeeCalc,
            $this->directBuy
        );
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::fromArray($this->makeProductData($overrides));
    }

    // =========================================================
    // addToCart()
    // =========================================================

    public function testAddToCartFailsWhenProductNotFound(): void
    {
        $this->productRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->addToCart([
            'cart_session_id' => 'sess123',
            'member_id'       => 0,
            'domain_id'       => 1,
            'goods_id'        => 999,
            'quantity'        => 1,
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('상품', $result->getMessage());
    }

    public function testAddToCartFailsWhenProductIsInactive(): void
    {
        $product = $this->makeProduct(['is_active' => false]);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->addToCart([
            'cart_session_id' => 'sess123',
            'member_id'       => 0,
            'domain_id'       => 1,
            'goods_id'        => 1,
            'quantity'        => 1,
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testAddToCartFailsWhenOutOfStock(): void
    {
        // stock_quantity = 0 → isInStock() = false
        $product = $this->makeProduct(['stock_quantity' => 0]);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->addToCart([
            'cart_session_id' => 'sess123',
            'member_id'       => 0,
            'domain_id'       => 1,
            'goods_id'        => 1,
            'quantity'        => 1,
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('재고', $result->getMessage());
    }

    public function testAddToCartFailsWhenQuantityExceedsStock(): void
    {
        // stock_quantity = 2, 요청 quantity = 5 → buildCartItems에서 빈 배열 반환
        $product = $this->makeProduct(['stock_quantity' => 2, 'option_mode' => 'NONE']);
        $this->productRepo->method('findInDomain')->willReturn($product);
        $this->cartRepo->method('getItems')->willReturn([]);

        $result = $this->service->addToCart([
            'cart_session_id' => 'sess123',
            'member_id'       => 0,
            'domain_id'       => 1,
            'goods_id'        => 1,
            'quantity'        => 5,
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testAddToCartSuccessfullyAddsNewItem(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 10, 'option_mode' => 'NONE']);
        $this->productRepo->method('findInDomain')->willReturn($product);
        $this->cartRepo->method('findDuplicate')->willReturn(null);
        $this->cartRepo->method('addItem')->willReturn(1);
        $this->cartRepo->method('getItems')->willReturn([]);

        $result = $this->service->addToCart([
            'cart_session_id' => 'sess123',
            'member_id'       => 0,
            'domain_id'       => 1,
            'goods_id'        => 1,
            'quantity'        => 2,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    public function testAddToCartUpsertsDuplicateItem(): void
    {
        // 동일 상품이 이미 장바구니에 있으면 수량 증가 (upsert)
        $product   = $this->makeProduct(['stock_quantity' => 10, 'option_mode' => 'NONE']);
        $existItem = CartItem::fromArray($this->makeCartItemData(['cart_item_id' => 5, 'quantity' => 1]));
        $this->productRepo->method('findInDomain')->willReturn($product);
        $this->cartRepo->method('findDuplicate')->willReturn(5); // 기존 cart_item_id
        $this->cartRepo->method('findInDomain')->willReturn($existItem);
        $this->cartRepo->method('updateQuantity')->willReturn(true);
        $this->cartRepo->method('getItems')->willReturn([]);

        $result = $this->service->addToCart([
            'cart_session_id' => 'sess123',
            'member_id'       => 0,
            'domain_id'       => 1,
            'goods_id'        => 1,
            'quantity'        => 2,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    // =========================================================
    // updateQuantity()
    // updateQuantity(int $cartItemId, int $quantity, string $cartSessionId, int $memberId)
    // =========================================================

    public function testUpdateQuantityFailsWhenZero(): void
    {
        $cartItem = CartItem::fromArray($this->makeCartItemData());
        $this->cartRepo->method('findInDomain')->willReturn($cartItem);

        $result = $this->service->updateQuantity(10, 0, 'sess_abc123', 0, 1);

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateQuantityFailsWhenStockInsufficient(): void
    {
        $cartItem = CartItem::fromArray($this->makeCartItemData(['goods_id' => 1]));
        $product  = $this->makeProduct(['stock_quantity' => 3]);
        $this->cartRepo->method('findInDomain')->willReturn($cartItem);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->updateQuantity(10, 10, 'sess_abc123', 0, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('재고', $result->getMessage());
    }

    public function testUpdateQuantitySucceeds(): void
    {
        $cartItem = CartItem::fromArray($this->makeCartItemData(['goods_id' => 1]));
        $product  = $this->makeProduct(['stock_quantity' => 100]);
        $this->cartRepo->method('findInDomain')->willReturn($cartItem);
        $this->productRepo->method('findInDomain')->willReturn($product);
        $this->cartRepo->method('updateQuantity')->willReturn(true);

        $result = $this->service->updateQuantity(10, 3, 'sess_abc123', 0, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateQuantityFailsForWrongSession(): void
    {
        // session 불일치 → 수정 거부
        $cartItem = CartItem::fromArray($this->makeCartItemData(['cart_session_id' => 'sess_other']));
        $this->cartRepo->method('findInDomain')->willReturn($cartItem);

        $result = $this->service->updateQuantity(10, 1, 'sess_mine', 0, 1);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // removeItem()
    // =========================================================

    public function testRemoveItemSucceeds(): void
    {
        $cartItem = CartItem::fromArray($this->makeCartItemData());
        $this->cartRepo->method('findInDomain')->willReturn($cartItem);
        $this->cartRepo->method('removeItem')->willReturn(true);

        $result = $this->service->removeItem(10, 'sess_abc123', 0, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testRemoveItemFailsWhenNotFound(): void
    {
        $this->cartRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->removeItem(99, 'sess_abc123', 0, 1);

        $this->assertTrue($result->isFailure());
    }

    public function testRemoveItemFailsForWrongSession(): void
    {
        $cartItem = CartItem::fromArray($this->makeCartItemData(['cart_session_id' => 'sess_other']));
        $this->cartRepo->method('findInDomain')->willReturn($cartItem);

        $result = $this->service->removeItem(10, 'sess_mine', 0, 1);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // getCartList()
    // =========================================================

    public function testGetCartListReturnsEmptyWhenNoItems(): void
    {
        $this->cartRepo->method('getItems')->willReturn([]);

        $result = $this->service->getCartList('sess123', 0, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertEmpty($result->get('items'));
    }

    public function testGetCartListReturnsItemsWithProductInfo(): void
    {
        $cartItem = CartItem::fromArray($this->makeCartItemData(['goods_id' => 1]));
        $product  = $this->makeProduct(['goods_id' => 1]);

        $this->cartRepo->method('getItems')->willReturn([$cartItem]);
        $this->productRepo->method('findByIdsForDomain')->willReturn([$product]);
        $this->productRepo->method('getMainImages')->willReturn([]);

        $result = $this->service->getCartList('sess123', 0, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->get('items'));
    }

    // =========================================================
    // recalculateSelection() — 선택 기반 재계산
    // =========================================================

    public function testRecalculateSelectionUsesOnlySelectedItemsAndMapsFreeRemain(): void
    {
        // 전용 계산기 mock: 조건부 무료 그룹(기준 5만, 소계 3만 → 부족 2만) 반환
        $calc = $this->createMock(ShippingFeeCalculator::class);
        $calc->method('calculate')->willReturn([
            'groups' => [
                'tpl_10' => [
                    'shipping_fee'    => 3000,
                    'unresolved'      => false,
                    'shipping_method' => 'COND',
                    'free_threshold'  => 50000,
                    'group_total'     => 30000,
                ],
            ],
            'total'      => 3000,
            'unresolved' => false,
        ]);

        $service = new CartService(
            $this->cartRepo, $this->productRepo, $this->optionRepo,
            $this->priceCalc, $calc, $this->directBuy
        );

        $item1 = CartItem::fromArray($this->makeCartItemData(['cart_item_id' => 1, 'goods_id' => 1, 'total_price' => 30000]));
        $item2 = CartItem::fromArray($this->makeCartItemData(['cart_item_id' => 2, 'goods_id' => 2, 'total_price' => 20000]));
        $this->cartRepo->method('getItems')->willReturn([$item1, $item2]);
        $this->productRepo->method('findByIdsForDomain')->willReturn([
            $this->makeProduct(['goods_id' => 1]),
            $this->makeProduct(['goods_id' => 2]),
        ]);

        // 아이템 1만 선택
        $data = $service->recalculateSelection('sess', 0, 1, [1])->getData();

        $this->assertSame(30000, $data['totals']['itemTotal']);   // 선택된 것만 합산
        $this->assertSame(3000, $data['totals']['shippingTotal']);
        $this->assertSame(33000, $data['totals']['grandTotal']);
        $this->assertFalse($data['totals']['unresolved']);
        $this->assertSame(20000, $data['groups']['tpl_10']['free_remain']); // 50000 - 30000
    }

    public function testRecalculateSelectionEmptyWhenNothingSelected(): void
    {
        $item1 = CartItem::fromArray($this->makeCartItemData(['cart_item_id' => 1, 'goods_id' => 1]));
        $this->cartRepo->method('getItems')->willReturn([$item1]);

        $data = $this->service->recalculateSelection('sess', 0, 1, [])->getData();

        $this->assertSame([], $data['groups']);
        $this->assertSame(0, $data['totals']['itemTotal']);
        $this->assertSame(0, $data['totals']['grandTotal']);
        $this->assertFalse($data['totals']['unresolved']);
    }
}
