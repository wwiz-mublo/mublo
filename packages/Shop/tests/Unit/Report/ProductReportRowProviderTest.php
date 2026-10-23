<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\Report;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Report\ProductReportRowProvider;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\TestCase;

/**
 * 상품 엑셀은 목록 화면의 조건으로 조회하고, 재고·상태를 화면과 같은 말로 적는다.
 */
final class ProductReportRowProviderTest extends TestCase
{
    public function testRowsUseListFiltersAndScreenWording(): void
    {
        $filters = ['keyword' => '냉장고', 'is_active' => '1', 'sort' => 'price_asc'];

        $service = $this->createMock(ProductService::class);
        $service->expects($this->once())
            ->method('getList')
            ->with(3, $filters, 1, 100)
            ->willReturn(Result::success('', [
                'items' => [
                    ['item_code' => '202610001', 'goods_name' => '냉장고', 'option_mode' => 'NONE', 'stock_quantity' => null, 'is_active' => 1, 'display_price' => 990000, 'rating_avg' => 4.25],
                    ['item_code' => '202610002', 'goods_name' => '세탁기', 'option_mode' => 'NONE', 'stock_quantity' => 0, 'is_active' => 0],
                ],
                'totalItems' => 2,
            ]));

        $rows = iterator_to_array((new ProductReportRowProvider($service, 3, $filters))->rows());

        $this->assertSame('무한', $rows[0]['stock']);
        $this->assertSame('판매중', $rows[0]['status']);
        $this->assertSame(990000, $rows[0]['display_price']);
        $this->assertSame(4.3, $rows[0]['rating_avg']);
        $this->assertSame('0', $rows[1]['stock']);
        $this->assertSame('판매중지', $rows[1]['status']);
    }
}
