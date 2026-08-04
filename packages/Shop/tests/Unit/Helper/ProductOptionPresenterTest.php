<?php

namespace Tests\Shop\Unit\Helper;

use Mublo\Packages\Shop\Helper\ProductOptionPresenter;
use Tests\Shop\TestCase;

/**
 * 옵션/조합 프론트 페이로드 형태 회귀 테스트.
 *
 * 배경: 상품 상세와 장바구니가 같은 옵션 데이터를 서로 다른 경로로 프론트에
 * 내려주면서 `is_active` 가 한쪽은 boolean, 다른 쪽은 "1" 이 됐다. 소비자 JS 는
 * parseInt 로 판정하고 있었고, parseInt(true) 가 NaN 이라 모든 옵션 값이 걸러져
 * 상품 상세 옵션 셀렉트가 비었다(#568).
 *
 * 여기서 고정하는 것은 "프론트가 받는 키와 타입"이다. 타입이 다시 갈리면
 * 소비자가 조용히 깨지므로 값이 아니라 타입까지 단언한다.
 */
class ProductOptionPresenterTest extends TestCase
{
    /** DB(PDO) 가 돌려주는 문자열 행 */
    private function rawOption(): array
    {
        return [
            'option_id'   => '5',
            'goods_id'    => '4',
            'option_name' => '색상',
            'option_type' => 'BASIC',
            'is_required' => '1',
            'sort_order'  => '0',
            'created_at'  => '2026-08-03 09:04:06',
            'updated_at'  => '2026-08-03 09:04:07',
            'values'      => [[
                'value_id'       => '13',
                'option_id'      => '5',
                'value_name'     => '빨강',
                'extra_price'    => '0',
                'stock_quantity' => null,
                'is_active'      => '1',
                'sort_order'     => '0',
                'created_at'     => '2026-08-03 09:04:07',
            ]],
        ];
    }

    // ===== 타입 고정 =====

    public function testFlagsAreBooleans(): void
    {
        $option = ProductOptionPresenter::toOptionList([$this->rawOption()])[0];

        $this->assertIsBool($option['is_required']);
        $this->assertTrue($option['is_required']);
        $this->assertIsBool($option['values'][0]['is_active']);
        $this->assertTrue($option['values'][0]['is_active']);
    }

    public function testIdentifiersAndPricesAreIntegers(): void
    {
        $option = ProductOptionPresenter::toOptionList([$this->rawOption()])[0];

        $this->assertSame(5, $option['option_id']);
        $this->assertSame(13, $option['values'][0]['value_id']);
        $this->assertSame(0, $option['values'][0]['extra_price']);
    }

    public function testInactiveFlagSurvivesAsFalse(): void
    {
        $raw = $this->rawOption();
        $raw['values'][0]['is_active'] = '0';

        $option = ProductOptionPresenter::toOptionList([$raw])[0];

        $this->assertFalse($option['values'][0]['is_active']);
    }

    // ===== 재고 미관리(NULL) 보존 =====

    public function testNullStockIsPreserved(): void
    {
        $option = ProductOptionPresenter::toOptionList([$this->rawOption()])[0];

        // null(재고 미관리)과 0(품절)은 다른 상태다. 0 으로 뭉개면 전부 품절이 된다.
        $this->assertNull($option['values'][0]['stock_quantity']);
    }

    public function testZeroStockStaysZero(): void
    {
        $combo = ProductOptionPresenter::toComboList([[
            'combo_id'        => '21',
            'combination_key' => '빨강/S',
            'extra_price'     => '0',
            'stock_quantity'  => '0',
            'is_active'       => '1',
        ]])[0];

        $this->assertSame(0, $combo['stock_quantity']);
    }

    // ===== 공개 키 화이트리스트 =====

    public function testInternalColumnsAreNotExposed(): void
    {
        $option = ProductOptionPresenter::toOptionList([$this->rawOption()])[0];

        $this->assertArrayNotHasKey('created_at', $option);
        $this->assertArrayNotHasKey('updated_at', $option);
        $this->assertArrayNotHasKey('goods_id', $option);
        $this->assertArrayNotHasKey('created_at', $option['values'][0]);
    }

    public function testComboExposesOnlyPublicKeys(): void
    {
        $combo = ProductOptionPresenter::toComboList([[
            'combo_id'        => '19',
            'goods_id'        => '4',
            'combination_key' => '빨강/L',
            'extra_price'     => '0',
            'stock_quantity'  => null,
            'is_active'       => '1',
            'created_at'      => '2026-08-03 09:04:07',
        ]])[0];

        $this->assertSame(
            ['combo_id', 'combination_key', 'extra_price', 'stock_quantity', 'is_active'],
            array_keys($combo)
        );
    }

    // ===== 빈 입력 =====

    public function testEmptyInputYieldsEmptyList(): void
    {
        $this->assertSame([], ProductOptionPresenter::toOptionList([]));
        $this->assertSame([], ProductOptionPresenter::toComboList([]));
    }

    public function testOptionWithoutValuesYieldsEmptyValueList(): void
    {
        $raw = $this->rawOption();
        unset($raw['values']);

        $option = ProductOptionPresenter::toOptionList([$raw])[0];

        $this->assertSame([], $option['values']);
    }
}
