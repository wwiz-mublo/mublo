<?php
/**
 * packages/Shop/tests/Unit/Entity/CartItemTest.php
 *
 * CartItem 엔티티 단위 테스트
 *
 * 검증 항목:
 * - fromArray()를 통한 생성 및 getter 동작
 * - 상태 판단: isPending, isOrdered, isMemberCart, isGuestCart, hasOption, isExtraOption
 */

namespace Tests\Shop\Unit\Entity;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Entity\CartItem;
use Mublo\Packages\Shop\Enum\OptionMode;
use Mublo\Packages\Shop\Enum\OptionType;

class CartItemTest extends TestCase
{
    // =========================================================
    // fromArray / 기본 getter
    // =========================================================

    public function testFromArrayCreatesCartItem(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData());

        $this->assertInstanceOf(CartItem::class, $item);
    }

    public function testBasicFieldsAreMapped(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData([
            'cart_item_id'    => 5,
            'cart_session_id' => 'sess_xyz',
            'member_id'       => 10,
            'goods_id'        => 20,
        ]));

        $this->assertSame(5, $item->getCartItemId());
        $this->assertSame('sess_xyz', $item->getCartSessionId());
        $this->assertSame(10, $item->getMemberId());
        $this->assertSame(20, $item->getGoodsId());
    }

    public function testPriceFieldsAreMapped(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData([
            'goods_price'  => 15000,
            'option_price' => 3000,
            'total_price'  => 18000,
            'quantity'     => 2,
            'point_amount' => 500,
        ]));

        $this->assertSame(15000, $item->getGoodsPrice());
        $this->assertSame(3000, $item->getOptionPrice());
        $this->assertSame(18000, $item->getTotalPrice());
        $this->assertSame(2, $item->getQuantity());
        $this->assertSame(500, $item->getPointAmount());
    }

    public function testOptionModeEnumIsParsed(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData(['option_mode' => 'SINGLE']));

        $this->assertSame(OptionMode::SINGLE, $item->getOptionMode());
    }

    public function testOptionTypeEnumIsParsed(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData(['option_type' => 'EXTRA']));

        $this->assertSame(OptionType::EXTRA, $item->getOptionType());
    }

    // =========================================================
    // 상태 판단 메서드
    // =========================================================

    public function testIsPendingReturnsTrueForPendingStatus(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData(['cart_status' => 'PENDING']));

        $this->assertTrue($item->isPending());
        $this->assertFalse($item->isOrdered());
    }

    public function testIsOrderedReturnsTrueForOrderedStatus(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData(['cart_status' => 'ORDERED']));

        $this->assertTrue($item->isOrdered());
        $this->assertFalse($item->isPending());
    }

    public function testIsMemberCartReturnsTrueWhenMemberIdPositive(): void
    {
        $memberItem = CartItem::fromArray($this->makeCartItemData(['member_id' => 42]));
        $guestItem  = CartItem::fromArray($this->makeCartItemData(['member_id' => 0]));

        $this->assertTrue($memberItem->isMemberCart());
        $this->assertFalse($memberItem->isGuestCart());

        $this->assertFalse($guestItem->isMemberCart());
        $this->assertTrue($guestItem->isGuestCart());
    }

    public function testHasOptionReturnsFalseWhenModeIsNone(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData(['option_mode' => 'NONE']));

        $this->assertFalse($item->hasOption());
    }

    public function testHasOptionReturnsTrueForSingleMode(): void
    {
        $item = CartItem::fromArray($this->makeCartItemData(['option_mode' => 'SINGLE']));

        $this->assertTrue($item->hasOption());
    }

    public function testIsExtraOptionReturnsTrueWhenTypeIsExtra(): void
    {
        $extraItem = CartItem::fromArray($this->makeCartItemData(['option_type' => 'EXTRA']));
        $basicItem = CartItem::fromArray($this->makeCartItemData(['option_type' => 'BASIC']));

        $this->assertTrue($extraItem->isExtraOption());
        $this->assertFalse($basicItem->isExtraOption());
    }

    public function testHasOptionPriceReturnsTrueWhenNonZero(): void
    {
        $withPrice    = CartItem::fromArray($this->makeCartItemData(['option_price' => 2000]));
        $withoutPrice = CartItem::fromArray($this->makeCartItemData(['option_price' => 0]));

        $this->assertTrue($withPrice->hasOptionPrice());
        $this->assertFalse($withoutPrice->hasOptionPrice());
    }

    public function testHasPointReturnsTrueWhenPositive(): void
    {
        $withPoint    = CartItem::fromArray($this->makeCartItemData(['point_amount' => 100]));
        $withoutPoint = CartItem::fromArray($this->makeCartItemData(['point_amount' => 0]));

        $this->assertTrue($withPoint->hasPoint());
        $this->assertFalse($withoutPoint->hasPoint());
    }

    // =========================================================
    // toArray 라운드트립
    // =========================================================

    public function testToArrayRoundTrip(): void
    {
        $data = $this->makeCartItemData([
            'goods_price' => 12000,
            'quantity'    => 3,
        ]);

        $item  = CartItem::fromArray($data);
        $array = $item->toArray();

        $this->assertSame(12000, $array['goods_price']);
        $this->assertSame(3, $array['quantity']);
        $this->assertSame('PENDING', $array['cart_status']);
    }
}
