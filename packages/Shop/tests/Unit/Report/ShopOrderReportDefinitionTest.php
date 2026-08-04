<?php

namespace Tests\Shop\Unit\Report;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Report\ShopOrderReportDefinition;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Renderer\XlsxReportRenderer;
use Mublo\Core\Result\Result;

class ShopOrderReportDefinitionTest extends TestCase
{
    private function makeDefinition(array $orders = [], int $total = 0): ShopOrderReportDefinition
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->method('getOrderList')->willReturnCallback(
            function (int $domainId, array $filters, int $page, int $perPage) use ($orders, $total): Result {
                // 첫 페이지에만 데이터, 이후 빈 페이지 (청크 종료)
                $items = ($page === 1) ? $orders : [];
                return Result::success('', [
                    'items' => $items,
                    'pagination' => ['total' => $total],
                ]);
            }
        );

        $resolver = $this->createMock(OrderStateResolver::class);
        $resolver->method('getLabel')->willReturnCallback(
            fn(int $d, string $id) => $id === 'paid' ? '결제완료' : $id
        );

        return new ShopOrderReportDefinition($orderService, $resolver);
    }

    private function sampleOrder(): array
    {
        return [
            'order_no' => 'ORD20260704001',
            'created_at' => '2026-07-04 10:00:00',
            'member_id' => 0,
            'orderer_name' => '홍길동',
            'recipient_name' => '김수령',
            'recipient_phone' => '01011112222',
            'shipping_zip' => '12345',
            'shipping_address1' => '서울시 강남구',
            'shipping_address2' => '101동 202호',
            'first_item_name' => '테스트상품',
            'item_count' => 3,
            'final_amount' => 33000,
            'payment_method' => 'CARD',
            'order_status' => 'paid',
        ];
    }

    public function testFieldPickerBuildsOnlySelectedColumns(): void
    {
        $def = $this->makeDefinition();
        $doc = $def->build([
            'domain_id' => 1,
            'columns' => ['order_no', 'recipient_name', 'invoice_no'],
        ]);

        /** @var TableSection $section */
        $section = $doc->sections()[0];
        $keys = array_map(fn($c) => $c->key, $section->columns());

        $this->assertSame(['order_no', 'recipient_name', 'invoice_no'], $keys);
        // 라벨 매핑 확인
        $labels = array_map(fn($c) => $c->label, $section->columns());
        $this->assertSame(['주문번호', '수령인', '송장번호'], $labels);
    }

    public function testUnknownColumnsIgnoredAndEmptyFallsBackToDefault(): void
    {
        $def = $this->makeDefinition();

        $doc = $def->build(['domain_id' => 1, 'columns' => ['order_no', 'BOGUS']]);
        $keys = array_map(fn($c) => $c->key, $doc->sections()[0]->columns());
        $this->assertSame(['order_no'], $keys); // 알 수 없는 키 무시

        $docDefault = $def->build(['domain_id' => 1]); // columns 미지정 → 기본셋
        $defaultKeys = array_map(fn($c) => $c->key, $docDefault->sections()[0]->columns());
        $this->assertSame(ShopOrderReportDefinition::DEFAULT_COLUMNS, $defaultKeys);
    }

    public function testRowMappingDecryptedFieldsAndDerived(): void
    {
        $def = $this->makeDefinition([$this->sampleOrder()], 1);
        $doc = $def->build(['domain_id' => 1, 'columns' => array_keys(ShopOrderReportDefinition::FIELDS)]);

        $rows = iterator_to_array($doc->sections()[0]->rows());
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('비회원', $row['member_type']);            // member_id=0
        $this->assertSame('테스트상품 외 2건', $row['product_summary']); // item_count=3
        $this->assertSame('신용카드', $row['payment_method']);        // CARD label
        $this->assertSame('결제완료', $row['order_status']);          // resolver label
        $this->assertSame('서울시 강남구 101동 202호', $row['shipping_address']);
        $this->assertSame('', $row['courier']);                      // 운송장 빈칸
        $this->assertSame('', $row['invoice_no']);
    }

    public function testRendersToXlsxEndToEnd(): void
    {
        $def = $this->makeDefinition([$this->sampleOrder()], 1);
        $doc = $def->build(['domain_id' => 1, 'columns' => ['order_no', 'recipient_name', 'final_amount']]);

        $renderer = new XlsxReportRenderer();
        $path = sys_get_temp_dir() . '/mublo-shop-report-' . bin2hex(random_bytes(4)) . '.xlsx';
        $renderer->renderToFile($doc, $path);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }
}
