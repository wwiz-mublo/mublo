<?php
/**
 * packages/Shop/tests/Unit/Service/ShipmentGroupResolverTest.php
 *
 * ShipmentGroupResolver 단위 테스트
 *
 * 검증 항목:
 * - resolve()  — 그룹별 주문상품 분배, 단일 그룹 정규화, 브레이크다운 결손 시 폴백
 * - detailIdsForShipment() — 품목 지정 > 그룹 지정 > 주문 전체 우선순위
 * - hasGroupKey() — 관리자 입력 검증
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\ShipmentGroupResolver;

class ShipmentGroupResolverTest extends TestCase
{
    private ShipmentGroupResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ShipmentGroupResolver();
    }

    /** 상품 3개가 배송비 그룹 2개로 갈린 주문 (장바구니 화면의 묶음과 같은 구조) */
    private function twoGroupOrder(): array
    {
        return [
            'order_no' => 'ORD1',
            'shipping_breakdown' => json_encode([
                ['group_key' => 'tpl_1', 'template_name' => '조건부 무료', 'base_fee' => 3000, 'extra_fee' => 0, 'goods_ids' => [10]],
                ['group_key' => 'tpl_2', 'template_name' => '무료배송', 'base_fee' => 0, 'extra_fee' => 0, 'goods_ids' => [20, 30]],
            ]),
        ];
    }

    private function threeItems(): array
    {
        return [
            ['order_detail_id' => 1, 'goods_id' => 10, 'goods_name' => '티셔츠'],
            ['order_detail_id' => 2, 'goods_id' => 20, 'goods_name' => '모자'],
            ['order_detail_id' => 3, 'goods_id' => 30, 'goods_name' => '선글라스'],
        ];
    }

    public function testResolveSplitsItemsByShippingGroup(): void
    {
        $groups = $this->resolver->resolve($this->twoGroupOrder(), $this->threeItems());

        $this->assertCount(2, $groups);
        $this->assertSame('tpl_1', $groups[0]['key']);
        $this->assertSame([1], $groups[0]['detail_ids']);
        $this->assertSame(3000, $groups[0]['fee']);
        $this->assertSame('tpl_2', $groups[1]['key']);
        $this->assertSame([2, 3], $groups[1]['detail_ids']);
        $this->assertSame(['모자', '선글라스'], $groups[1]['item_names']);
    }

    public function testGroupLabelCarriesTemplateNameAndFee(): void
    {
        $groups = $this->resolver->resolve($this->twoGroupOrder(), $this->threeItems());

        $this->assertStringContainsString('조건부 무료', $groups[0]['label']);
        $this->assertStringContainsString('3,000원', $groups[0]['label']);
        $this->assertStringContainsString('무료배송', $groups[1]['label']);
    }

    public function testSingleGroupIsNormalizedToWholeOrder(): void
    {
        // 쪼갤 것이 없는 주문은 종전처럼 "주문 전체 묶음배송"으로 다룬다
        $order = [
            'shipping_breakdown' => [
                ['group_key' => 'tpl_1', 'template_name' => '기본배송', 'base_fee' => 3000, 'goods_ids' => [10, 20]],
            ],
        ];
        $items = [
            ['order_detail_id' => 1, 'goods_id' => 10, 'goods_name' => '티셔츠'],
            ['order_detail_id' => 2, 'goods_id' => 20, 'goods_name' => '모자'],
        ];

        $groups = $this->resolver->resolve($order, $items);

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['key']);
        $this->assertSame([1, 2], $groups[0]['detail_ids']);
    }

    public function testMissingBreakdownFallsBackToWholeOrder(): void
    {
        $groups = $this->resolver->resolve(['order_no' => 'ORD1'], $this->threeItems());

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['key']);
        $this->assertSame([1, 2, 3], $groups[0]['detail_ids']);
    }

    public function testPartialBreakdownCoverageFallsBackToWholeOrder(): void
    {
        // 정책 미설정으로 그룹 하나가 브레이크다운에서 빠진 주문.
        // 반쪽 정보로 쪼개 엉뚱한 상품에 송장을 붙이느니 통째로 다룬다.
        $order = [
            'shipping_breakdown' => [
                ['group_key' => 'tpl_1', 'template_name' => '기본배송', 'base_fee' => 3000, 'goods_ids' => [10]],
            ],
        ];

        $groups = $this->resolver->resolve($order, $this->threeItems());

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['key']);
        $this->assertSame([1, 2, 3], $groups[0]['detail_ids']);
    }

    public function testLegacyBreakdownWithoutGroupKeyUsesArrayIndex(): void
    {
        // group_key 도입 전 주문. 얼린 스냅샷이라 배열 순서가 안정적인 키가 된다.
        $order = [
            'shipping_breakdown' => [
                ['template_name' => '조건부 무료', 'base_fee' => 3000, 'goods_ids' => [10]],
                ['template_name' => '무료배송', 'base_fee' => 0, 'goods_ids' => [20, 30]],
            ],
        ];

        $groups = $this->resolver->resolve($order, $this->threeItems());

        $this->assertSame('0', $groups[0]['key']);
        $this->assertSame('1', $groups[1]['key']);
    }

    public function testGroupsSplitByPerItemShippingAreMarked(): void
    {
        // 개별 배송 상품은 그룹 키에 상품 id가 붙는다. 같은 템플릿을 쓰는 일반 상품과
        // 나란히 있어도(묶음 1·3이 같은 템플릿) 개별 배송인 쪽만 표시되어야 한다.
        $order = [
            'shipping_breakdown' => [
                ['group_key' => 'tpl_1_g10', 'template_id' => 1, 'template_name' => '조건부 무료', 'base_fee' => 3000, 'goods_ids' => [10]],
                ['group_key' => 'tpl_2', 'template_id' => 2, 'template_name' => '무료배송', 'base_fee' => 0, 'goods_ids' => [20]],
                ['group_key' => 'tpl_1', 'template_id' => 1, 'template_name' => '조건부 무료', 'base_fee' => 3000, 'goods_ids' => [30]],
            ],
        ];

        $groups = $this->resolver->resolve($order, $this->threeItems());

        $this->assertTrue($groups[0]['separate'], '그룹 키에 상품 id가 붙은 쪽만 개별 배송입니다.');
        $this->assertFalse($groups[1]['separate']);
        $this->assertFalse($groups[2]['separate'], '같은 템플릿이어도 일반 상품이면 개별 배송이 아닙니다.');
    }

    public function testLegacyOrderWithoutGroupKeyIsNotMarkedSeparate(): void
    {
        // 키가 없으면 알 수 없다 — 잘못 붙이느니 표시하지 않는다
        $order = [
            'shipping_breakdown' => [
                ['template_name' => '조건부 무료', 'base_fee' => 3000, 'goods_ids' => [10]],
                ['template_name' => '무료배송', 'base_fee' => 0, 'goods_ids' => [20, 30]],
            ],
        ];

        foreach ($this->resolver->resolve($order, $this->threeItems()) as $group) {
            $this->assertFalse($group['separate']);
        }
    }

    public function testDetailIdsPrefersExplicitItemOverGroup(): void
    {
        $shipment = ['order_detail_id' => 3, 'shipping_group_key' => 'tpl_1'];

        $this->assertSame(
            [3],
            $this->resolver->detailIdsForShipment($shipment, $this->twoGroupOrder(), $this->threeItems())
        );
    }

    public function testDetailIdsResolveFromGroupKey(): void
    {
        $shipment = ['shipment_id' => 5, 'shipping_group_key' => 'tpl_2'];

        $this->assertSame(
            [2, 3],
            $this->resolver->detailIdsForShipment($shipment, $this->twoGroupOrder(), $this->threeItems())
        );
    }

    public function testDetailIdsWithoutAnyTargetCoverWholeOrder(): void
    {
        // 그룹 컬럼이 없던 시절의 송장 = 주문 전체 묶음배송
        $shipment = ['shipment_id' => 5];

        $this->assertSame(
            [1, 2, 3],
            $this->resolver->detailIdsForShipment($shipment, $this->twoGroupOrder(), $this->threeItems())
        );
    }

    public function testUnknownGroupKeyFallsBackToWholeOrder(): void
    {
        $shipment = ['shipment_id' => 5, 'shipping_group_key' => 'tpl_999'];

        $this->assertSame(
            [1, 2, 3],
            $this->resolver->detailIdsForShipment($shipment, $this->twoGroupOrder(), $this->threeItems())
        );
    }

    public function testHasGroupKeyRejectsForeignKey(): void
    {
        $order = $this->twoGroupOrder();
        $items = $this->threeItems();

        $this->assertTrue($this->resolver->hasGroupKey($order, $items, 'tpl_1'));
        $this->assertFalse($this->resolver->hasGroupKey($order, $items, 'tpl_999'));
    }

    public function testHasGroupKeyRejectsWhenOrderHasSingleGroup(): void
    {
        // 단일 그룹은 key가 null로 정규화되므로 귀속시킬 그룹이 없다
        $order = [
            'shipping_breakdown' => [
                ['group_key' => 'tpl_1', 'template_name' => '기본배송', 'base_fee' => 0, 'goods_ids' => [10]],
            ],
        ];
        $items = [['order_detail_id' => 1, 'goods_id' => 10, 'goods_name' => '티셔츠']];

        $this->assertFalse($this->resolver->hasGroupKey($order, $items, 'tpl_1'));
    }
}
