<?php

namespace Tests\Shop\Unit\Action;

use Mublo\Packages\Shop\Action\StockDeductActionHandler;
use Mublo\Packages\Shop\Action\StockRestoreActionHandler;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Tests\Shop\TestCase;

class StockActionHandlerTest extends TestCase
{
    public function testSingleOptionDeductUsesValueIdFromOptionCode(): void
    {
        $orderRepo = $this->createMock(OrderRepository::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $optionRepo = $this->createMock(ProductOptionRepository::class);

        $orderRepo->method('getItems')->willReturn([[
            'order_detail_id' => 10,
            'goods_id' => 1,
            'option_mode' => 'SINGLE',
            'option_id' => 55,
            'option_code' => 'opt-55-777',
            'quantity' => 2,
            'stock_deducted' => 0,
        ]]);
        $optionRepo->expects($this->once())
            ->method('adjustValueStock')
            ->with(777, -2)
            ->willReturn(1);
        $orderRepo->expects($this->once())
            ->method('updateItemFlags')
            ->with(10, ['stock_deducted' => 1]);

        $handler = new StockDeductActionHandler($orderRepo, $productRepo, $optionRepo);
        $handler->execute([], $this->event());
    }

    public function testSingleOptionDeductDoesNotMarkWhenStockUpdateFails(): void
    {
        $orderRepo = $this->createMock(OrderRepository::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $optionRepo = $this->createMock(ProductOptionRepository::class);

        $orderRepo->method('getItems')->willReturn([[
            'order_detail_id' => 10,
            'goods_id' => 1,
            'option_mode' => 'SINGLE',
            'option_id' => 55,
            'option_code' => 'opt-55-777',
            'quantity' => 2,
            'stock_deducted' => 0,
        ]]);
        $optionRepo->method('adjustValueStock')->willReturn(0);
        $orderRepo->expects($this->never())->method('updateItemFlags');

        $handler = new StockDeductActionHandler($orderRepo, $productRepo, $optionRepo);
        $handler->execute([], $this->event());
    }

    public function testManagedStockShortfallIsLoggedNotSilent(): void
    {
        $orderRepo = $this->createMock(OrderRepository::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $optionRepo = $this->createMock(ProductOptionRepository::class);

        // NONE 모드, 재고 관리 중(1개)인데 2개 주문 → 차감 실패(0행)
        $orderRepo->method('getItems')->willReturn([[
            'order_detail_id' => 10,
            'goods_id' => 7,
            'option_mode' => 'NONE',
            'option_id' => 0,
            'option_code' => '',
            'quantity' => 2,
            'stock_deducted' => 0,
            'product_name' => '한정판 티셔츠',
        ]]);
        $productRepo->method('adjustStock')->willReturn(0);      // 차감 실패
        $productRepo->method('getStock')->with(7)->willReturn(1); // 관리 중, 재고 1

        // 차감 실패 → 마킹 안 함
        $orderRepo->expects($this->never())->method('updateItemFlags');
        // 조용히 넘기지 않고 재고 부족을 주문 로그로 기록해야 한다
        $orderRepo->expects($this->once())
            ->method('insertOrderLog')
            ->with($this->callback(function (array $log): bool {
                return ($log['change_type'] ?? '') === 'STOCK'
                    && str_contains($log['reason'] ?? '', '재고 부족')
                    && str_contains($log['reason'] ?? '', '한정판 티셔츠');
            }));

        $handler = new StockDeductActionHandler($orderRepo, $productRepo, $optionRepo);
        $handler->execute([], $this->event());
    }

    public function testUnmanagedStockIsSkippedSilently(): void
    {
        $orderRepo = $this->createMock(OrderRepository::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $optionRepo = $this->createMock(ProductOptionRepository::class);

        // 재고 미관리(getStock=null) → 차감 0행이어도 로그 없이 정상 skip
        $orderRepo->method('getItems')->willReturn([[
            'order_detail_id' => 10,
            'goods_id' => 7,
            'option_mode' => 'NONE',
            'option_id' => 0,
            'option_code' => '',
            'quantity' => 2,
            'stock_deducted' => 0,
        ]]);
        $productRepo->method('adjustStock')->willReturn(0);
        $productRepo->method('getStock')->with(7)->willReturn(null); // 미관리

        $orderRepo->expects($this->never())->method('updateItemFlags');
        $orderRepo->expects($this->never())->method('insertOrderLog');

        $handler = new StockDeductActionHandler($orderRepo, $productRepo, $optionRepo);
        $handler->execute([], $this->event());
    }

    public function testSingleOptionRestoreUsesValueIdFromOptionCode(): void
    {
        $orderRepo = $this->createMock(OrderRepository::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $optionRepo = $this->createMock(ProductOptionRepository::class);

        $orderRepo->method('getItems')->willReturn([[
            'order_detail_id' => 10,
            'goods_id' => 1,
            'option_mode' => 'SINGLE',
            'option_id' => 55,
            'option_code' => 'opt-55-777',
            'quantity' => 2,
            'stock_deducted' => 1,
        ]]);
        $optionRepo->expects($this->once())
            ->method('adjustValueStock')
            ->with(777, 2)
            ->willReturn(1);
        $orderRepo->expects($this->once())
            ->method('updateItemFlags')
            ->with(10, ['stock_deducted' => 0]);

        $handler = new StockRestoreActionHandler($orderRepo, $productRepo, $optionRepo);
        $handler->execute([], $this->event());
    }

    private function event(): OrderStatusChangedEvent
    {
        return new OrderStatusChangedEvent('ORD1', 'received', 'paid', '접수', '결제완료', null, null, [
            'order_no' => 'ORD1',
            'domain_id' => 1,
        ]);
    }
}
