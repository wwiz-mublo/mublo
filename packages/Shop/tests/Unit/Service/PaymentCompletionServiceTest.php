<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Event\PaymentCompletedEvent;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\PaymentCompletionRepository;
use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Service\OrderPointService;
use Mublo\Packages\Shop\Service\PaymentCompletionService;
use Tests\Shop\TestCase;

class PaymentCompletionServiceTest extends TestCase
{
    private PaymentCompletionRepository $completionRepository;
    private PaymentTransactionRepository $transactionRepository;
    private OrderRepository $orderRepository;
    private CartRepository $cartRepository;
    private CouponService $couponService;
    private OrderPointService $orderPointService;
    private EventDispatcher $dispatcher;
    private PaymentCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completionRepository = $this->createMock(PaymentCompletionRepository::class);
        $this->transactionRepository = $this->createMock(PaymentTransactionRepository::class);
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->cartRepository = $this->createMock(CartRepository::class);
        $this->couponService = $this->createMock(CouponService::class);
        $this->orderPointService = $this->createMock(OrderPointService::class);
        $this->dispatcher = new EventDispatcher();

        $this->service = new PaymentCompletionService(
            $this->completionRepository,
            $this->transactionRepository,
            $this->orderRepository,
            $this->cartRepository,
            $this->couponService,
            $this->orderPointService,
            $this->dispatcher,
        );
    }

    public function testProcessRunsCoreEffectsOnceAndPublishesStableEventId(): void
    {
        $row = $this->completionRow();
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD1',
            'point_used' => 1000,
            'coupon_id' => 7,
            'coupon_discount' => 2000,
        ]));

        $this->completionRepository->method('find')->willReturn($row);
        $this->completionRepository->method('claim')->willReturn($row);
        $this->transactionRepository->method('findSuccessfulPayment')->willReturn(null);
        $this->transactionRepository->expects($this->once())->method('createTransaction');
        $this->orderRepository->method('find')->willReturn($order);
        $this->orderRepository->method('getCartItemIds')->willReturn([11, 12]);
        $this->cartRepository->expects($this->once())
            ->method('markOrderedByIds')
            ->with([11, 12]);
        $this->couponService->expects($this->once())
            ->method('markCouponsUsedForOrder')
            ->with('ORD1', [['coupon_id' => 7, 'discount' => 2000]])
            ->willReturn(Result::success('ok'));
        $this->orderPointService->expects($this->once())
            ->method('confirm')
            ->willReturn(Result::success('ok'));
        $this->completionRepository->expects($this->once())
            ->method('markCompleted')
            ->with('ORD1', 'lease-1');

        $events = [];
        $this->dispatcher->addListener(
            PaymentCompletedEvent::class,
            function (PaymentCompletedEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $result = $this->service->process('ORD1');

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $events);
        $this->assertSame('evt-payment-1', $events[0]->getEventId());
        $this->assertSame(1, $events[0]->getDomainId());
        $this->assertSame('txn_1', $events[0]->getTransactionId());
    }

    public function testRetrySkipsExistingPaymentTransaction(): void
    {
        $row = $this->completionRow();
        $order = Order::fromArray($this->makeOrderData(['order_no' => 'ORD1']));

        $this->completionRepository->method('find')->willReturn($row);
        $this->completionRepository->method('claim')->willReturn($row);
        $this->transactionRepository->method('findSuccessfulPayment')->willReturn([
            'transaction_id' => 99,
        ]);
        $this->transactionRepository->expects($this->never())->method('createTransaction');
        $this->orderRepository->method('find')->willReturn($order);
        $this->orderRepository->method('getCartItemIds')->willReturn([]);
        $this->couponService->method('markCouponsUsedForOrder')->willReturn(Result::success('ok'));
        $this->orderPointService->method('confirm')->willReturn(Result::success('ok'));

        $result = $this->service->process('ORD1');

        $this->assertTrue($result->isSuccess());
    }

    public function testCompletedLedgerDoesNotRepeatAnyEffect(): void
    {
        $row = $this->completionRow(['status' => 'COMPLETED']);
        $this->completionRepository->method('find')->willReturn($row);
        $this->completionRepository->expects($this->never())->method('claim');
        $this->orderRepository->expects($this->never())->method('find');
        $this->transactionRepository->expects($this->never())->method('createTransaction');

        $result = $this->service->process('ORD1');

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->get('already_completed'));
    }

    public function testMissingPointHoldBlocksCompletionForRetry(): void
    {
        $row = $this->completionRow();
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD1',
            'point_used' => 1000,
        ]));

        $this->completionRepository->method('find')->willReturn($row);
        $this->completionRepository->method('claim')->willReturn($row);
        $this->transactionRepository->method('findSuccessfulPayment')->willReturn(null);
        $this->orderRepository->method('find')->willReturn($order);
        $this->orderRepository->method('getCartItemIds')->willReturn([]);
        $this->couponService->method('markCouponsUsedForOrder')->willReturn(Result::success('ok'));

        $this->orderPointService->method('confirm')->willReturn(Result::failure('선점 이력 없음'));

        $this->completionRepository->expects($this->once())
            ->method('markFailed')
            ->with('ORD1', 'lease-1', '선점 이력 없음');
        $this->completionRepository->expects($this->never())->method('markCompleted');

        $events = [];
        $this->dispatcher->addListener(
            PaymentCompletedEvent::class,
            function (PaymentCompletedEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $result = $this->service->process('ORD1');

        $this->assertTrue($result->isFailure());
        $this->assertCount(0, $events);
    }

    public function testFailedCoreEffectMarksLedgerFailedForRetry(): void
    {
        $row = $this->completionRow();
        $order = Order::fromArray($this->makeOrderData(['order_no' => 'ORD1']));
        $this->completionRepository->method('find')->willReturn($row);
        $this->completionRepository->method('claim')->willReturn($row);
        $this->transactionRepository->method('findSuccessfulPayment')->willReturn(['transaction_id' => 1]);
        $this->orderRepository->method('find')->willReturn($order);
        $this->orderRepository->method('getCartItemIds')->willReturn([]);
        $this->couponService->method('markCouponsUsedForOrder')
            ->willReturn(Result::failure('coupon failed'));
        $this->orderPointService->expects($this->never())->method('confirm');
        $this->completionRepository->expects($this->once())
            ->method('markFailed')
            ->with('ORD1', 'lease-1', 'coupon failed');
        $this->completionRepository->expects($this->never())->method('markCompleted');

        $result = $this->service->process('ORD1');

        $this->assertTrue($result->isFailure());
    }

    public function testCompletionClaimUsesLeaseTokenToFenceStaleWorkers(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn(callable $callback) => $callback());
        $db->method('selectOne')->willReturn($this->completionRow([
            'processing_started_at' => null,
        ]));
        $capturedToken = '';
        $db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('lease_token = ?'),
                $this->callback(function (array $params) use (&$capturedToken): bool {
                    $capturedToken = (string) ($params[0] ?? '');
                    return strlen($capturedToken) === 32 && ($params[1] ?? '') === 'ORD1';
                })
            )
            ->willReturn(1);
        $repository = new PaymentCompletionRepository($db);

        $claimed = $repository->claim('ORD1');

        $this->assertNotNull($claimed);
        $this->assertSame($capturedToken, $claimed['lease_token']);
        $this->assertSame('PROCESSING', $claimed['status']);
    }

    public function testCompletionClaimDoesNotStealActiveLease(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn(callable $callback) => $callback());
        $db->method('selectOne')->willReturn($this->completionRow([
            'status' => 'PROCESSING',
            'processing_started_at' => date('Y-m-d H:i:s'),
        ]));
        $db->expects($this->never())->method('execute');
        $repository = new PaymentCompletionRepository($db);

        $this->assertNull($repository->claim('ORD1'));
    }

    private function completionRow(array $overrides = []): array
    {
        return array_merge([
            'order_no' => 'ORD1',
            'domain_id' => 1,
            'event_id' => 'evt-payment-1',
            'pg_key' => 'tosspay',
            'pg_tid' => 'txn_1',
            'verify_data' => json_encode([
                'success' => true,
                'transaction_id' => 'txn_1',
                'amount' => 33000,
                'payment_method' => 'CARD',
            ]),
            'status' => 'PENDING',
            'attempts' => 0,
            'lease_token' => 'lease-1',
        ], $overrides);
    }
}
