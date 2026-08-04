<?php
/**
 * packages/Shop/tests/Unit/Service/ShippingFeeCalculatorTest.php
 *
 * ShippingFeeCalculator 단위 테스트
 *
 * ShippingRepository / ShopConfigService 는 Mock, PriceCalculator 는 실제 인스턴스.
 *
 * 검증 항목:
 * - 상품 템플릿 우선 적용
 * - 상품 템플릿 없으면 쇼핑몰 기본 템플릿 적용
 * - 둘 다 없으면 미설정(unresolved)
 * - SEPARATE 적용방식 → 상품별 독립 그룹
 * - 혼합 템플릿 합산
 * - 빈 라인 → 0
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Repository\ShippingRepository;
use Mublo\Packages\Shop\Entity\ShippingTemplate;
use Mublo\Core\Result\Result;

class ShippingFeeCalculatorTest extends TestCase
{
    private ShippingRepository $shippingRepo;
    private ShopConfigService $shopConfig;
    private ShippingFeeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shippingRepo = $this->createMock(ShippingRepository::class);
        $this->shopConfig   = $this->createMock(ShopConfigService::class);
        $this->calculator   = new ShippingFeeCalculator(
            $this->shippingRepo,
            $this->shopConfig,
            new PriceCalculator()
        );
    }

    /**
     * 쇼핑몰 기본 배송 템플릿 ID 스텁
     */
    private function stubDefaultTemplateId(int $id): void
    {
        $this->shopConfig->method('getConfig')->willReturn(
            Result::success('', ['config' => ['default_shipping_template_id' => $id]])
        );
    }

    /**
     * shipping_id → ShippingTemplate 맵으로 도메인 제한 조회 스텁
     */
    private function stubTemplates(array $templatesById): void
    {
        $this->shippingRepo->method('findInDomain')->willReturnCallback(
            fn($domainId, $id, $activeOnly) => $templatesById[(int) $id] ?? null
        );
    }

    private function template(int $id, string $method, int $basicCost = 0, int $freeThreshold = 0, string $name = '템플릿'): ShippingTemplate
    {
        return ShippingTemplate::fromArray([
            'shipping_id'    => $id,
            'domain_id'      => 1,
            'name'           => $name,
            'shipping_method' => $method,
            'basic_cost'     => $basicCost,
            'free_threshold' => $freeThreshold,
        ]);
    }

    private function line(int $goodsId, ?int $templateId, int $total, int $qty, string $applyType = 'COMBINED'): array
    {
        return [
            'goods_id'             => $goodsId,
            'shipping_template_id' => $templateId,
            'shipping_apply_type'  => $applyType,
            'total_price'          => $total,
            'quantity'             => $qty,
        ];
    }

    // =========================================================

    public function testEmptyLinesReturnsZero(): void
    {
        $this->stubDefaultTemplateId(0);
        $result = $this->calculator->calculate(1, []);

        $this->assertSame(0, $result['total']);
        $this->assertFalse($result['unresolved']);
        $this->assertSame([], $result['groups']);
    }

    public function testProductTemplateTakesPriority(): void
    {
        // 상품 템플릿 10(유료 3000), config 기본은 20 — 10이 적용되어야 함
        $this->stubDefaultTemplateId(20);
        $this->stubTemplates([
            10 => $this->template(10, 'PAID', 3000),
            20 => $this->template(20, 'FREE'),
        ]);

        $result = $this->calculator->calculate(1, [$this->line(100, 10, 9000, 1)]);

        $this->assertFalse($result['unresolved']);
        $this->assertSame(3000, $result['total']);
    }

    public function testFallsBackToConfigDefaultTemplate(): void
    {
        // 상품 템플릿 없음 → config 기본 템플릿 20(무료) 적용
        $this->stubDefaultTemplateId(20);
        $this->stubTemplates([20 => $this->template(20, 'FREE')]);

        $result = $this->calculator->calculate(1, [$this->line(100, null, 9000, 1)]);

        $this->assertFalse($result['unresolved']);
        $this->assertSame(0, $result['total']);
    }

    public function testUnresolvedWhenNoTemplateAtAll(): void
    {
        // 상품 템플릿 없음 + config 기본도 0 → 미설정
        $this->stubDefaultTemplateId(0);

        $result = $this->calculator->calculate(1, [$this->line(100, null, 9000, 1)]);

        $this->assertTrue($result['unresolved']);
        $this->assertNull($result['total']);
        $group = reset($result['groups']);
        $this->assertTrue($group['unresolved']);
        $this->assertNull($group['shipping_fee']);
    }

    public function testUnresolvedWhenTemplateDeleted(): void
    {
        // 상품이 가리키는 템플릿 99가 DB에 없음(삭제됨) → 미설정
        $this->stubDefaultTemplateId(0);
        $this->stubTemplates([]); // find()는 항상 null

        $result = $this->calculator->calculate(1, [$this->line(100, 99, 9000, 1)]);

        $this->assertTrue($result['unresolved']);
        $this->assertNull($result['total']);
    }

    public function testSeparateApplyTypeCreatesMultipleGroups(): void
    {
        // 동일 템플릿이라도 SEPARATE면 상품별 독립 그룹 → 각각 배송비 부과
        $this->stubDefaultTemplateId(0);
        $this->stubTemplates([10 => $this->template(10, 'PAID', 3000)]);

        $result = $this->calculator->calculate(1, [
            $this->line(100, 10, 5000, 1, 'SEPARATE'),
            $this->line(200, 10, 5000, 1, 'SEPARATE'),
        ]);

        $this->assertFalse($result['unresolved']);
        $this->assertCount(2, $result['groups']);
        $this->assertSame(6000, $result['total']);
    }

    public function testCombinedSameTemplateIsOneGroup(): void
    {
        $this->stubDefaultTemplateId(0);
        $this->stubTemplates([10 => $this->template(10, 'PAID', 3000)]);

        $result = $this->calculator->calculate(1, [
            $this->line(100, 10, 5000, 1),
            $this->line(200, 10, 5000, 1),
        ]);

        $this->assertCount(1, $result['groups']);
        $this->assertSame(3000, $result['total']);
    }

    public function testMixedTemplatesSummed(): void
    {
        $this->stubDefaultTemplateId(0);
        $this->stubTemplates([
            10 => $this->template(10, 'PAID', 3000),
            11 => $this->template(11, 'PAID', 2000),
        ]);

        $result = $this->calculator->calculate(1, [
            $this->line(100, 10, 5000, 1),
            $this->line(200, 11, 5000, 1),
        ]);

        $this->assertFalse($result['unresolved']);
        $this->assertSame(5000, $result['total']);
        $this->assertCount(2, $result['groups']);
    }

    public function testConditionalFreeShippingPerGroup(): void
    {
        // 조건부 무료: 5만원 이상 무료, 미만 3000
        $this->stubDefaultTemplateId(0);
        $this->stubTemplates([10 => $this->template(10, 'COND', 3000, 50000)]);

        $below = $this->calculator->calculate(1, [$this->line(100, 10, 49000, 1)]);
        $this->assertSame(3000, $below['total']);

        $above = $this->calculator->calculate(1, [$this->line(100, 10, 50000, 1)]);
        $this->assertSame(0, $above['total']);

        // 그룹 출력에 조건 표시용 메타(shipping_method/free_threshold/group_total) 노출
        $group = reset($below['groups']);
        $this->assertSame('COND', $group['shipping_method']);
        $this->assertSame(50000, $group['free_threshold']);
        $this->assertSame(49000, $group['group_total']);
    }

    public function testGroupKeyHelper(): void
    {
        $this->assertSame('tpl_5', ShippingFeeCalculator::groupKey(5, 'COMBINED', 100));
        $this->assertSame('tpl_5_g100', ShippingFeeCalculator::groupKey(5, 'SEPARATE', 100));
        $this->assertSame('unset', ShippingFeeCalculator::groupKey(0, 'COMBINED', 100));
    }
}
