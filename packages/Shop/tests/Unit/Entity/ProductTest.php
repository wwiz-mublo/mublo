<?php
/**
 * packages/Shop/tests/Unit/Entity/ProductTest.php
 *
 * Product 엔티티 단위 테스트
 *
 * 외부 패키지 개발 참고용 예시:
 * - fromArray()를 통한 생성 패턴
 * - getter 동작 검증
 * - 상태 판단 메서드(hasDiscount, hasReward, hasOptions, isInStock) 검증
 */

namespace Tests\Shop\Unit\Entity;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Enum\OptionMode;

class ProductTest extends TestCase
{
    // =========================================================
    // fromArray / toArray
    // =========================================================

    public function testFromArrayCreatesProduct(): void
    {
        $data = $this->makeProductData();
        $product = Product::fromArray($data);

        $this->assertInstanceOf(Product::class, $product);
    }

    public function testFromArrayMapsBasicFields(): void
    {
        $data = $this->makeProductData([
            'goods_id'   => 99,
            'domain_id'  => 2,
            'goods_name' => '스마트폰',
            'item_code'  => 'PHONE001',
        ]);

        $product = Product::fromArray($data);

        $this->assertSame(99, $product->getGoodsId());
        $this->assertSame(2, $product->getDomainId());
        $this->assertSame('스마트폰', $product->getGoodsName());
        $this->assertSame('PHONE001', $product->getItemCode());
    }

    public function testFromArrayMapsPriceFields(): void
    {
        $data = $this->makeProductData([
            'origin_price'  => 50000,
            'display_price' => 45000,
        ]);

        $product = Product::fromArray($data);

        $this->assertSame(50000, $product->getOriginPrice());
        $this->assertSame(45000, $product->getDisplayPrice());
    }

    public function testFromArrayMapsEnumFields(): void
    {
        $data = $this->makeProductData([
            'discount_type' => 'PERCENTAGE',
            'reward_type'   => 'FIXED',
            'option_mode'   => 'SINGLE',
        ]);

        $product = Product::fromArray($data);

        $this->assertSame(DiscountType::PERCENTAGE, $product->getDiscountType());
        $this->assertSame(RewardType::FIXED, $product->getRewardType());
        $this->assertSame(OptionMode::SINGLE, $product->getOptionMode());
    }

    public function testFromArrayFallsBackToDefaultEnumOnInvalidValue(): void
    {
        $data = $this->makeProductData([
            'discount_type' => 'INVALID_TYPE',
            'option_mode'   => 'UNKNOWN',
        ]);

        $product = Product::fromArray($data);

        // 잘못된 값은 기본값(NONE)으로 폴백
        $this->assertSame(DiscountType::NONE, $product->getDiscountType());
        $this->assertSame(OptionMode::NONE, $product->getOptionMode());
    }

    public function testToArrayRoundTrip(): void
    {
        $data = $this->makeProductData();
        $product = Product::fromArray($data);
        $array = $product->toArray();

        // 주요 필드가 원본과 일치해야 함
        $this->assertSame($data['goods_id'], $array['goods_id']);
        $this->assertSame($data['goods_name'], $array['goods_name']);
        $this->assertSame($data['origin_price'], $array['origin_price']);
        $this->assertSame($data['display_price'], $array['display_price']);
    }

    public function testNullStockQuantity(): void
    {
        $product = Product::fromArray($this->makeProductData(['stock_quantity' => null]));

        $this->assertNull($product->getStockQuantity());
    }

    // =========================================================
    // 상태 판단 메서드
    // =========================================================

    public function testHasDiscountReturnsFalseWhenTypeIsNone(): void
    {
        $product = Product::fromArray($this->makeProductData([
            'discount_type'  => 'NONE',
            'discount_value' => 10.0,
        ]));

        $this->assertFalse($product->hasDiscount());
    }

    public function testHasDiscountReturnsFalseWhenValueIsZero(): void
    {
        $product = Product::fromArray($this->makeProductData([
            'discount_type'  => 'PERCENTAGE',
            'discount_value' => 0.0,
        ]));

        $this->assertFalse($product->hasDiscount());
    }

    public function testHasDiscountReturnsTrueWhenApplicableAndPositive(): void
    {
        $product = Product::fromArray($this->makeProductData([
            'discount_type'  => 'PERCENTAGE',
            'discount_value' => 10.0,
        ]));

        $this->assertTrue($product->hasDiscount());
    }

    public function testHasRewardReturnsFalseWhenTypeIsNone(): void
    {
        $product = Product::fromArray($this->makeProductData([
            'reward_type'  => 'NONE',
            'reward_value' => 5.0,
        ]));

        $this->assertFalse($product->hasReward());
    }

    public function testHasRewardReturnsTrueWhenApplicableAndPositive(): void
    {
        $product = Product::fromArray($this->makeProductData([
            'reward_type'  => 'PERCENTAGE',
            'reward_value' => 3.0,
        ]));

        $this->assertTrue($product->hasReward());
    }

    public function testHasOptionsReturnsFalseWhenModeIsNone(): void
    {
        $product = Product::fromArray($this->makeProductData(['option_mode' => 'NONE']));

        $this->assertFalse($product->hasOptions());
    }

    public function testHasOptionsReturnsTrueForSingleMode(): void
    {
        $product = Product::fromArray($this->makeProductData(['option_mode' => 'SINGLE']));

        $this->assertTrue($product->hasOptions());
    }

    public function testHasOptionsReturnsTrueForCombinationMode(): void
    {
        $product = Product::fromArray($this->makeProductData(['option_mode' => 'COMBINATION']));

        $this->assertTrue($product->hasOptions());
    }

    public function testIsInStockReturnsTrueWhenStockIsNull(): void
    {
        // stock_quantity가 null이면 재고 무제한으로 취급
        $product = Product::fromArray($this->makeProductData(['stock_quantity' => null]));

        $this->assertTrue($product->isInStock());
    }

    public function testIsInStockReturnsTrueWhenPositiveStock(): void
    {
        $product = Product::fromArray($this->makeProductData(['stock_quantity' => 10]));

        $this->assertTrue($product->isInStock());
    }

    public function testIsInStockReturnsFalseWhenZeroStock(): void
    {
        $product = Product::fromArray($this->makeProductData(['stock_quantity' => 0]));

        $this->assertFalse($product->isInStock());
    }

    public function testIsActiveField(): void
    {
        $active   = Product::fromArray($this->makeProductData(['is_active' => true]));
        $inactive = Product::fromArray($this->makeProductData(['is_active' => false]));

        $this->assertTrue($active->isActive());
        $this->assertFalse($inactive->isActive());
    }

    public function testAllowedCouponField(): void
    {
        $allowed    = Product::fromArray($this->makeProductData(['allowed_coupon' => true]));
        $notAllowed = Product::fromArray($this->makeProductData(['allowed_coupon' => false]));

        $this->assertTrue($allowed->isAllowedCoupon());
        $this->assertFalse($notAllowed->isAllowedCoupon());
    }
}
