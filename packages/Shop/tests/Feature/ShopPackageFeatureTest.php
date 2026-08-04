<?php
/**
 * packages/Shop/tests/Feature/ShopPackageFeatureTest.php
 *
 * Shop 패키지 Feature 테스트
 *
 * 외부 개발자가 패키지를 포크하거나 커스터마이징할 때 가장 먼저 실행할 테스트입니다.
 * 패키지의 핵심 클래스들이 정상적으로 로드되는지 검증합니다.
 *
 * 검증 항목:
 * - 패키지 구조 — manifest.json 존재, 필수 필드
 * - 핵심 클래스 로드 가능 여부
 * - 서비스 클래스들의 생성자 파라미터 (DI 계약 검증)
 * - Enum 케이스 일관성 (OrderAction과 defaultStates() 동기화)
 * - PriceCalculator 엔드-투-엔드 시나리오
 */

namespace Tests\Shop\Feature;

use Tests\Shop\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ShopPackageFeatureTest extends TestCase
{
    // =========================================================
    // 패키지 구조 검증
    // =========================================================

    public function testManifestFileExists(): void
    {
        $manifestPath = MUBLO_PACKAGE_PATH . '/Shop/manifest.json';

        $this->assertFileExists($manifestPath, 'manifest.json 파일이 없습니다.');
    }

    public function testManifestHasRequiredFields(): void
    {
        $manifestPath = MUBLO_PACKAGE_PATH . '/Shop/manifest.json';
        $manifest = json_decode(file_get_contents($manifestPath), true);

        $requiredFields = ['name', 'label', 'description', 'version', 'author'];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $manifest, "manifest.json에 '{$field}' 필드가 없습니다.");
            $this->assertNotEmpty($manifest[$field], "manifest.json의 '{$field}' 값이 비어 있습니다.");
        }
    }

    public function testManifestNameIsShop(): void
    {
        $manifest = json_decode(
            file_get_contents(MUBLO_PACKAGE_PATH . '/Shop/manifest.json'),
            true
        );

        $this->assertSame('Shop', $manifest['name']);
    }

    // =========================================================
    // 핵심 클래스 로드 검증
    // =========================================================

    #[DataProvider('provideEntityClasses')]
    public function testEntityClassesAreLoadable(string $className): void
    {
        $this->assertTrue(class_exists($className), "{$className} 클래스를 로드할 수 없습니다.");
    }

    public static function provideEntityClasses(): array
    {
        return [
            'Product'          => [\Mublo\Packages\Shop\Entity\Product::class],
            'Order'            => [\Mublo\Packages\Shop\Entity\Order::class],
            'CartItem'         => [\Mublo\Packages\Shop\Entity\CartItem::class],
            'Coupon'           => [\Mublo\Packages\Shop\Entity\Coupon::class],
            'OrderItem'        => [\Mublo\Packages\Shop\Entity\OrderItem::class],
            'ShippingTemplate' => [\Mublo\Packages\Shop\Entity\ShippingTemplate::class],
            'CategoryItem'     => [\Mublo\Packages\Shop\Entity\CategoryItem::class],
        ];
    }

    #[DataProvider('provideEnumClasses')]
    public function testEnumClassesAreLoadable(string $className): void
    {
        $this->assertTrue(enum_exists($className), "{$className} Enum을 로드할 수 없습니다.");
    }

    public static function provideEnumClasses(): array
    {
        return [
            'OrderAction'   => [\Mublo\Packages\Shop\Enum\OrderAction::class],
            'DiscountType'  => [\Mublo\Packages\Shop\Enum\DiscountType::class],
            'RewardType'    => [\Mublo\Packages\Shop\Enum\RewardType::class],
            'PaymentMethod' => [\Mublo\Packages\Shop\Enum\PaymentMethod::class],
            'ShippingMethod'=> [\Mublo\Packages\Shop\Enum\ShippingMethod::class],
            'OptionMode'    => [\Mublo\Packages\Shop\Enum\OptionMode::class],
            'CouponType'    => [\Mublo\Packages\Shop\Enum\CouponType::class],
        ];
    }

    #[DataProvider('provideServiceClasses')]
    public function testServiceClassesAreLoadable(string $className): void
    {
        $this->assertTrue(class_exists($className), "{$className} 클래스를 로드할 수 없습니다.");
    }

    public static function provideServiceClasses(): array
    {
        return [
            'PriceCalculator' => [\Mublo\Packages\Shop\Service\PriceCalculator::class],
            'ReviewService'   => [\Mublo\Packages\Shop\Service\ReviewService::class],
            'ShippingService' => [\Mublo\Packages\Shop\Service\ShippingService::class],
            'ProductService'  => [\Mublo\Packages\Shop\Service\ProductService::class],
            'OrderService'    => [\Mublo\Packages\Shop\Service\OrderService::class],
            'CartService'     => [\Mublo\Packages\Shop\Service\CartService::class],
            'CouponService'   => [\Mublo\Packages\Shop\Service\CouponService::class],
            'CategoryService' => [\Mublo\Packages\Shop\Service\CategoryService::class],
        ];
    }

    // =========================================================
    // Enum 일관성 검증
    // =========================================================

    /**
     * defaultStates()의 id가 실제 OrderAction 케이스와 동기화되는지 확인
     */
    public function testOrderActionDefaultStatesAreConsistentWithEnumValues(): void
    {
        $states = \Mublo\Packages\Shop\Enum\OrderAction::defaultStates();
        $enumValues = array_map(
            fn(\Mublo\Packages\Shop\Enum\OrderAction $a) => $a->value,
            \Mublo\Packages\Shop\Enum\OrderAction::cases()
        );

        foreach ($states as $state) {
            $this->assertContains(
                $state['action'],
                $enumValues,
                "defaultStates()의 action '{$state['action']}'이 OrderAction에 존재하지 않습니다."
            );
        }
    }

    /**
     * 모든 terminal=true 상태는 to=[] 이어야 함
     */
    public function testTerminalStatesHaveNoTransitions(): void
    {
        $states = \Mublo\Packages\Shop\Enum\OrderAction::defaultStates();

        foreach ($states as $state) {
            if ($state['terminal']) {
                $this->assertEmpty(
                    $state['to'],
                    "종료 상태 '{$state['id']}'의 to 배열이 비어 있지 않습니다."
                );
            }
        }
    }

    // =========================================================
    // PriceCalculator 엔드-투-엔드 시나리오
    // =========================================================

    /**
     * 시나리오: 10% 할인 상품, 3% 적립, 5만원 이상 무료 배송
     */
    public function testPriceCalculatorFullScenario(): void
    {
        $calculator = new \Mublo\Packages\Shop\Service\PriceCalculator();

        $displayPrice = 30000;

        // 1. 할인가 계산
        $discountResult = $calculator->calculateSalesPrice(
            $displayPrice,
            \Mublo\Packages\Shop\Enum\DiscountType::PERCENTAGE,
            10.0
        );
        $salesPrice = $discountResult['sales_price']; // 27000

        $this->assertSame(27000, $salesPrice);
        $this->assertSame(3000, $discountResult['discount_amount']);

        // 2. 적립금 계산
        $rewardResult = $calculator->calculateRewardPoints(
            $salesPrice,
            \Mublo\Packages\Shop\Enum\RewardType::PERCENTAGE,
            3.0
        );

        $this->assertSame(810, $rewardResult['point_amount']); // 27000 * 3% = 810

        // 3. 배송비 계산 (조건부 무료, 30000원 미만이므로 유료)
        $template = [
            'shipping_method' => 'COND',
            'basic_cost'      => 3000,
            'free_threshold'  => 50000,
        ];
        $shippingFee = $calculator->calculateShippingFee($template, $salesPrice, 1);

        $this->assertSame(3000, $shippingFee);

        // 4. 최종 결제 금액
        $finalAmount = $calculator->calculatePaymentAmount([
            'total_price'     => $salesPrice,
            'shipping_fee'    => $shippingFee,
            'extra_price'     => 0,
            'tax_amount'      => 0,
            'point_used'      => 0,
            'coupon_discount' => 0,
        ]);

        $this->assertSame(30000, $finalAmount); // 27000 + 3000
    }

    /**
     * 시나리오: 포인트 + 쿠폰 동시 적용 시 최종 결제 금액
     */
    public function testPaymentAmountWithPointAndCouponApplied(): void
    {
        $calculator = new \Mublo\Packages\Shop\Service\PriceCalculator();

        $finalAmount = $calculator->calculatePaymentAmount([
            'total_price'     => 50000,
            'shipping_fee'    => 0,      // 무료 배송
            'extra_price'     => 0,
            'tax_amount'      => 0,
            'point_used'      => 3000,   // 3000포인트 사용
            'coupon_discount' => 5000,   // 5000원 쿠폰
        ]);

        $this->assertSame(42000, $finalAmount); // 50000 - 3000 - 5000
    }
}
