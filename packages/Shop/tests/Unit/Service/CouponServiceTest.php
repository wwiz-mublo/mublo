<?php
/**
 * packages/Shop/tests/Unit/Service/CouponServiceTest.php
 *
 * CouponService 단위 테스트
 *
 * 검증 항목:
 * - create()       — 이름 누락 거부, 할인값 0 거부, 기간 역전 거부, 성공
 * - issueCoupon()  — 비활성 거부, 발행 기간 외 거부, 한도 초과 거부, 성공
 * - useCoupon()    — 이미 사용됨 거부, 만료됨 거부, 최소금액 미달 거부, 성공
 * - restoreCoupon() — 미사용 거부, 만료 시 EXPIRED 처리, 유효 시 ISSUED 복원
 * - registerByPromotionCode() — 코드 없음 거부, 유효하지 않은 코드 거부, 성공 시 issueCoupon 호출
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Repository\CouponRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Entity\Coupon;
use Mublo\Packages\Shop\Entity\CouponPolicy;
use Mublo\Infrastructure\Database\Database;

class CouponServiceTest extends TestCase
{
    private CouponRepository $couponRepo;
    private OrderRepository $orderRepo;
    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->couponRepo = $this->createMock(CouponRepository::class);
        $this->orderRepo  = $this->createMock(OrderRepository::class);

        // 발급 원자화(FOR UPDATE + transaction)를 위한 DB 모의: transaction은 클로저를
        // 그대로 실행하고, select(잠금)는 no-op.
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn(callable $fn) => $fn());
        $db->method('select')->willReturn([]);
        $this->couponRepo->method('getDb')->willReturn($db);

        $this->service    = new CouponService($this->couponRepo, $this->orderRepo);
    }

    private function makePolicyEntity(array $overrides = []): CouponPolicy
    {
        return CouponPolicy::fromArray(array_merge([
            'coupon_group_id'          => 1,
            'domain_id'                => 1,
            'name'                     => '테스트쿠폰',
            'coupon_type'              => 'DOWNLOAD',
            'coupon_method'            => 'ORDER',
            'discount_type'            => 'FIXED',
            'discount_value'           => 2000,
            'max_discount'             => null,
            'min_order_amount'         => 0,
            'issue_start'              => null,
            'issue_end'                => null,
            'valid_days'               => 30,
            'download_limit_per_member'=> 1,
            'use_limit_per_member'     => 1,
            'total_issue_limit'        => null,
            'allowed_member_levels'    => '',
            'first_order_only'         => false,
            'duplicate_policy'         => 'ALLOW',
            'coupon_method_target'     => null,
            'target_goods_id'          => null,
            'target_category'          => null,
            'excluded_goods'           => null,
            'excluded_categories'      => null,
            'auto_issue_trigger'       => null,
            'promotion_code'           => null,
            'is_active'                => true,
            'staff_id'                 => null,
            'created_at'               => '2026-01-01 00:00:00',
        ], $overrides));
    }

    private function makeCouponEntity(array $overrides = []): Coupon
    {
        return Coupon::fromArray($this->makeCouponData($overrides));
    }

    // =========================================================
    // create()
    // =========================================================

    public function testCreateFailsWhenNameIsEmpty(): void
    {
        $result = $this->service->create(1, ['name' => '', 'discount_value' => 1000]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이름', $result->getMessage());
    }

    public function testCreateFailsWhenDiscountValueIsZero(): void
    {
        $result = $this->service->create(1, ['name' => '쿠폰', 'discount_value' => 0]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('할인', $result->getMessage());
    }

    public function testCreateFailsWhenIssueStartAfterEnd(): void
    {
        $result = $this->service->create(1, [
            'name'          => '쿠폰',
            'discount_value'=> 1000,
            'issue_start'   => '2026-12-31',
            'issue_end'     => '2026-01-01',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('시작일', $result->getMessage());
    }

    public function testCreateSuccessReturnsCouponGroupId(): void
    {
        $this->couponRepo->method('create')->willReturn(5);

        $result = $this->service->create(1, [
            'name'           => '신규 할인',
            'discount_value' => 1000,
            'is_active'      => true,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(5, $result->get('coupon_group_id'));
    }

    // =========================================================
    // issueCoupon()
    // =========================================================

    public function testIssueCouponFailsWhenPolicyNotFound(): void
    {
        $this->couponRepo->method('find')->willReturn(null);

        $result = $this->service->issueCoupon(99, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    public function testIssueCouponFailsWhenPolicyIsInactive(): void
    {
        $policy = $this->makePolicyEntity(['is_active' => false]);
        $this->couponRepo->method('find')->willReturn($policy);

        $result = $this->service->issueCoupon(1, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('발행이 중지', $result->getMessage());
    }

    public function testIssueCouponFailsWhenBeforeIssueStart(): void
    {
        $policy = $this->makePolicyEntity([
            'issue_start' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $this->couponRepo->method('find')->willReturn($policy);

        $result = $this->service->issueCoupon(1, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('기간', $result->getMessage());
    }

    public function testIssueCouponFailsWhenAfterIssueEnd(): void
    {
        $policy = $this->makePolicyEntity([
            'issue_end' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $this->couponRepo->method('find')->willReturn($policy);

        $result = $this->service->issueCoupon(1, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('종료', $result->getMessage());
    }

    public function testIssueCouponFailsWhenTotalLimitExceeded(): void
    {
        $policy = $this->makePolicyEntity(['total_issue_limit' => 10]);
        $this->couponRepo->method('find')->willReturn($policy);
        $this->couponRepo->method('getTotalIssuedCount')->willReturn(10);

        $result = $this->service->issueCoupon(1, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('소진', $result->getMessage());
    }

    public function testIssueCouponFailsWhenMemberLimitExceeded(): void
    {
        $policy = $this->makePolicyEntity(['download_limit_per_member' => 1]);
        $this->couponRepo->method('find')->willReturn($policy);
        $this->couponRepo->method('getTotalIssuedCount')->willReturn(0);
        $this->couponRepo->method('getIssuedCount')->willReturn(1); // 이미 발행

        $result = $this->service->issueCoupon(1, 42);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('한도', $result->getMessage());
    }

    public function testIssueCouponSuccessReturnsCouponId(): void
    {
        $policy = $this->makePolicyEntity();
        $this->couponRepo->method('find')->willReturn($policy);
        $this->couponRepo->method('getTotalIssuedCount')->willReturn(0);
        $this->couponRepo->method('getIssuedCount')->willReturn(0);
        $this->couponRepo->method('issueCoupon')->willReturn(7);

        $result = $this->service->issueCoupon(1, 42);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->get('coupon_id'));
        $this->assertNotEmpty($result->get('coupon_number'));
    }

    // =========================================================
    // useCoupon()
    // =========================================================

    public function testUseCouponFailsWhenCouponNotFound(): void
    {
        $this->couponRepo->method('findIssuedCoupon')->willReturn(null);

        $result = $this->service->useCoupon(99, 'ORD001', 30000);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    public function testUseCouponFailsWhenAlreadyUsed(): void
    {
        $coupon = $this->makeCouponEntity(['is_used' => true, 'status' => 'USED']);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());

        $result = $this->service->useCoupon(1, 'ORD001', 30000, 42);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미 사용', $result->getMessage());
    }

    public function testUseCouponFailsWhenExpired(): void
    {
        $coupon = $this->makeCouponEntity([
            'is_used'     => false,
            'valid_until' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'status'      => 'ISSUED',
        ]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());

        $result = $this->service->useCoupon(1, 'ORD001', 30000, 42);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('만료', $result->getMessage());
    }

    public function testUseCouponFailsWhenOrderAmountBelowMinimum(): void
    {
        $coupon = $this->makeCouponEntity(['is_used' => false, 'status' => 'ISSUED']);
        $policy = $this->makePolicyEntity(['min_order_amount' => 50000]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());
        $this->couponRepo->method('find')->willReturn($policy);

        $result = $this->service->useCoupon(1, 'ORD001', 10000, 42);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('최소', $result->getMessage());
    }

    public function testUseCouponSuccessReturnsDiscountAmount(): void
    {
        $coupon = $this->makeCouponEntity(['is_used' => false, 'status' => 'ISSUED']);
        $policy = $this->makePolicyEntity([
            'discount_type'  => 'FIXED',
            'discount_value' => 3000,
            'min_order_amount' => 0,
        ]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());
        $this->couponRepo->method('find')->willReturn($policy);
        $this->couponRepo->method('getCouponsByOrderNo')->willReturn([]);
        $this->couponRepo->method('useCoupon')->willReturn(true);

        $result = $this->service->useCoupon(1, 'ORD001', 30000, 42);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(3000, $result->get('discount_amount'));
    }

    public function testUseCouponPercentageDiscountCalculation(): void
    {
        $coupon = $this->makeCouponEntity(['is_used' => false, 'status' => 'ISSUED']);
        $policy = $this->makePolicyEntity([
            'discount_type'  => 'PERCENTAGE',
            'discount_value' => 10,    // 10%
            'max_discount'   => null,
            'min_order_amount' => 0,
        ]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());
        $this->couponRepo->method('find')->willReturn($policy);
        $this->couponRepo->method('getCouponsByOrderNo')->willReturn([]);
        $this->couponRepo->method('useCoupon')->willReturn(true);

        $result = $this->service->useCoupon(1, 'ORD001', 50000, 42);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(5000, $result->get('discount_amount')); // 50000 * 10% = 5000
    }

    public function testUseCouponPercentageDiscountRespectMaxDiscount(): void
    {
        $coupon = $this->makeCouponEntity(['is_used' => false, 'status' => 'ISSUED']);
        $policy = $this->makePolicyEntity([
            'discount_type'  => 'PERCENTAGE',
            'discount_value' => 50,    // 50%
            'max_discount'   => 2000,  // 최대 2000원
            'min_order_amount' => 0,
        ]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());
        $this->couponRepo->method('find')->willReturn($policy);
        $this->couponRepo->method('getCouponsByOrderNo')->willReturn([]);
        $this->couponRepo->method('useCoupon')->willReturn(true);

        $result = $this->service->useCoupon(1, 'ORD001', 20000, 42);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2000, $result->get('discount_amount')); // 10000원이지만 max 2000
    }

    public function testPreviewCouponRejectsForeignMemberCoupon(): void
    {
        // 쿠폰은 회원 42 소유인데 회원 99가 사용 시도 → 도용 차단.
        // 비소유자에게 존재/상태를 노출하지 않도록 '찾을 수 없음'으로 응답해야 한다.
        $coupon = $this->makeCouponEntity(['is_used' => false, 'status' => 'ISSUED']); // member_id=42
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());

        $result = $this->service->previewCoupon(1, '', 30000, 99);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    // =========================================================
    // restoreCoupon()
    // =========================================================

    public function testRestoreCouponFailsWhenNotUsed(): void
    {
        $coupon = $this->makeCouponEntity(['is_used' => false, 'status' => 'ISSUED']);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());

        $result = $this->service->restoreCoupon(1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('사용되지 않은', $result->getMessage());
    }

    public function testRestoreCouponSetsExpiredWhenOverdue(): void
    {
        $coupon = $this->makeCouponEntity([
            'is_used'     => true,
            'status'      => 'USED',
            'valid_until' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());
        $this->couponRepo->expects($this->once())->method('expireCoupon');

        $result = $this->service->restoreCoupon(1);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->get('restored'));
    }

    public function testRestoreCouponRestoresValidCoupon(): void
    {
        $coupon = $this->makeCouponEntity([
            'is_used'     => true,
            'status'      => 'USED',
            'valid_until' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        $this->couponRepo->method('findIssuedCoupon')->willReturn($coupon->toArray());
        $this->couponRepo->method('restoreCoupon')->willReturn(true);

        $result = $this->service->restoreCoupon(1);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->get('restored'));
    }

    // =========================================================
    // registerByPromotionCode()
    // =========================================================

    public function testRegisterByPromotionCodeFailsWhenCodeIsEmpty(): void
    {
        $result = $this->service->registerByPromotionCode('', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('코드', $result->getMessage());
    }

    public function testRegisterByPromotionCodeFailsWhenCodeNotFound(): void
    {
        $this->couponRepo->method('findByPromotionCode')->willReturn(null);

        $result = $this->service->registerByPromotionCode('INVALID123', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('유효하지 않은', $result->getMessage());
    }

    // =========================================================
    // previewCouponStack() — 복수 쿠폰 스택
    // =========================================================

    /**
     * 여러 정책을 group_id 별로 매핑해 findIssuedCoupon/find를 callback으로 응답하도록 세팅.
     * (coupon_id == coupon_group_id 로 단순화)
     *
     * @param array<int,CouponPolicy> $policiesByGroupId
     */
    private function stubStackRepo(array $policiesByGroupId): void
    {
        $this->couponRepo->method('findIssuedCoupon')->willReturnCallback(
            fn($id) => $this->makeCouponData(['coupon_id' => $id, 'coupon_group_id' => $id, 'is_used' => false, 'status' => 'ISSUED'])
        );
        $this->couponRepo->method('find')->willReturnCallback(
            fn($gid) => $policiesByGroupId[$gid] ?? null
        );
        $this->couponRepo->method('getCouponsByOrderNo')->willReturn([]);
        $this->couponRepo->method('getUsedCount')->willReturn(0);
    }

    public function testPreviewCouponStackSumsIndependentDiscounts(): void
    {
        $this->stubStackRepo([
            1 => $this->makePolicyEntity(['coupon_group_id' => 1, 'coupon_method' => 'ORDER', 'discount_type' => 'PERCENTAGE', 'discount_value' => 10, 'duplicate_policy' => 'DENY_SAME_METHOD']),
            2 => $this->makePolicyEntity(['coupon_group_id' => 2, 'coupon_method' => 'SHIPPING', 'discount_type' => 'FIXED', 'discount_value' => 2000, 'duplicate_policy' => 'DENY_SAME_METHOD']),
        ]);

        // 상품 10000 / 배송 3000 → ORDER 10%(1000) + SHIPPING 2000 = 3000
        $result = $this->service->previewCouponStack([1, 2], 10000, 42, 0, [], 3000);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(3000, $result->get('total_discount'));
        $this->assertCount(2, $result->get('breakdown'));
    }

    public function testPreviewCouponStackRejectsSameMethodWhenDenySameMethod(): void
    {
        $this->stubStackRepo([
            1 => $this->makePolicyEntity(['coupon_group_id' => 1, 'coupon_method' => 'ORDER', 'discount_type' => 'PERCENTAGE', 'discount_value' => 10, 'duplicate_policy' => 'DENY_SAME_METHOD']),
            3 => $this->makePolicyEntity(['coupon_group_id' => 3, 'coupon_method' => 'ORDER', 'discount_type' => 'FIXED', 'discount_value' => 2000, 'duplicate_policy' => 'DENY_SAME_METHOD']),
        ]);

        $result = $this->service->previewCouponStack([1, 3], 10000, 42, 0, [], 3000);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('동일한 유형', $result->getMessage());
    }

    public function testPreviewCouponStackRejectsDenyAll(): void
    {
        $this->stubStackRepo([
            4 => $this->makePolicyEntity(['coupon_group_id' => 4, 'coupon_method' => 'ORDER', 'discount_type' => 'FIXED', 'discount_value' => 1000, 'duplicate_policy' => 'DENY_ALL']),
            2 => $this->makePolicyEntity(['coupon_group_id' => 2, 'coupon_method' => 'SHIPPING', 'discount_type' => 'FIXED', 'discount_value' => 2000, 'duplicate_policy' => 'DENY_SAME_METHOD']),
        ]);

        $result = $this->service->previewCouponStack([4, 2], 10000, 42, 0, [], 3000);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('함께 사용할 수 없', $result->getMessage());
    }

    public function testPreviewCouponStackClampsProductPool(): void
    {
        // 상품 2000원에 ORDER 2000(FIXED) + GOODS 1500(FIXED), 둘 다 ALLOW
        // → 상품풀 그리디: ORDER 2000 소진 후 GOODS 0 → 적용 제외. 합계 2000, 1장.
        $this->stubStackRepo([
            5 => $this->makePolicyEntity(['coupon_group_id' => 5, 'coupon_method' => 'ORDER', 'discount_type' => 'FIXED', 'discount_value' => 2000, 'duplicate_policy' => 'ALLOW']),
            6 => $this->makePolicyEntity(['coupon_group_id' => 6, 'coupon_method' => 'GOODS', 'discount_type' => 'FIXED', 'discount_value' => 1500, 'duplicate_policy' => 'ALLOW']),
        ]);

        $result = $this->service->previewCouponStack([5, 6], 2000, 42, 0, [['goods_id' => 1, 'category_code' => '']], 3000);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2000, $result->get('total_discount'));
        $this->assertCount(1, $result->get('breakdown'));
    }

    public function testMarkCouponsUsedForOrderMarksEach(): void
    {
        $this->couponRepo->expects($this->exactly(2))
            ->method('useCoupon')
            ->willReturn(true);

        $result = $this->service->markCouponsUsedForOrder('ORD777', [
            ['coupon_id' => 1, 'discount' => 1000],
            ['coupon_id' => 2, 'discount' => 2000],
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->get('count'));
    }

    // 멱등: 이미 '같은 주문'으로 claim된 쿠폰을 다시 마킹하면 성공으로 간주한다
    // (PG 경로: 주문 생성 시 claim → 결제 완료 이벤트에서 재마킹).
    public function testMarkCouponUsedIsIdempotentForSameOrder(): void
    {
        $this->couponRepo->method('useCoupon')->willReturn(false); // 이미 사용됨
        $this->couponRepo->method('findIssuedCoupon')->willReturn([
            'coupon_id' => 1,
            'is_used'   => 1,
            'order_no'  => 'ORD777',
        ]);

        $result = $this->service->markCouponUsedForOrder(1, 'ORD777', 1000);

        $this->assertTrue($result->isSuccess());
    }

    // 이중 사용 차단: '다른 주문'이 이미 claim한 쿠폰이면 실패로 남긴다.
    public function testMarkCouponUsedFailsWhenClaimedByAnotherOrder(): void
    {
        $this->couponRepo->method('useCoupon')->willReturn(false);
        $this->couponRepo->method('findIssuedCoupon')->willReturn([
            'coupon_id' => 1,
            'is_used'   => 1,
            'order_no'  => 'OTHER_ORDER',
        ]);

        $result = $this->service->markCouponUsedForOrder(1, 'ORD777', 1000);

        $this->assertTrue($result->isFailure());
    }
}
