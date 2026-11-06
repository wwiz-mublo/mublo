<?php

namespace Tests\Shop\Unit\Helper;

use Mublo\Packages\Shop\Helper\AllProductsTab;
use PHPUnit\Framework\TestCase;

class AllProductsTabTest extends TestCase
{
    private const TREE = [
        ['code' => '', 'label' => '빈 코드'],
        ['code' => 'A 1', 'label' => '대분류', 'children' => [['code' => 'A1-1']]],
        ['code' => 'B', 'label' => '두 번째'],
    ];

    public function testShownByDefaultAndBeforeMigration(): void
    {
        $this->assertTrue(AllProductsTab::isShown([]));
        $this->assertTrue(AllProductsTab::isShown(['show_all_products_tab' => 1]));
        $this->assertTrue(AllProductsTab::isShown(['show_all_products_tab' => '1']));
        $this->assertFalse(AllProductsTab::isShown(['show_all_products_tab' => 0]));
        $this->assertFalse(AllProductsTab::isShown(['show_all_products_tab' => '0']));
    }

    public function testDefaultConfigNeverRedirects(): void
    {
        $this->assertNull(AllProductsTab::redirectUrl([], self::TREE, '', ''));
        $this->assertNull(AllProductsTab::redirectUrl(['show_all_products_tab' => 1], self::TREE, '', ''));
    }

    public function testHiddenTabRedirectsToFirstActiveRootKeepingSort(): void
    {
        $url = AllProductsTab::redirectUrl(
            ['show_all_products_tab' => 0],
            self::TREE,
            '',
            '',
            ['sort' => 'popular', 'page' => '3', 'keyword' => '']
        );
        $this->assertSame('/shop/category/A%201?sort=popular', $url);
    }

    public function testCategoryPageAndKeywordSearchAreNotRedirected(): void
    {
        $config = ['show_all_products_tab' => 0];
        $this->assertNull(AllProductsTab::redirectUrl($config, self::TREE, 'B', ''));
        $this->assertNull(AllProductsTab::redirectUrl($config, self::TREE, '', '가방'));
    }

    public function testNoCategoryKeepsAllProductsList(): void
    {
        $this->assertNull(AllProductsTab::redirectUrl(['show_all_products_tab' => 0], [], '', ''));
        $this->assertNull(AllProductsTab::redirectUrl(['show_all_products_tab' => 0], [['code' => '']], '', ''));
    }
}
