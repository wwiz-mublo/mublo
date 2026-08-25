<?php

namespace Tests\Shop\Unit\Helper;

use Mublo\Packages\Shop\Helper\ProductPresenter;
use PHPUnit\Framework\TestCase;

class ProductPresenterNoticeTest extends TestCase
{
    public function testDetailKeepsStructuredProductNoticeForTabRendering(): void
    {
        $notice = [
            'notice' => ['template_id' => 3],
            'template' => ['name' => '의류'],
            'fields' => [['field_code' => 'material', 'label' => '제품 소재']],
            'values' => ['material' => '면 100%'],
        ];

        $view = (new ProductPresenter())->toView([
            'goods_id' => 10,
            'goods_name' => '테스트 상품',
            'display_price' => 1000,
            'option_mode' => 'NONE',
            'product_notice' => $notice,
        ]);

        $this->assertSame($notice, $view['product_notice']);
    }

    public function testUnknownInternalFieldsAreStillRemoved(): void
    {
        $view = (new ProductPresenter())->toView([
            'goods_id' => 10,
            'goods_name' => '테스트 상품',
            'display_price' => 1000,
            'option_mode' => 'NONE',
            'private_value' => 'hidden',
        ]);

        $this->assertArrayNotHasKey('private_value', $view);
    }
}
