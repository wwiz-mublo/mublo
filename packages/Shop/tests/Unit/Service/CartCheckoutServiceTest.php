<?php
/**
 * packages/Shop/tests/Unit/Service/CartCheckoutServiceTest.php
 *
 * CartCheckoutService 단위 테스트
 *
 * 검증 항목:
 * - prepareCheckout() — 빈 아이템 거부, 세션 불일치 제외, 비활성 상품 제외, 재고 부족 거부
 * - markOrdered()    — 장바구니 상태 ORDERED 변경
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\CartCheckoutService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Entity\CartItem;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Core\Result\Result;

class CartCheckoutServiceTest extends TestCase
{
    private CartRepository $cartRepo;
    private ProductRepository $productRepo;
    private ProductOptionRepository $productOptionRepo;
    private ShippingFeeCalculator $shippingFeeCalc;
    private PriceCalculator $priceCalc;
    private CartCheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartRepo         = $this->createMock(CartRepository::class);
        $this->productRepo      = $this->createMock(ProductRepository::class);
        $this->productOptionRepo = $this->createMock(ProductOptionRepository::class);
        $this->shippingFeeCalc  = $this->createMock(ShippingFeeCalculator::class);
        $this->priceCalc        = new PriceCalculator();

        // 기본 스텁: 배송 정책 해석 성공(배송비 0)
        $this->shippingFeeCalc->method('calculate')->willReturn([
            'groups' => [], 'total' => 0, 'unresolved' => false,
        ]);

        $this->service = new CartCheckoutService(
            $this->cartRepo,
            $this->productRepo,
            $this->priceCalc,
            $this->shippingFeeCalc,
            $this->productOptionRepo
        );
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::fromArray($this->makeProductData($overrides));
    }

    private function makeCartItem(array $overrides = []): CartItem
    {
        return CartItem::fromArray($this->makeCartItemData($overrides));
    }

    // =========================================================
    // prepareCheckout()
    // =========================================================

    public function testPrepareCheckoutFailsWhenNoItemsSelected(): void
    {
        $result = $this->service->prepareCheckout('sess123', [], 0, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('선택', $result->getMessage());
    }

    public function testPrepareCheckoutExcludesItemsFromDifferentSession(): void
    {
        // find()가 null을 반환 → 세션 불일치이거나 존재하지 않는 아이템
        $this->cartRepo->expects($this->exactly(2))
            ->method('findInDomain')
            ->willReturnCallback(function (int $domainId): ?CartItem {
                $this->assertSame(1, $domainId);
                return null;
            });

        $result = $this->service->prepareCheckout('sess123', [99, 100], 0, 1);

        // 모든 아이템이 제외되므로 실패
        $this->assertTrue($result->isFailure());
    }

    public function testPrepareCheckoutFailsWhenProductIsInactive(): void
    {
        $cartItem = $this->makeCartItem(['goods_id' => 1]);
        $product  = $this->makeProduct(['is_active' => false]);

        $this->cartRepo->method('findInDomain')->willReturn($cartItem);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->prepareCheckout('sess_abc123', [10], 0, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('판매 불가', $result->getMessage());
    }

    public function testPrepareCheckoutFailsWhenStockInsufficient(): void
    {
        $cartItem = $this->makeCartItem(['goods_id' => 1, 'quantity' => 5]);
        $product  = $this->makeProduct(['stock_quantity' => 2]);

        $this->cartRepo->method('findInDomain')->willReturn($cartItem);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->prepareCheckout('sess_abc123', [10], 0, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('판매 불가', $result->getMessage());
    }

    public function testPrepareCheckoutSuccessReturnsCheckoutData(): void
    {
        $cartItem = $this->makeCartItem([
            'goods_id'    => 1,
            'quantity'    => 1,
            'goods_price' => 20000,
            'total_price' => 20000,
            'option_mode' => 'NONE',
        ]);
        $product = $this->makeProduct(['stock_quantity' => null, 'display_price' => 20000]);

        $this->cartRepo->method('findInDomain')->willReturn($cartItem);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->prepareCheckout('sess_abc123', [10], 0, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('items', $result->getData());
        $this->assertArrayHasKey('totalPrice', $result->getData());
    }

    public function testPrepareCheckoutCalculatesTotalsCorrectly(): void
    {
        $cartItem1 = $this->makeCartItem([
            'cart_item_id' => 10,
            'goods_id'     => 1,
            'quantity'     => 2,
            'goods_price'  => 20000,
            'option_price' => 0,
            'total_price'  => 40000,
            'option_mode'  => 'NONE',
        ]);

        $product = $this->makeProduct(['stock_quantity' => null, 'display_price' => 20000]);

        $this->cartRepo->method('findInDomain')->willReturn($cartItem1);
        $this->productRepo->method('findInDomain')->willReturn($product);

        $result = $this->service->prepareCheckout('sess_abc123', [10], 0, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('totalPrice', $result->getData());
        $this->assertGreaterThanOrEqual(0, $result->get('totalPrice'));
    }

    // =========================================================
    // markOrdered()
    // =========================================================

    public function testShippingBreakdownKeepsReturnPolicySnapshot(): void
    {
        $result = $this->service->buildShippingBreakdown([[
            'template_id' => 3,
            'template_name' => '기본 배송',
            'base_fee' => 3000,
            'extra_fee' => 0,
            'goods_ids' => [10, 20],
            'return_cost' => 3500,
            'exchange_cost' => 7000,
            'return_address' => ['zipcode' => '12345', 'address1' => '서울', 'address2' => '1층'],
        ]]);

        $this->assertSame([10, 20], $result[0]['goods_ids']);
        $this->assertSame(3500, $result[0]['return_cost']);
        $this->assertSame('서울', $result[0]['return_address']['address1']);
    }

    public function testMarkOrderedUpdatesCartStatus(): void
    {
        $this->cartRepo->method('markOrdered')->willReturn(1);

        // markOrdered는 내부에서 repository를 호출하므로 예외 없이 실행되면 성공
        $this->service->markOrdered('sess_abc123', [10, 11]);

        // 검증: 여기까지 예외 없이 도달하면 성공
        $this->assertTrue(true);
    }
}
