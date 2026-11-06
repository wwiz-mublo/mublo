<?php
declare(strict_types=1);

namespace Tests\Innopay\Unit;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Result\Result;
use Mublo\Plugin\Innopay\Service\InnopayReturnService;
use PHPUnit\Framework\TestCase;
use Tests\Innopay\Support\InnopayTestKit;

class InnopayReturnServiceTest extends TestCase
{
    private const MOID = 'S20261106ABCDEF';
    private const OK = 'https://shop.example.com/shop/order/S20261106ABCDEF/complete';
    private const FAIL = 'https://shop.example.com/shop/checkout';

    private InnopayTestKit $kit;
    private FakeConsumer $consumer;
    private ContractRegistry $registry;

    protected function setUp(): void
    {
        $this->kit = new InnopayTestKit($this);
        $this->consumer = new FakeConsumer();
        $this->registry = new ContractRegistry();
        $this->registry->register(PaymentConsumerInterface::class, 'shop', $this->consumer);

        $this->kit->attempts->prepare(1, self::MOID, [
            'consumer' => 'shop',
            'success_url' => self::OK,
            'fail_url' => self::FAIL,
            'pay_method' => 'CARD',
            'amount' => 15000,
        ]);
    }

    private function service(): InnopayReturnService
    {
        return new InnopayReturnService(
            $this->kit->configService,
            $this->kit->attempts,
            $this->kit->logger,
            $this->registry,
            fn(int $domainId) => $this->kit->gateway($domainId)
        );
    }

    private function params(array $override = []): array
    {
        return $override + [
            'paymentToken' => 'PTOKEN',
            'tid' => 'TID1',
            'mid' => 'testpay01m',
            'amt' => '15000',
            'taxFreeAmt' => '0',
            'moid' => self::MOID,
        ];
    }

    private function approveOk(): void
    {
        $tx = ['success' => true, 'data' => InnopayTestKit::transaction('TID1', self::MOID, 15000)];
        $this->kit->routes['/v1/transactions/pay'] = [200, $tx];
        $this->kit->routes['/v1/transactions/TID1'] = [200, $tx];
    }

    private function attemptStatus(): string
    {
        return $this->kit->attempts->find(1, self::MOID)['status'];
    }

    public function testHappyPathApprovesThenLetsConsumerVerify(): void
    {
        $this->approveOk();

        $out = $this->service()->handle(1, $this->params(), '1.2.3.4', 'UA');

        self::assertTrue($out->success);
        self::assertSame(self::OK, $out->redirect);
        self::assertSame('COMPLETED', $this->attemptStatus());
        self::assertSame([[1, self::MOID, 'innopay', 'TID1']], $this->consumer->verifyCalls);

        $approve = $this->kit->requests[0];
        self::assertSame('PTOKEN', $approve['headers']['Payment-Token']);
        self::assertSame('MKEY', $approve['headers']['Merchant-Key']);
        self::assertSame(['tid' => 'TID1', 'mid' => 'testpay01m', 'moid' => self::MOID, 'amt' => 15000, 'taxFreeAmt' => 0], $approve['body']);

        self::assertSame(['RETURN', 'APPROVE_REQ', 'APPROVE_RES', 'CONSUMER_RESULT'], $this->kit->steps());
        self::assertSame('1.2.3.4', $this->kit->stepRow('RETURN')['ip']);
        self::assertStringNotContainsString('PTOKEN', json_encode($this->kit->logs), '결제 토큰은 로그에 남지 않는다');
    }

    public function testAmountMismatchNeverCallsApproval(): void
    {
        $this->approveOk();

        $out = $this->service()->handle(1, $this->params(['amt' => '100']));

        self::assertFalse($out->success);
        self::assertStringStartsWith(self::FAIL . '?pay_error=', $out->redirect);
        self::assertSame([], $this->kit->requests);
        self::assertSame('AMOUNT_MISMATCH', $this->kit->stepRow('RETURN_FAIL')['result_code']);
        self::assertSame('READY', $this->attemptStatus(), '사전 대조 실패는 다시 결제할 수 있게 둔다');
    }

    public function testConsumerOrderAmountMismatchNeverCallsApproval(): void
    {
        $this->approveOk();
        $this->consumer->prepared = new PreparedPayment('ATTEMPTING', 'innopay', 9000);

        $out = $this->service()->handle(1, $this->params());

        self::assertFalse($out->success);
        self::assertSame([], $this->kit->requests);
    }

    public function testMidMismatchIsRejected(): void
    {
        $out = $this->service()->handle(1, $this->params(['mid' => 'othermid01']));

        self::assertFalse($out->success);
        self::assertSame('MID_MISMATCH', $this->kit->stepRow('RETURN_FAIL')['result_code']);
        self::assertSame([], $this->kit->requests);
    }

    public function testReturnWithoutTokenIsAuthFailure(): void
    {
        $out = $this->service()->handle(1, ['moid' => self::MOID, 'resultMsg' => '사용자 결제 취소']);

        self::assertFalse($out->success);
        self::assertStringContainsString(rawurlencode('사용자 결제 취소'), $out->redirect);
        self::assertSame('FAILED', $this->attemptStatus());
    }

    public function testUnknownMoidShowsMessageWithoutRedirect(): void
    {
        $out = $this->service()->handle(1, $this->params(['moid' => 'UNKNOWN123']));

        self::assertNull($out->redirect);
        self::assertSame('NO_ATTEMPT', $this->kit->stepRow('RETURN_FAIL')['result_code']);
    }

    public function testApprovalErrorFailsAndRecordsCode(): void
    {
        $this->kit->routes['/v1/transactions/pay'] = [400, ['success' => false, 'error' => ['code' => '1010', 'message' => '결제 시간 만료']]];

        $out = $this->service()->handle(1, $this->params());

        self::assertFalse($out->success);
        self::assertStringContainsString(rawurlencode('결제 시간 만료'), $out->redirect);
        self::assertSame('FAILED', $this->attemptStatus());
        self::assertSame('1010', $this->kit->attempts->find(1, self::MOID)['result_code']);
        self::assertSame([], $this->consumer->verifyCalls);
    }

    public function testTimeoutIsRecoveredByQueryingTransaction(): void
    {
        $this->kit->routes['/v1/transactions/pay'] = [0, '', 'Operation timed out'];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => InnopayTestKit::transaction('TID1', self::MOID, 15000)]];

        $out = $this->service()->handle(1, $this->params());

        self::assertTrue($out->success);
        self::assertSame('COMPLETED', $this->attemptStatus());
        self::assertSame('RECOVERED', $this->kit->stepRow('APPROVE_RECOVER')['result_code']);
        self::assertSame('error', $this->kit->stepRow('APPROVE_RES')['level']);
    }

    public function testAlreadyApprovedCodeIsRecoveredByQuery(): void
    {
        $this->kit->routes['/v1/transactions/pay'] = [400, ['success' => false, 'error' => ['code' => '1005', 'message' => '승인 완료된 결제 건']]];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => InnopayTestKit::transaction('TID1', self::MOID, 15000)]];

        self::assertTrue($this->service()->handle(1, $this->params())->success);
    }

    public function testConsumerFailureTriggersAutoCancel(): void
    {
        $this->approveOk();
        $this->kit->routes['/api/cancelApi'] = [200, ['resultCode' => '2001', 'pgApprovalAmt' => '15000']];
        $this->consumer->verifyResult = Result::failure('결제 금액이 일치하지 않습니다.');

        $out = $this->service()->handle(1, $this->params());

        self::assertFalse($out->success);
        self::assertSame('CANCELLED', $this->attemptStatus());
        self::assertSame('CANCELLED', $this->kit->stepRow('AUTO_CANCEL')['result_code']);
        self::assertStringStartsWith(self::FAIL . '?pay_error=', $out->redirect);
    }

    public function testConsumerFailureWithoutCancelPasswordNeedsAction(): void
    {
        $this->approveOk();
        $this->kit->config['cancel_pwd'] = '';
        $this->consumer->verifyResult = Result::failure('DB 오류');

        $out = $this->service()->handle(1, $this->params());

        self::assertFalse($out->success);
        self::assertSame('NEEDS_ACTION', $this->attemptStatus());
        self::assertNotNull($this->kit->stepRow('NEEDS_ACTION'));
        self::assertCount(1, $this->kit->attempts->findNeedsAction(1));
    }

    public function testConsumerFailureButOrderAlreadyPaidIsSuccess(): void
    {
        $this->approveOk();
        $this->consumer->verifyResult = Result::failure('동시 처리');
        $this->consumer->paidAfterVerify = true;

        $out = $this->service()->handle(1, $this->params());

        self::assertTrue($out->success);
        self::assertSame('COMPLETED', $this->attemptStatus());
        self::assertNull($this->kit->stepRow('AUTO_CANCEL'), '이미 반영된 결제는 취소하지 않는다');
    }

    public function testRefreshAfterCompletionDoesNotApproveAgain(): void
    {
        $this->approveOk();
        $this->service()->handle(1, $this->params());
        $requestsBefore = count($this->kit->requests);

        $out = $this->service()->handle(1, $this->params());

        self::assertSame(self::OK, $out->redirect);
        self::assertCount($requestsBefore, $this->kit->requests);
    }

    public function testInFlightApprovalIsNotDuplicated(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'APPROVING']);

        $out = $this->service()->handle(1, $this->params());

        self::assertNull($out->redirect);
        self::assertSame([], $this->kit->requests);
    }

    public function testApprovedButInterruptedResumesWithoutReapproving(): void
    {
        $this->approveOk();
        $this->kit->attempts->update(1, self::MOID, ['status' => 'APPROVED', 'tid' => 'TID1']);

        $out = $this->service()->handle(1, $this->params());

        self::assertTrue($out->success);
        self::assertNotContains('APPROVE_REQ', $this->kit->steps());
        self::assertCount(1, $this->consumer->verifyCalls);
    }

    public function testUnknownConsumerFailsClosed(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['consumer' => 'nobody']);

        $out = $this->service()->handle(1, $this->params());

        self::assertFalse($out->success);
        self::assertSame([], $this->kit->requests);
    }
}

final class FakeConsumer implements PaymentConsumerInterface
{
    public array $verifyCalls = [];
    public ?Result $verifyResult = null;
    public ?PreparedPayment $prepared = null;
    public bool $paidAfterVerify = false;

    public function findPreparedPayment(int $domainId, string $orderNo): ?PreparedPayment
    {
        if ($this->paidAfterVerify && $this->verifyCalls !== []) {
            return new PreparedPayment('PAID', 'innopay', 15000, true);
        }
        return $this->prepared;
    }

    public function verifyPayment(int $domainId, string $orderNo, string $pgKey, string $transactionId): Result
    {
        $this->verifyCalls[] = [$domainId, $orderNo, $pgKey, $transactionId];
        return $this->verifyResult ?? Result::success('결제가 검증되었습니다.');
    }

    public function recordExternalRefund(int $domainId, string $orderNo, string $pgKey, string $transactionId, int $amount, string $reason, bool $fullCancel): Result
    {
        return Result::success();
    }
}
