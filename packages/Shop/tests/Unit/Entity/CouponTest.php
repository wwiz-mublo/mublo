<?php
/**
 * packages/Shop/tests/Unit/Entity/CouponTest.php
 *
 * Coupon 엔티티 단위 테스트
 *
 * 검증 항목:
 * - fromArray()를 통한 생성 및 getter 동작
 * - isExpired(): 만료일 기준 판단
 * - isUsable(): 미사용 + 미만료 조합 판단
 */

namespace Tests\Shop\Unit\Entity;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Entity\Coupon;

class CouponTest extends TestCase
{
    // =========================================================
    // fromArray / 기본 getter
    // =========================================================

    public function testFromArrayCreatesCoupon(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData());

        $this->assertInstanceOf(Coupon::class, $coupon);
    }

    public function testBasicFieldsAreMapped(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'coupon_id'       => 5,
            'coupon_group_id' => 20,
            'member_id'       => 42,
            'coupon_number'   => 'CPN-TEST9999',
        ]));

        $this->assertSame(5, $coupon->getCouponId());
        $this->assertSame(20, $coupon->getCouponGroupId());
        $this->assertSame(42, $coupon->getMemberId());
        $this->assertSame('CPN-TEST9999', $coupon->getCouponNumber());
    }

    public function testUsageFieldsAreMapped(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'is_used'     => true,
            'used_at'     => '2025-02-01 10:00:00',
            'order_no'    => 'ORD2025020100001',
            'used_amount' => 5000,
        ]));

        $this->assertTrue($coupon->isUsed());
        $this->assertSame('2025-02-01 10:00:00', $coupon->getUsedAt());
        $this->assertSame('ORD2025020100001', $coupon->getOrderNo());
        $this->assertSame(5000, $coupon->getUsedAmount());
    }

    public function testStatusFieldIsMapped(): void
    {
        $issued  = Coupon::fromArray($this->makeCouponData(['status' => 'ISSUED']));
        $expired = Coupon::fromArray($this->makeCouponData(['status' => 'EXPIRED']));

        $this->assertSame('ISSUED', $issued->getStatus());
        $this->assertSame('EXPIRED', $expired->getStatus());
    }

    // =========================================================
    // isExpired()
    // =========================================================

    public function testIsExpiredReturnsFalseForFutureDate(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'valid_until' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]));

        $this->assertFalse($coupon->isExpired());
    }

    public function testIsExpiredReturnsTrueForPastDate(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'valid_until' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]));

        $this->assertTrue($coupon->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenValidUntilIsEmpty(): void
    {
        // validUntil이 비어 있으면 만료 없음으로 처리
        $coupon = Coupon::fromArray($this->makeCouponData(['valid_until' => '']));

        $this->assertFalse($coupon->isExpired());
    }

    // =========================================================
    // isUsable()
    // =========================================================

    public function testIsUsableReturnsTrueWhenNotUsedAndNotExpired(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'is_used'     => false,
            'valid_until' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]));

        $this->assertTrue($coupon->isUsable());
    }

    public function testIsUsableReturnsFalseWhenAlreadyUsed(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'is_used'     => true,
            'valid_until' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]));

        $this->assertFalse($coupon->isUsable());
    }

    public function testIsUsableReturnsFalseWhenExpired(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'is_used'     => false,
            'valid_until' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]));

        $this->assertFalse($coupon->isUsable());
    }

    public function testIsUsableReturnsFalseWhenUsedAndExpired(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData([
            'is_used'     => true,
            'valid_until' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]));

        $this->assertFalse($coupon->isUsable());
    }

    // =========================================================
    // toArray 라운드트립
    // =========================================================

    public function testToArrayContainsExpectedKeys(): void
    {
        $coupon = Coupon::fromArray($this->makeCouponData());
        $array  = $coupon->toArray();

        $expectedKeys = [
            'coupon_id', 'coupon_group_id', 'member_id', 'coupon_number',
            'issued_at', 'valid_until', 'is_used', 'used_at',
            'order_no', 'used_amount', 'status', 'created_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "toArray()에 '{$key}' 키가 없습니다.");
        }
    }
}
