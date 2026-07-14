<?php
/**
 * packages/Shop/tests/TestCase.php
 *
 * Shop 패키지 테스트 기본 클래스
 *
 * 외부 개발자가 Shop 패키지 테스트를 작성할 때 이 클래스를 상속합니다.
 * 상품/주문/쿠폰 등 공통 픽스처 생성 헬퍼를 제공합니다.
 */

namespace Tests\Shop;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // =========================================================
    // 픽스처 헬퍼 — Entity::fromArray() 테스트에 사용
    // =========================================================

    /**
     * 기본 상품 배열 픽스처
     *
     * @param array $overrides 일부 필드만 오버라이드할 때 사용
     */
    protected function makeProductData(array $overrides = []): array
    {
        return array_merge([
            'goods_id'            => 1,
            'domain_id'           => 1,
            'category_code'       => 'CAT001',
            'category_code_extra' => null,
            'item_code'           => 'ITEM001',
            'goods_name'          => '테스트 상품',
            'goods_slug'          => 'test-product',
            'goods_origin'        => null,
            'goods_manufacturer'  => null,
            'goods_code'          => null,
            'goods_badge'         => null,
            'goods_icon'          => null,
            'goods_filter'        => null,
            'goods_tags'          => null,
            'origin_price'        => 20000,
            'display_price'       => 20000,
            'discount_type'       => 'NONE',
            'discount_value'      => 0.0,
            'reward_type'         => 'NONE',
            'reward_value'        => 0.0,
            'reward_review'       => 0,
            'allowed_coupon'      => true,
            'stock_quantity'      => 100,
            'option_mode'         => 'NONE',
            'shipping_template_id'=> null,
            'shipping_apply_type' => 'COMBINED',
            'seller_id'           => null,
            'supply_id'           => null,
            'hit'                 => 0,
            'is_active'           => true,
            'created_at'          => '2025-01-01 00:00:00',
            'updated_at'          => null,
        ], $overrides);
    }

    /**
     * 기본 주문 배열 픽스처
     */
    protected function makeOrderData(array $overrides = []): array
    {
        return array_merge([
            'order_no'          => 'ORD2025010100001',
            'domain_id'         => 1,
            'cart_session_id'   => null,
            'member_id'         => 42,
            'total_price'       => 30000,
            'extra_price'       => 0,
            'point_used'        => 0,
            'coupon_discount'   => 0,
            'coupon_id'         => null,
            'shipping_fee'      => 3000,
            'tax_amount'        => 0,
            'orderer_name'      => '홍길동',
            'orderer_phone'     => '01012345678',
            'orderer_email'     => 'test@example.com',
            'shipping_zip'      => '12345',
            'shipping_address1' => '서울시 강남구',
            'shipping_address2' => '101동',
            'recipient_name'    => '홍길동',
            'recipient_phone'   => '01012345678',
            'payment_gateway'   => 'iamport',
            'payment_method'    => 'CARD',
            'order_status'      => 'paid',
            'review_status'     => 'NONE',
            'review_point'      => 0,
            'order_memo'        => null,
            'is_complete'       => false,
            'is_direct_order'   => false,
            'created_at'        => '2025-01-01 00:00:00',
            'updated_at'        => null,
        ], $overrides);
    }

    /**
     * 기본 장바구니 아이템 배열 픽스처
     */
    protected function makeCartItemData(array $overrides = []): array
    {
        return array_merge([
            'cart_item_id'   => 10,
            'cart_session_id'=> 'sess_abc123',
            'member_id'      => 0,
            'goods_id'       => 1,
            'option_mode'    => 'NONE',
            'option_id'      => 0,
            'option_code'    => null,
            'option_type'    => 'BASIC',
            'goods_price'    => 20000,
            'option_price'   => 0,
            'total_price'    => 20000,
            'quantity'       => 1,
            'point_amount'   => 0,
            'cart_status'    => 'PENDING',
            'created_at'     => '2025-01-01 00:00:00',
            'updated_at'     => null,
        ], $overrides);
    }

    /**
     * 기본 쿠폰 배열 픽스처
     */
    protected function makeCouponData(array $overrides = []): array
    {
        return array_merge([
            'coupon_id'       => 1,
            'coupon_group_id' => 10,
            'member_id'       => 42,
            'coupon_number'   => 'CPN-ABCD1234',
            'issued_at'       => '2025-01-01 00:00:00',
            'valid_until'     => date('Y-m-d H:i:s', strtotime('+30 days')),
            'is_used'         => false,
            'used_at'         => null,
            'order_no'        => null,
            'used_amount'     => 0,
            'status'          => 'ISSUED',
            'staff_id'        => null,
            'created_at'      => '2025-01-01 00:00:00',
            'updated_at'      => null,
        ], $overrides);
    }
}
