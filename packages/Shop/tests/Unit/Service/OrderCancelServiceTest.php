<?php
/**
 * packages/Shop/tests/Unit/Service/OrderCancelServiceTest.php
 *
 * OrderCancelService 단위 테스트
 *
 * 검증 항목:
 * - itemsBlockingCancel() — 출고된 품목이 주문 전체 취소를 막는다
 *
 * 이 판정은 프론트 주문 상세의 취소 버튼 노출 조건이기도 하다. 두 곳이 갈리면
 * 눌러야 거절당하는 버튼이 생기거나, 반대로 이미 받은 상품 값까지 환불된다.
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\OrderCancelService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\RefundService;

class OrderCancelServiceTest extends TestCase
{
    private OrderCancelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderCancelService(
            $this->createMock(OrderRepository::class),
            $this->createMock(OrderService::class),
            $this->createMock(RefundService::class)
        );
    }

    /** @param array<int, array{0:string, 1:string}> $pairs [상태, 상품명] */
    private function items(array $pairs): array
    {
        return array_map(
            static fn(array $p): array => ['status' => $p[0], 'goods_name' => $p[1]],
            $pairs
        );
    }

    public function testNothingBlocksWhenAllItemsAreStillCancellable(): void
    {
        $items = $this->items([['received', '티셔츠'], ['paid', '모자']]);

        $this->assertSame([], $this->service->itemsBlockingCancel($items));
    }

    public function testPartiallyShippedOrderBlocksWholeOrderCancel(): void
    {
        // 3개 중 1개만 배송완료인 주문. 주문 상태는 가장 뒤처진 품목을 따라
        // 결제완료로 남지만, 이미 나간 상품이 있으므로 전체 취소는 성립하지 않는다.
        $items = $this->items([['paid', '티셔츠'], ['delivered', '모자'], ['paid', '선글라스']]);

        $this->assertSame(['모자'], $this->service->itemsBlockingCancel($items));
    }

    public function testAlreadyCancelledItemDoesNotBlock(): void
    {
        // 되돌릴 것이 남아 있지 않은 품목이 나머지의 취소를 막을 이유는 없다
        $items = $this->items([['cancelled', '티셔츠'], ['paid', '모자']]);

        $this->assertSame([], $this->service->itemsBlockingCancel($items));
    }

    public function testUnknownStatusBlocks(): void
    {
        // 판매자가 FSM에 추가한 커스텀 상태는 무엇인지 알 수 없다.
        // 잘못 막으면 문의 한 번이지만, 잘못 열면 돈이 나간다.
        $items = $this->items([['paid', '티셔츠'], ['awaiting_stock', '모자']]);

        $this->assertSame(['모자'], $this->service->itemsBlockingCancel($items));
    }

    public function testUnnamedItemStillReported(): void
    {
        $this->assertSame(['상품'], $this->service->itemsBlockingCancel([['status' => 'delivered']]));
    }

    public function testEmptyOrderDoesNotBlock(): void
    {
        $this->assertSame([], $this->service->itemsBlockingCancel([]));
    }
}
