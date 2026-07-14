<?php
/**
 * RefundService 단위 테스트
 *
 * 검증 항목:
 * - POINT 환불이 회원 포인트를 실제로 지급한다 (BalanceManager.adjust)
 * - POINT 환불은 비회원/모듈부재 시 거부한다
 * - BANK 환불은 포인트를 지급하지 않는다
 * - 초과환불을 거부한다
 * - 포인트 지급 실패를 조용히 넘기지 않고 노출한다
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\RefundService;
use Mublo\Packages\Shop\Service\PaymentService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Infrastructure\Database\Database;
use Mublo\Core\Result\Result;

class RefundServiceTest extends TestCase
{
    /**
     * @return array{0: RefundService, 1: OrderRepository, 2: PaymentTransactionRepository}
     */
    private function makeService(?BalanceManager $balance, int $totalRefunded = 0): array
    {
        $payment  = $this->createMock(PaymentService::class);
        $txnRepo  = $this->createMock(PaymentTransactionRepository::class);
        $orderRepo = $this->createMock(OrderRepository::class);
        $resolver = $this->createMock(OrderStateResolver::class);

        // 트랜잭션 콜백을 그대로 실행, FOR UPDATE select는 no-op
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn(callable $fn) => $fn($db));
        $db->method('select')->willReturn([]);

        $txnRepo->method('getDb')->willReturn($db);
        $txnRepo->method('getTotalRefunded')->willReturn($totalRefunded);
        $txnRepo->method('createTransaction')->willReturn(777);

        $service = new RefundService($payment, $txnRepo, $orderRepo, $resolver, null, $balance);

        return [$service, $orderRepo, $txnRepo];
    }

    private function order(array $overrides = []): Order
    {
        return Order::fromArray($this->makeOrderData(array_merge([
            'order_no'     => 'ORD1',
            'domain_id'    => 1,
            'order_status' => 'paid',
        ], $overrides)));
    }

    public function testPointRefundCreditsMemberPoints(): void
    {
        $balance = $this->createMock(BalanceManager::class);
        $balance->expects($this->once())
            ->method('adjust')
            ->with($this->callback(function (array $p): bool {
                return (int) $p['member_id'] === 42
                    && (int) $p['amount'] === 10000        // +지급
                    && $p['action'] === 'refund_point'
                    && $p['source_name'] === 'Shop'
                    && $p['reference_id'] === 'ORD1'
                    && $p['idempotency_key'] === 'shop_refund_point_777';
            }))
            ->willReturn(Result::success('지급', ['balance_after' => 10000]));

        [$service, $orderRepo] = $this->makeService($balance);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));

        $result = $service->processRefund('ORD1', 10000, 'POINT', '고객요청', 1, 5);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10000, $result->get('refund_amount'));
    }

    public function testPointRefundRejectedForGuestOrder(): void
    {
        $balance = $this->createMock(BalanceManager::class);
        $balance->expects($this->never())->method('adjust');

        [$service, $orderRepo] = $this->makeService($balance);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 0]));

        $result = $service->processRefund('ORD1', 10000, 'POINT', 'r', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('회원', $result->getMessage());
    }

    public function testPointRefundBlockedWhenNoBalanceManager(): void
    {
        [$service, $orderRepo] = $this->makeService(null);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));

        $result = $service->processRefund('ORD1', 10000, 'POINT', 'r', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('포인트 지급', $result->getMessage());
    }

    public function testBankRefundDoesNotCreditPoints(): void
    {
        $balance = $this->createMock(BalanceManager::class);
        $balance->expects($this->never())->method('adjust');

        [$service, $orderRepo] = $this->makeService($balance);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));

        $result = $service->processRefund('ORD1', 10000, 'BANK', '무통장환불', 1, 5, [
            'bank' => '국민', 'account' => '123', 'holder' => '홍길동',
        ]);

        $this->assertTrue($result->isSuccess());
    }

    public function testOverRefundRejected(): void
    {
        // 이미 전액(33000) 환불됨 → 환불 가능액 0
        [$service, $orderRepo] = $this->makeService($this->createMock(BalanceManager::class), 33000);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));

        $result = $service->processRefund('ORD1', 10000, 'POINT', 'r', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('초과', $result->getMessage());
    }

    public function testPointCreditFailureIsSurfacedNotSilent(): void
    {
        $balance = $this->createMock(BalanceManager::class);
        $balance->method('adjust')->willReturn(Result::failure('잔액 스냅샷 오류'));

        [$service, $orderRepo] = $this->makeService($balance);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));

        $result = $service->processRefund('ORD1', 10000, 'POINT', 'r', 1);

        // 환불 기록 자체는 성공하되, 포인트 지급 실패가 플래그로 노출되어야 한다
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->get('point_credit_failed'));
        $this->assertStringContainsString('포인트 지급에 실패', $result->getMessage());
    }

    // ===== retryPointCredit — 지급 실패 건 재처리 (회귀) =====

    /** POINT 환불 트랜잭션 행 샘플 */
    private function pointRefundTxn(int $txnId = 777, int $amount = 10000): array
    {
        return [
            'transaction_id' => $txnId,
            'pg_key' => 'manual',
            'admin_memo' => "포인트 환불 ({$amount}원)",
            'transaction_status' => 'SUCCESS',
            'cancel_amount' => $amount,
            'cancel_reason' => '고객 요청',
        ];
    }

    public function testRetryPointCreditUsesSameIdempotencyKey(): void
    {
        $balance = $this->createMock(BalanceManager::class);
        $balance->expects($this->once())->method('adjust')
            ->with($this->callback(fn(array $p) =>
                $p['idempotency_key'] === 'shop_refund_point_777'
                && (int) $p['amount'] === 10000
                && (int) $p['member_id'] === 42
            ))
            ->willReturn(Result::success('ok', ['log_id' => 1, 'idempotent' => true]));

        [$service, $orderRepo, $txnRepo] = $this->makeService($balance);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));
        $txnRepo->method('getByOrderNoInDomain')->willReturn([$this->pointRefundTxn()]);

        $result = $service->retryPointCredit(1, 'ORD1', 777, 5);

        $this->assertTrue($result->isSuccess());
        // 이미 지급된 건은 멱등 응답 안내
        $this->assertStringContainsString('이미 지급된', $result->getMessage());
    }

    public function testRetryPointCreditRejectsNonPointRefundTransaction(): void
    {
        $balance = $this->createMock(BalanceManager::class);
        $balance->expects($this->never())->method('adjust');

        [$service, $orderRepo, $txnRepo] = $this->makeService($balance);
        $orderRepo->method('find')->willReturn($this->order(['member_id' => 42]));
        // BANK 환불 건 — 포인트 재지급 대상 아님
        $txnRepo->method('getByOrderNoInDomain')->willReturn([
            array_merge($this->pointRefundTxn(), ['admin_memo' => '무통장 환불 (10000원)']),
        ]);

        $result = $service->retryPointCredit(1, 'ORD1', 777, 5);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('포인트 환불 거래가 아닙니다', $result->getMessage());
    }
}
