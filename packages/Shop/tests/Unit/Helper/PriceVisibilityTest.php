<?php

namespace Tests\Shop\Unit\Helper;

use Mublo\Packages\Shop\Helper\PriceVisibility;
use Mublo\Packages\Shop\Helper\ProductPresenter;
use PHPUnit\Framework\TestCase;

class PriceVisibilityTest extends TestCase
{
    public function testAllIsDefaultAndShowsPriceToGuests(): void
    {
        $this->assertTrue(PriceVisibility::allows([], null));
        $this->assertTrue(PriceVisibility::allows(['price_display' => 'ALL'], null));
    }

    public function testMemberModeHidesPriceFromGuestsOnly(): void
    {
        $config = ['price_display' => 'MEMBER'];

        $this->assertFalse(PriceVisibility::allows($config, null));
        $this->assertTrue(PriceVisibility::allows($config, 1));
    }

    public function testLevelModeNeedsMinimumLevelValue(): void
    {
        $config = ['price_display' => 'LEVEL', 'price_display_level' => 5];

        $this->assertFalse(PriceVisibility::allows($config, null));
        $this->assertFalse(PriceVisibility::allows($config, 4));
        $this->assertTrue(PriceVisibility::allows($config, 5));
        $this->assertTrue(PriceVisibility::allows($config, 9));
    }

    public function testHiddenTextFallsBackToDefault(): void
    {
        $this->assertSame(PriceVisibility::DEFAULT_HIDDEN_TEXT, PriceVisibility::hiddenText([]));
        $this->assertSame(PriceVisibility::DEFAULT_HIDDEN_TEXT, PriceVisibility::hiddenText(['price_hidden_text' => '  ']));
        $this->assertSame('인증 회원 전용가', PriceVisibility::hiddenText(['price_hidden_text' => '인증 회원 전용가']));
    }

    public function testPresenterBlanksEveryPriceFieldWhenHidden(): void
    {
        $item = [
            'goods_id' => 10,
            'goods_name' => '냉장고',
            'origin_price' => 700000,
            'display_price' => 1000000,
            'discount_type' => 'PERCENTAGE',
            'discount_value' => 10,
            'option_mode' => 'SINGLE',
            'options' => [['option_id' => 1, 'option_name' => '색상', 'values' => [['value_id' => 1, 'value_name' => '흰색', 'extra_price' => 50000]]]],
        ];
        $config = ['price_display' => 'MEMBER', 'price_hidden_text' => '회원인증시 가격 확인가능'];

        $view = (new ProductPresenter($config, null, null, false))->toView($item);

        $this->assertTrue($view['price_hidden']);
        $this->assertSame('회원인증시 가격 확인가능', $view['price_hidden_text']);
        $this->assertNull($view['sales_price']);
        $this->assertNull($view['display_price']);
        $this->assertNull($view['origin_price']);
        $this->assertSame('', $view['sales_price_formatted']);
        $this->assertSame('', $view['display_price_formatted']);
        $this->assertFalse($view['has_discount']);
        $this->assertSame([], $view['options']);
        $this->assertStringNotContainsString('900,000', json_encode($view, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('1000000', json_encode($view));
    }

    public function testPresenterKeepsPricesWhenVisible(): void
    {
        $list = (new ProductPresenter())->toList([[
            'goods_id' => 10,
            'goods_name' => '냉장고',
            'display_price' => 1000000,
            'option_mode' => 'NONE',
        ]]);

        $this->assertFalse($list[0]['price_hidden']);
        $this->assertSame('', $list[0]['price_hidden_text']);
        $this->assertSame(1000000, $list[0]['sales_price']);
        $this->assertSame('1,000,000', $list[0]['sales_price_formatted']);
    }
}
