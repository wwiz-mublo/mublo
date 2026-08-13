<?php
/**
 * packages/Shop/tests/Unit/Service/PriceCalculatorTest.php
 *
 * PriceCalculator 서비스 단위 테스트
 *
 * PriceCalculator는 DB 의존성이 없는 순수 계산 클래스이므로
 * Mock 없이 직접 인스턴스화하여 테스트합니다.
 *
 * 검증 항목:
 * - calculateSalesPrice() — 할인가 계산 (정률/정액/기본설정/없음)
 * - calculateRewardPoints() — 적립금 계산 (정률/정액)
 * - calculateShippingFee() — 배송비 계산 (무료/유료/조건부/수량/금액)
 * - calculatePaymentAmount() — 최종 결제 금액 계산
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator();
    }

    // =========================================================
    // calculateSalesPrice() — 할인가 계산
    // 규칙: 할인 유형이 계산 방식을 정한다 (값 크기로 추정하지 않는다)
    // =========================================================

    public function testCalculateSalesPriceWithNoDiscount(): void
    {
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::NONE, 0);

        $this->assertSame(20000, $result['sales_price']);
        $this->assertSame(0, $result['discount_amount']);
        $this->assertSame(0, $result['discount_percent']);
    }

    public function testCalculateSalesPriceWithZeroValue(): void
    {
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::PERCENTAGE, 0);

        // value가 0이면 할인 없음
        $this->assertSame(20000, $result['sales_price']);
        $this->assertSame(0, $result['discount_amount']);
    }

    public function testCalculateSalesPriceWithPercentageDiscount(): void
    {
        // 20000원, 10% 할인 → 판매가 18000원
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::PERCENTAGE, 10.0);

        $this->assertSame(18000, $result['sales_price']);
        $this->assertSame(2000, $result['discount_amount']);
        $this->assertSame(10, $result['discount_percent']);
    }

    public function testCalculateSalesPriceWithFixedDiscount(): void
    {
        // 20000원, 3000원 정액 할인 → 판매가 17000원
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::FIXED, 3000.0);

        $this->assertSame(17000, $result['sales_price']);
        $this->assertSame(3000, $result['discount_amount']);
    }

    public function testFixedDiscountUnder100IsNotTreatedAsPercentage(): void
    {
        // 정액 50원 할인. 값 크기로 방식을 추정하던 때는 50% 할인(10000원)이 됐다.
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::FIXED, 50.0);

        $this->assertSame(19950, $result['sales_price']);
        $this->assertSame(50, $result['discount_amount']);
    }

    public function testPercentageDiscountOf100IsNotTreatedAsFixedAmount(): void
    {
        // 정률 100% 할인. 예전 규칙에서는 100원 정액으로 뒤집혔다.
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::PERCENTAGE, 100.0);

        $this->assertSame(0, $result['sales_price']);
        $this->assertSame(20000, $result['discount_amount']);
        $this->assertSame(100, $result['discount_percent']);
    }

    public function testPercentageOver100IsCappedForDisplay(): void
    {
        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::PERCENTAGE, 150.0);

        $this->assertSame(0, $result['sales_price']);
        $this->assertSame(100, $result['discount_percent']);
    }

    public function testCalculateSalesPriceDoesNotGoBelowZero(): void
    {
        // 할인액이 상품가보다 크면 0원
        $result = $this->calculator->calculateSalesPrice(5000, DiscountType::FIXED, 10000.0);

        $this->assertSame(0, $result['sales_price']);
        $this->assertGreaterThanOrEqual(0, $result['sales_price']);
    }

    public function testCalculateSalesPriceWithBasicUsesShopConfig(): void
    {
        // BASIC(구 유형)은 shopConfig의 discount_value를 값 크기로 해석한다 — 하위호환 경로.
        // 상품 쪽 $value 는 보지 않으므로 0을 넘겨도 설정이 적용된다.
        $shopConfig = ['discount_value' => 20.0]; // 쇼핑몰 기본 20% 할인
        $result = $this->calculator->calculateSalesPrice(10000, DiscountType::BASIC, 0, $shopConfig);

        $this->assertSame(8000, $result['sales_price']);
        $this->assertSame(2000, $result['discount_amount']);
    }

    public function testCalculateSalesPriceWithBasicAndNoShopConfig(): void
    {
        // shopConfig에 discount_value가 없거나 0이면 BASIC은 할인 없음 처리
        $result = $this->calculator->calculateSalesPrice(10000, DiscountType::BASIC, 1.0, []);

        $this->assertSame(10000, $result['sales_price']);
        $this->assertSame(0, $result['discount_amount']);
    }

    // =========================================================
    // DEFAULT — 쇼핑몰 설정으로 재해석
    // =========================================================

    public function testDefaultResolvesThroughShopConfigType(): void
    {
        // 상품은 "쇼핑몰 기본설정 적용", 설정은 정액 1500원
        $shopConfig = ['discount_type' => 'FIXED', 'discount_value' => 1500.0];
        $result = $this->calculator->calculateSalesPrice(10000, DiscountType::DEFAULT, 0, $shopConfig);

        $this->assertSame(8500, $result['sales_price']);
        $this->assertSame(1500, $result['discount_amount']);
    }

    public function testDefaultWithoutShopConfigGivesNoDiscount(): void
    {
        $result = $this->calculator->calculateSalesPrice(10000, DiscountType::DEFAULT, 0, []);

        $this->assertSame(10000, $result['sales_price']);
    }

    public function testDefaultPointingBackToDefaultDoesNotRecurse(): void
    {
        // 설정이 다시 DEFAULT 를 가리키는 자기참조 — 할인 없음으로 끊는다
        $shopConfig = ['discount_type' => 'DEFAULT', 'discount_value' => 30.0];
        $result = $this->calculator->calculateSalesPrice(10000, DiscountType::DEFAULT, 0, $shopConfig);

        $this->assertSame(10000, $result['sales_price']);
        $this->assertSame(0, $result['discount_amount']);
    }

    // =========================================================
    // LEVEL — 등급별 할인
    // =========================================================

    public function testLevelDiscountAppliesSettingForMemberLevel(): void
    {
        $levelSettings = [
            3 => ['type' => 'PERCENTAGE', 'value' => 10.0],
            5 => ['type' => 'FIXED', 'value' => 2000.0],
        ];

        $gold = $this->calculator->calculateSalesPrice(20000, DiscountType::LEVEL, 0, [], 5, $levelSettings);
        $this->assertSame(18000, $gold['sales_price']);
        $this->assertSame(2000, $gold['discount_amount']);

        $silver = $this->calculator->calculateSalesPrice(20000, DiscountType::LEVEL, 0, [], 3, $levelSettings);
        $this->assertSame(18000, $silver['sales_price']);
        $this->assertSame(10, $silver['discount_percent']);
    }

    public function testLevelDiscountIsSkippedForGuestOrUnknownLevel(): void
    {
        $levelSettings = [3 => ['type' => 'PERCENTAGE', 'value' => 10.0]];

        // 비회원(levelId 없음)
        $guest = $this->calculator->calculateSalesPrice(20000, DiscountType::LEVEL, 0, [], null, $levelSettings);
        $this->assertSame(20000, $guest['sales_price']);

        // 설정이 없는 등급
        $other = $this->calculator->calculateSalesPrice(20000, DiscountType::LEVEL, 0, [], 9, $levelSettings);
        $this->assertSame(20000, $other['sales_price']);
    }

    public function testLevelSettingsAcceptStringKeysFromJson(): void
    {
        // JSON 디코드 결과는 키가 문자열일 수 있다
        $levelSettings = ['3' => ['type' => 'PERCENTAGE', 'value' => 25.0]];

        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::LEVEL, 0, [], 3, $levelSettings);

        $this->assertSame(15000, $result['sales_price']);
    }

    public function testDefaultResolvingToLevelUsesShopConfigLevelTable(): void
    {
        // 상품은 DEFAULT, 쇼핑몰 설정이 등급별 → 등급표도 설정 쪽 것을 쓴다
        $shopConfig = [
            'discount_type' => 'LEVEL',
            'discount_level_settings' => json_encode([7 => ['type' => 'FIXED', 'value' => 3000.0]]),
        ];

        $result = $this->calculator->calculateSalesPrice(20000, DiscountType::DEFAULT, 0, $shopConfig, 7);

        $this->assertSame(17000, $result['sales_price']);
        $this->assertSame(3000, $result['discount_amount']);
    }

    // =========================================================
    // calculateRewardPoints() — 적립금 계산
    // =========================================================

    public function testCalculateRewardPointsWithNoReward(): void
    {
        $result = $this->calculator->calculateRewardPoints(20000, RewardType::NONE, 0);

        $this->assertSame(0, $result['point_amount']);
        $this->assertSame(0, $result['reward_percent']);
    }

    public function testCalculateRewardPointsWithPercentage(): void
    {
        // 20000원, 5% 적립 → 1000포인트
        $result = $this->calculator->calculateRewardPoints(20000, RewardType::PERCENTAGE, 5.0);

        $this->assertSame(1000, $result['point_amount']);
        $this->assertSame(5, $result['reward_percent']);
    }

    public function testCalculateRewardPointsWithFixed(): void
    {
        $result = $this->calculator->calculateRewardPoints(20000, RewardType::FIXED, 500.0);

        $this->assertSame(500, $result['point_amount']);
    }

    public function testFixedRewardUnder100IsNotTreatedAsPercentage(): void
    {
        // 정액 50원 적립 — 예전 규칙에서는 50%(10000포인트)로 뒤집혔다
        $result = $this->calculator->calculateRewardPoints(20000, RewardType::FIXED, 50.0);

        $this->assertSame(50, $result['point_amount']);
    }

    public function testLevelRewardAppliesSettingForMemberLevel(): void
    {
        $levelSettings = [5 => ['type' => 'PERCENTAGE', 'value' => 7.0]];

        $result = $this->calculator->calculateRewardPoints(20000, RewardType::LEVEL, 0, [], 5, $levelSettings);

        $this->assertSame(1400, $result['point_amount']);
    }

    public function testDefaultRewardResolvesThroughShopConfigType(): void
    {
        $shopConfig = ['reward_type' => 'PERCENTAGE', 'reward_value' => 2.0];

        $result = $this->calculator->calculateRewardPoints(10000, RewardType::DEFAULT, 0, $shopConfig);

        $this->assertSame(200, $result['point_amount']);
    }

    public function testCalculateRewardPointsWithBasicUsesShopConfig(): void
    {
        // BASIC 타입은 shopConfig의 reward_value를 사용.
        // 초기 $value는 early-return 방지를 위해 1.0 전달.
        $shopConfig = ['reward_value' => 3.0]; // 기본 3% 적립
        $result = $this->calculator->calculateRewardPoints(10000, RewardType::BASIC, 1.0, $shopConfig);

        $this->assertSame(300, $result['point_amount']);
    }

    // =========================================================
    // calculateShippingFee() — 배송비 계산
    // =========================================================

    public function testFreeShipping(): void
    {
        $template = ['shipping_method' => 'FREE', 'basic_cost' => 3000];

        $fee = $this->calculator->calculateShippingFee($template, 10000, 1);

        $this->assertSame(0, $fee);
    }

    public function testPaidShipping(): void
    {
        $template = ['shipping_method' => 'PAID', 'basic_cost' => 3000];

        $fee = $this->calculator->calculateShippingFee($template, 10000, 1);

        $this->assertSame(3000, $fee);
    }

    public function testConditionalShippingBelowThreshold(): void
    {
        $template = [
            'shipping_method'  => 'COND',
            'basic_cost'       => 3000,
            'free_threshold'   => 50000,
        ];

        // 49000원 → 무료 기준 미달 → 3000원 부과
        $fee = $this->calculator->calculateShippingFee($template, 49000, 1);

        $this->assertSame(3000, $fee);
    }

    public function testConditionalShippingAboveThreshold(): void
    {
        $template = [
            'shipping_method'  => 'COND',
            'basic_cost'       => 3000,
            'free_threshold'   => 50000,
        ];

        // 50000원 이상 → 무료
        $fee = $this->calculator->calculateShippingFee($template, 50000, 1);

        $this->assertSame(0, $fee);
    }

    public function testQuantityBasedShipping(): void
    {
        $template = [
            'shipping_method' => 'QUANTITY',
            'basic_cost'      => 3000,
            'goods_per_unit'  => 3, // 3개당 3000원
        ];

        // 수량 3개 → ceil(3/3) * 3000 = 3000
        $this->assertSame(3000, $this->calculator->calculateShippingFee($template, 0, 3));

        // 수량 4개 → ceil(4/3) * 3000 = 6000
        $this->assertSame(6000, $this->calculator->calculateShippingFee($template, 0, 4));
    }

    public function testAmountBasedShippingWithPriceRanges(): void
    {
        $template = [
            'shipping_method' => 'AMOUNT',
            'basic_cost'      => 5000,
            'price_ranges'    => [
                ['min' => 0,     'max' => 9999,  'cost' => 3000],
                ['min' => 10000, 'max' => 29999, 'cost' => 2000],
                ['min' => 30000, 'max' => 99999, 'cost' => 1000],
            ],
        ];

        $this->assertSame(3000, $this->calculator->calculateShippingFee($template, 5000, 1));
        $this->assertSame(2000, $this->calculator->calculateShippingFee($template, 15000, 1));
        $this->assertSame(1000, $this->calculator->calculateShippingFee($template, 50000, 1));
    }

    public function testAmountBasedShippingFallsBackToBasicCost(): void
    {
        // 범위에 해당 없으면 basic_cost 사용
        $template = [
            'shipping_method' => 'AMOUNT',
            'basic_cost'      => 5000,
            'price_ranges'    => [
                ['min' => 0, 'max' => 9999, 'cost' => 3000],
            ],
        ];

        // 100000원은 범위 밖 → basic_cost = 5000
        $fee = $this->calculator->calculateShippingFee($template, 100000, 1);

        $this->assertSame(5000, $fee);
    }

    public function testAmountBasedShippingWithJsonStringRanges(): void
    {
        // price_ranges가 JSON 문자열로 저장된 경우 자동 파싱
        $template = [
            'shipping_method' => 'AMOUNT',
            'basic_cost'      => 5000,
            'price_ranges'    => json_encode([
                ['min' => 0, 'max' => 19999, 'cost' => 3000],
            ]),
        ];

        $fee = $this->calculator->calculateShippingFee($template, 10000, 1);

        $this->assertSame(3000, $fee);
    }

    // =========================================================
    // calculatePaymentAmount() — 최종 결제 금액
    // =========================================================

    public function testCalculatePaymentAmountBasic(): void
    {
        $orderData = [
            'total_price'     => 30000,
            'shipping_fee'    => 3000,
            'extra_price'     => 0,
            'tax_amount'      => 0,
            'point_used'      => 0,
            'coupon_discount' => 0,
        ];

        $this->assertSame(33000, $this->calculator->calculatePaymentAmount($orderData));
    }

    public function testCalculatePaymentAmountWithAllFields(): void
    {
        $orderData = [
            'total_price'     => 50000,
            'shipping_fee'    => 3000,
            'extra_price'     => 1000,
            'tax_amount'      => 500,
            'point_used'      => 2000,
            'coupon_discount' => 5000,
        ];

        // 50000 + 3000 + 1000 + 500 - 2000 - 5000 = 47500
        $this->assertSame(47500, $this->calculator->calculatePaymentAmount($orderData));
    }

    public function testCalculatePaymentAmountWithMissingFieldsDefaultsToZero(): void
    {
        // 누락된 필드는 0으로 처리
        $orderData = ['total_price' => 10000];

        $this->assertSame(10000, $this->calculator->calculatePaymentAmount($orderData));
    }
}
