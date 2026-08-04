<?php

namespace Tests\Shop\Unit\Helper;

use Mublo\Packages\Shop\Helper\ProductPresenter;
use Tests\Shop\TestCase;

/**
 * ProductPresenter 재고/품절 판정 회귀 테스트.
 *
 * 배경: 조합(COMBINATION)/단독(SINGLE) 옵션 상품에서 stock_quantity를 합산할 때
 * NULL(미관리=무제한)을 무시하고, 관리되는 항목이 하나라도 있으면 그 합계로
 * 상품 재고를 확정하던 버그가 있었다. 이 때문에 조합 하나만 품절(0)이어도
 * 나머지가 무제한(NULL)인 상품 전체가 품절로 표시됐다.
 */
class ProductPresenterStockTest extends TestCase
{
    private function combinationItem(array $combos): array
    {
        return [
            'goods_id'       => 999,
            'goods_name'     => '테스트 상품',
            'option_mode'    => 'COMBINATION',
            'stock_quantity' => null,
            'combos'         => $combos,
        ];
    }

    private function singleItem(array $values): array
    {
        return [
            'goods_id'       => 999,
            'goods_name'     => '테스트 상품',
            'option_mode'    => 'SINGLE',
            'stock_quantity' => null,
            'options'        => [['values' => $values]],
        ];
    }

    // ===== COMBINATION =====

    /** 핵심 회귀: 조합 하나만 품절(0)이고 나머지가 무제한(NULL)이면 상품은 품절이 아니다. */
    public function testCombinationNotSoldoutWhenOneComboZeroAndRestUnmanaged(): void
    {
        $view = (new ProductPresenter())->toView($this->combinationItem([
            ['stock_quantity' => 0,    'is_active' => 1],
            ['stock_quantity' => null, 'is_active' => 1],
            ['stock_quantity' => null, 'is_active' => 1],
        ]));

        $this->assertFalse($view['is_soldout']);
        $this->assertNull($view['stock_label']);
    }

    /** 모든 조합이 무제한(NULL)이면 품절이 아니다. */
    public function testCombinationNotSoldoutWhenAllUnmanaged(): void
    {
        $view = (new ProductPresenter())->toView($this->combinationItem([
            ['stock_quantity' => null, 'is_active' => 1],
            ['stock_quantity' => null, 'is_active' => 1],
        ]));

        $this->assertFalse($view['is_soldout']);
    }

    /** 모든 조합이 관리값 0이면 품절이다. */
    public function testCombinationSoldoutWhenAllZero(): void
    {
        $view = (new ProductPresenter())->toView($this->combinationItem([
            ['stock_quantity' => 0, 'is_active' => 1],
            ['stock_quantity' => 0, 'is_active' => 1],
        ]));

        $this->assertTrue($view['is_soldout']);
        $this->assertSame('품절', $view['stock_label']);
    }

    /** 모든 조합이 관리값일 때만 합산한다. */
    public function testCombinationSumsWhenAllManaged(): void
    {
        $view = (new ProductPresenter())->toView($this->combinationItem([
            ['stock_quantity' => 0, 'is_active' => 1],
            ['stock_quantity' => 5, 'is_active' => 1],
        ]));

        $this->assertFalse($view['is_soldout']);
        $this->assertSame('재고 5개', $view['stock_label']);
    }

    /** 비활성 조합은 판정에서 제외한다 (비활성 0 무시, 활성 무제한 → 품절 아님). */
    public function testCombinationIgnoresInactiveCombo(): void
    {
        $view = (new ProductPresenter())->toView($this->combinationItem([
            ['stock_quantity' => 0,    'is_active' => 0],
            ['stock_quantity' => null, 'is_active' => 1],
        ]));

        $this->assertFalse($view['is_soldout']);
    }

    // ===== SINGLE =====

    /** 회귀: 옵션값 하나만 품절(0)이고 나머지가 무제한(NULL)이면 상품은 품절이 아니다. */
    public function testSingleNotSoldoutWhenOneValueZeroAndRestUnmanaged(): void
    {
        $view = (new ProductPresenter())->toView($this->singleItem([
            ['stock_quantity' => 0,    'is_active' => 1],
            ['stock_quantity' => null, 'is_active' => 1],
        ]));

        $this->assertFalse($view['is_soldout']);
        $this->assertNull($view['stock_label']);
    }

    /** 모든 옵션값이 관리값 0이면 품절이다. */
    public function testSingleSoldoutWhenAllZero(): void
    {
        $view = (new ProductPresenter())->toView($this->singleItem([
            ['stock_quantity' => 0, 'is_active' => 1],
            ['stock_quantity' => 0, 'is_active' => 1],
        ]));

        $this->assertTrue($view['is_soldout']);
        $this->assertSame('품절', $view['stock_label']);
    }

    public function testOnlyExplicitPublicFieldsReachTheViewContract(): void
    {
        $view = (new ProductPresenter())->toView([
            'goods_id' => 1,
            'goods_name' => '공개 상품',
            'domain_id' => 7,
            'seller_id' => 8,
            'supply_id' => 9,
            'future_internal_column' => 'secret',
            'options' => [[
                'option_id' => 3,
                'option_name' => '색상',
                'internal_cost' => 500,
                'values' => [['value_id' => 4, 'value_name' => '검정', 'internal_cost' => 300]],
            ]],
            'combos' => [['combo_id' => 5, 'combination_key' => '검정', 'internal_cost' => 700]],
            'details' => [['detail_id' => 6, 'detail_type' => '설명', 'detail_value' => '본문', 'internal_note' => 'x']],
        ]);

        $this->assertSame('공개 상품', $view['goods_name']);
        $this->assertArrayNotHasKey('domain_id', $view);
        $this->assertArrayNotHasKey('seller_id', $view);
        $this->assertArrayNotHasKey('supply_id', $view);
        $this->assertArrayNotHasKey('future_internal_column', $view);
        $this->assertArrayNotHasKey('internal_cost', $view['options'][0]);
        $this->assertArrayNotHasKey('internal_cost', $view['options'][0]['values'][0]);
        $this->assertArrayNotHasKey('internal_cost', $view['combos'][0]);
        $this->assertArrayNotHasKey('internal_note', $view['details'][0]);
    }
}
