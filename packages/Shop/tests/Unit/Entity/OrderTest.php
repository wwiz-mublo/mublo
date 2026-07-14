<?php
/**
 * packages/Shop/tests/Unit/Entity/OrderTest.php
 *
 * Order 엔티티 단위 테스트
 *
 * 검증 항목:
 * - fromArray()를 통한 생성 및 getter 동작
 * - getFinalAmount() 금액 계산 로직
 * - 커스텀 상태(custom:라벨) 파싱 동작
 */

namespace Tests\Shop\Unit\Entity;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Enum\PaymentMethod;

class OrderTest extends TestCase
{
    // =========================================================
    // fromArray / 기본 getter
    // =========================================================

    public function testFromArrayCreatesOrder(): void
    {
        $order = Order::fromArray($this->makeOrderData());

        $this->assertInstanceOf(Order::class, $order);
    }

    public function testOrderNoAndMemberIdAreSet(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no'  => 'ORD2025030500001',
            'member_id' => 77,
        ]));

        $this->assertSame('ORD2025030500001', $order->getOrderNo());
        $this->assertSame(77, $order->getMemberId());
    }

    public function testAmountFieldsAreIntegers(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'total_price'     => 30000,
            'shipping_fee'    => 3000,
            'point_used'      => 1000,
            'coupon_discount' => 2000,
            'extra_price'     => 500,
            'tax_amount'      => 0,
        ]));

        $this->assertSame(30000, $order->getTotalPrice());
        $this->assertSame(3000, $order->getShippingFee());
        $this->assertSame(1000, $order->getPointUsed());
        $this->assertSame(2000, $order->getCouponDiscount());
        $this->assertSame(500, $order->getExtraPrice());
        $this->assertSame(0, $order->getTaxAmount());
    }

    public function testPaymentMethodEnumIsParsed(): void
    {
        $order = Order::fromArray($this->makeOrderData(['payment_method' => 'CARD']));

        $this->assertSame(PaymentMethod::CARD, $order->getPaymentMethod());
    }

    public function testPaymentMethodFallsBackToBankOnInvalid(): void
    {
        $order = Order::fromArray($this->makeOrderData(['payment_method' => 'INVALID']));

        $this->assertSame(PaymentMethod::BANK, $order->getPaymentMethod());
    }

    public function testOrderStatusEnumIsParsed(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'paid']));

        $this->assertSame(OrderAction::PAID, $order->getOrderStatus());
    }

    public function testOrderStatusRawIsPreserved(): void
    {
        // custom:라벨 형식의 커스텀 상태는 Enum에 없으므로 null이지만 Raw는 원본 유지
        $order = Order::fromArray($this->makeOrderData(['order_status' => 'custom:검수중']));

        $this->assertNull($order->getOrderStatus());
        $this->assertSame('custom:검수중', $order->getOrderStatusRaw());
    }

    public function testShippingAddressFields(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'shipping_zip'      => '06234',
            'shipping_address1' => '서울시 강남구 테헤란로',
            'shipping_address2' => '101호',
            'recipient_name'    => '김테스트',
            'recipient_phone'   => '01099998888',
        ]));

        $this->assertSame('06234', $order->getShippingZip());
        $this->assertSame('서울시 강남구 테헤란로', $order->getShippingAddress1());
        $this->assertSame('101호', $order->getShippingAddress2());
        $this->assertSame('김테스트', $order->getRecipientName());
        $this->assertSame('01099998888', $order->getRecipientPhone());
    }

    public function testIsCompleteAndIsDirectOrder(): void
    {
        $complete = Order::fromArray($this->makeOrderData(['is_complete' => true, 'is_direct_order' => true]));
        $pending  = Order::fromArray($this->makeOrderData(['is_complete' => false, 'is_direct_order' => false]));

        $this->assertTrue($complete->isComplete());
        $this->assertTrue($complete->isDirectOrder());
        $this->assertFalse($pending->isComplete());
        $this->assertFalse($pending->isDirectOrder());
    }

    // =========================================================
    // getFinalAmount() — 최종 결제 금액 계산
    // =========================================================

    /**
     * 공식: total_price + extra_price + shipping_fee + tax_amount - point_used - coupon_discount
     */
    public function testGetFinalAmountBasicCase(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'total_price'     => 30000,
            'extra_price'     => 0,
            'shipping_fee'    => 3000,
            'tax_amount'      => 0,
            'point_used'      => 0,
            'coupon_discount' => 0,
        ]));

        $this->assertSame(33000, $order->getFinalAmount());
    }

    public function testGetFinalAmountWithPointAndCoupon(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'total_price'     => 50000,
            'extra_price'     => 1000,
            'shipping_fee'    => 3000,
            'tax_amount'      => 0,
            'point_used'      => 2000,
            'coupon_discount' => 5000,
        ]));

        // 50000 + 1000 + 3000 + 0 - 2000 - 5000 = 47000
        $this->assertSame(47000, $order->getFinalAmount());
    }

    public function testGetFinalAmountWithAllFields(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'total_price'     => 100000,
            'extra_price'     => 2000,
            'shipping_fee'    => 3000,
            'tax_amount'      => 500,
            'point_used'      => 3000,
            'coupon_discount' => 10000,
        ]));

        // 100000 + 2000 + 3000 + 500 - 3000 - 10000 = 92500
        $this->assertSame(92500, $order->getFinalAmount());
    }

    // =========================================================
    // toArray 라운드트립
    // =========================================================

    public function testToArrayContainsExpectedKeys(): void
    {
        $order = Order::fromArray($this->makeOrderData());
        $array = $order->toArray();

        $expectedKeys = [
            'order_no', 'domain_id', 'member_id',
            'total_price', 'shipping_fee', 'point_used', 'coupon_discount',
            'orderer_name', 'orderer_phone', 'orderer_email',
            'shipping_zip', 'shipping_address1', 'recipient_name',
            'payment_method', 'order_status',
            'is_complete', 'is_direct_order', 'created_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "toArray()에 '{$key}' 키가 없습니다.");
        }
    }
}
