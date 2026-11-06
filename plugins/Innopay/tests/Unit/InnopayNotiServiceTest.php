<?php
declare(strict_types=1);

namespace Tests\Innopay\Unit;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Result\Result;
use Mublo\Plugin\Innopay\Service\InnopayNotiService;
use Mublo\Plugin\Innopay\Service\InnopayReturnService;
use PHPUnit\Framework\TestCase;
use Tests\Innopay\Support\FakeConsumer;
use Tests\Innopay\Support\InnopayTestKit;

class InnopayNotiServiceTest extends TestCase
{
    private const MOID = 'S20261106ABCDEF';

    private InnopayTestKit $kit;
    private FakeConsumer $consumer;

    protected function setUp(): void
    {
        $this->kit = new InnopayTestKit($this);
        $this->consumer = new FakeConsumer();

        $this->kit->attempts->prepare(1, self::MOID, [
            'consumer' => 'shop',
            'success_url' => 'https://shop.example.com/ok',
            'fail_url' => 'https://shop.example.com/fail',
            'pay_method' => 'CARD',
            'amount' => 15000,
        ]);
    }

    private function service(): InnopayNotiService
    {
        $registry = new ContractRegistry();
        $registry->register(PaymentConsumerInterface::class, 'shop', $this->consumer);
        $factory = fn(int $domainId) => $this->kit->gateway($domainId);

        $return = new InnopayReturnService($this->kit->configService, $this->kit->attempts, $this->kit->logger, $registry, $factory);

        return new InnopayNotiService(
            $this->kit->configService,
            $this->kit->attempts,
            $this->kit->cancels,
            $this->kit->logger,
            $return,
            $factory
        );
    }

    private function approveNoti(array $override = []): array
    {
        return $override + [
            'shopCode' => 'testpay01m',
            'transSeq' => 'TID1',
            'moid' => self::MOID,
            'goodsAmt' => '15000',
            'approvalAmt' => '15000',
            'payMethod' => '0108',
            'status' => '25',
            'stateCd' => '0',
            'cardNo' => '1234567812345678',
            'buyerPhoneNo' => '01012345678',
        ];
    }

    private function cancelNoti(array $override = []): array
    {
        return $override + $this->approveNoti([
            'status' => '85',
            'stateCd' => '1',
            'cancelAmt' => '15000',
            'cancelMsg' => '상점관리 취소',
            'cancelPgTid' => 'CPGTID1',
        ]);
    }

    private function txRoute(int $status = 0, array $extra = []): void
    {
        $tx = InnopayTestKit::transaction('TID1', self::MOID, 15000, $status) + $extra;
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];
    }

    private function attemptStatus(): string
    {
        return $this->kit->attempts->find(1, self::MOID)['status'];
    }

    // ── 승인 통보 ────────────────────────────────────────────────────────

    public function testApproveNotiCompletesAttemptWhoseReturnWasLost(): void
    {
        // 구매자가 승인 직후 창을 닫아 복귀 처리가 주문 반영까지 못 간 경우
        $this->kit->attempts->update(1, self::MOID, ['status' => 'APPROVED', 'tid' => 'TID1']);
        $this->txRoute();

        $reply = $this->service()->handle(1, $this->approveNoti(), '1.2.3.4');

        self::assertSame('0000', $reply);
        self::assertSame('COMPLETED', $this->attemptStatus());
        self::assertSame([[1, self::MOID, 'innopay', 'TID1']], $this->consumer->verifyCalls);
        self::assertSame('COMPLETED', $this->lastNotiResult()['result_code']);
    }

    public function testApproveNotiRecoversAttemptMarkedFailed(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'FAILED']);
        $this->txRoute();

        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti()));
        self::assertSame('COMPLETED', $this->attemptStatus());
    }

    public function testApproveNotiForCompletedAttemptIsIdempotent(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED']);

        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti()));
        self::assertSame([], $this->kit->requests, '이미 반영된 건은 조회도 하지 않는다');
        self::assertSame([], $this->consumer->verifyCalls);
    }

    public function testApproveNotiWhileReturnIsApprovingAsksForRetry(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'APPROVING']);

        self::assertSame('FAIL', $this->service()->handle(1, $this->approveNoti()));
    }

    public function testApproveNotiIsNotTrustedWithoutQueryConfirmation(): void
    {
        // 위조된 통보 — 거래조회로는 승인 거래가 없다
        $this->kit->routes['/v1/transactions/'] = [400, ['success' => false, 'error' => ['code' => '1011', 'message' => '존재하지 않는 결제 정보']]];

        $reply = $this->service()->handle(1, $this->approveNoti());

        self::assertSame('0000', $reply);
        self::assertSame('READY', $this->attemptStatus());
        self::assertSame([], $this->consumer->verifyCalls);
        self::assertSame('NOT_APPROVED', $this->lastNotiResult()['result_code']);
    }

    public function testQueryNetworkErrorAsksForRetry(): void
    {
        $this->kit->routes['/v1/transactions/'] = [0, '', 'timeout'];

        self::assertSame('FAIL', $this->service()->handle(1, $this->approveNoti()));
    }

    public function testApproveNotiWithAmountMismatchNeedsAction(): void
    {
        $tx = InnopayTestKit::transaction('TID1', self::MOID, 9000);
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];

        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti()));
        self::assertSame('NEEDS_ACTION', $this->attemptStatus());
        self::assertSame([], $this->consumer->verifyCalls);
    }

    public function testMidMismatchIsRejected(): void
    {
        self::assertSame('FAIL', $this->service()->handle(1, $this->approveNoti(['shopCode' => 'othermid01'])));
        self::assertSame([], $this->kit->requests);
    }

    public function testIpAllowlistRejectsUnknownSender(): void
    {
        $this->kit->config['noti_ip_allowlist'] = '211.1.2.0/24';

        self::assertSame('FAIL', $this->service()->handle(1, $this->approveNoti(), '8.8.8.8'));
        $this->txRoute();
        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti(), '211.1.2.77'));
    }

    public function testNotiForUnknownOrderIsAcknowledged(): void
    {
        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti(['moid' => 'OTHERSYSTEM1'])));
        self::assertSame('NOT_OURS', $this->lastNotiResult()['result_code']);
    }

    public function testNotiParamsAreLoggedWithPersonalDataMasked(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED']);
        $this->service()->handle(1, $this->approveNoti());

        $logged = $this->kit->stepRow('NOTI')['request'];
        self::assertStringNotContainsString('1234567812345678', $logged);
        self::assertStringNotContainsString('01012345678', $logged);
        self::assertStringContainsString('5678', $logged);
    }

    public function testEucKrValuesAreConvertedToUtf8(): void
    {
        $converted = InnopayNotiService::toUtf8(['goodsName' => mb_convert_encoding('갤럭시', 'CP949', 'UTF-8'), 'plain' => '테스트']);

        self::assertSame('갤럭시', $converted['goodsName']);
        self::assertSame('테스트', $converted['plain']);
    }

    // ── 취소 통보 ────────────────────────────────────────────────────────

    public function testExternalCancelIsRecordedAsRefundOnce(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED', 'tid' => 'TID1']);
        $this->txRoute(1, ['cancel' => ['amt' => 15000]]);

        self::assertSame('0000', $this->service()->handle(1, $this->cancelNoti()));
        self::assertCount(1, $this->consumer->refundCalls);
        $call = $this->consumer->refundCalls[0];
        self::assertSame(15000, $call['amount']);
        self::assertTrue($call['fullCancel']);
        self::assertSame('TID1', $call['transactionId']);
        self::assertSame('CANCELLED', $this->attemptStatus());

        // 이노페이 재전송 — 다시 기록하지 않는다
        self::assertSame('0000', $this->service()->handle(1, $this->cancelNoti()));
        self::assertCount(1, $this->consumer->refundCalls);
        self::assertSame('DUPLICATE', $this->lastNotiResult()['result_code']);
    }

    public function testOwnApiCancelNotiIsNotRecordedAgain(): void
    {
        // 관리자 환불(API 취소) → 소비 패키지가 이미 반영 → 같은 취소의 통보가 온다
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED', 'tid' => 'TID1']);
        $this->txRoute();
        $this->kit->routes['/api/cancelApi'] = [200, ['resultCode' => '2001', 'pgApprovalAmt' => '5000', 'pgCancelTid' => 'C1']];
        self::assertTrue($this->kit->gateway()->cancel('TID1', 5000, '부분 환불', 15000)->success);

        $this->txRoute(0, ['partialCancels' => [['tid' => 'P1', 'amt' => 5000]]]);
        $reply = $this->service()->handle(1, $this->cancelNoti(['stateCd' => '0', 'cancelAmt' => '5000', 'cancelPgTid' => 'X1']));

        self::assertSame('0000', $reply);
        self::assertSame([], $this->consumer->refundCalls);
        self::assertSame('OWN_CANCEL', $this->lastNotiResult()['result_code']);
        self::assertSame('COMPLETED', $this->attemptStatus(), '부분취소는 시도 상태를 바꾸지 않는다');
    }

    public function testPartialExternalCancelOnPartialCancelTransaction(): void
    {
        // 부분취소 통보가 취소 거래(otid=원거래)를 가리키는 경우
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED', 'tid' => 'TID1']);
        $cancelTx = InnopayTestKit::transaction('PTID1', self::MOID, 0, 2);
        $cancelTx['otid'] = 'TID1';
        $this->kit->routes['/v1/transactions/PTID1'] = [200, ['success' => true, 'data' => $cancelTx]];
        $this->txRoute(0, ['partialCancels' => [['tid' => 'PTID1', 'amt' => 3000]]]);

        $reply = $this->service()->handle(1, $this->cancelNoti(['transSeq' => 'PTID1', 'stateCd' => '2', 'cancelAmt' => '3000']));

        self::assertSame('0000', $reply);
        self::assertSame(3000, $this->consumer->refundCalls[0]['amount']);
        self::assertFalse($this->consumer->refundCalls[0]['fullCancel']);
        self::assertSame('TID1', $this->consumer->refundCalls[0]['transactionId']);
    }

    public function testCancelNotiWithoutRealCancelIsIgnored(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED', 'tid' => 'TID1']);
        $this->txRoute(0);

        self::assertSame('0000', $this->service()->handle(1, $this->cancelNoti()));
        self::assertSame([], $this->consumer->refundCalls);
        self::assertSame('NOT_CANCELLED', $this->lastNotiResult()['result_code']);
    }

    public function testConsumerRefundFailureAsksForRetry(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'COMPLETED', 'tid' => 'TID1']);
        $this->txRoute(1, ['cancel' => ['amt' => 15000]]);
        $this->consumer->refundResult = Result::failure('DB 오류');

        self::assertSame('FAIL', $this->service()->handle(1, $this->cancelNoti()));
        self::assertFalse($this->kit->cancels->existsNoti(1, 'CPGTID1'), '실패한 반영은 기록하지 않아 재통보 때 다시 시도한다');
    }

    public function testIpAllowlistHelper(): void
    {
        self::assertTrue(InnopayNotiService::ipAllowed('1.2.3.4', ''));
        self::assertTrue(InnopayNotiService::ipAllowed('1.2.3.4', '9.9.9.9, 1.2.3.4'));
        self::assertTrue(InnopayNotiService::ipAllowed('10.0.5.9', '10.0.0.0/16'));
        self::assertFalse(InnopayNotiService::ipAllowed('10.1.5.9', '10.0.0.0/16'));
        self::assertFalse(InnopayNotiService::ipAllowed('', '10.0.0.0/16'));
    }

    // ── 가상계좌 입금 통보 ───────────────────────────────────────────────

    private function vbankTx(int $amt = 15000): void
    {
        $tx = InnopayTestKit::transaction('TID1', self::MOID, $amt, 0, 'VBANK');
        unset($tx['card']);
        $tx['virtualAccount'] = ['accountNumber' => '28284335018607', 'bankName' => '우리은행', 'expDate' => '20261109'];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];
    }

    public function testDepositNotiCompletesWaitingVirtualAccount(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'DEPOSIT_WAITING', 'tid' => 'TID1']);
        $this->vbankTx();

        $reply = $this->service()->handle(1, $this->approveNoti(['payMethod' => '08', 'vacctNo' => '28284335018607']));

        self::assertSame('0000', $reply);
        self::assertSame('COMPLETED', $this->attemptStatus());
        self::assertSame([[1, self::MOID, 'innopay', 'TID1']], $this->consumer->verifyCalls);
        self::assertSame('DEPOSIT_COMPLETED', $this->lastNotiResult()['result_code']);
    }

    public function testDepositNotiNotVisibleInQueryAsksForRetry(): void
    {
        // 입금 전 가상계좌는 조회에 {} 로 나온다 — 위조 통보이거나 반영 지연이다
        $this->kit->attempts->update(1, self::MOID, ['status' => 'DEPOSIT_WAITING', 'tid' => 'TID1']);
        $this->kit->routes['/v1/transactions/'] = [200, ['success' => true, 'data' => []]];

        self::assertSame('FAIL', $this->service()->handle(1, $this->approveNoti(['payMethod' => '08'])));
        self::assertSame('DEPOSIT_WAITING', $this->attemptStatus());
        self::assertSame([], $this->consumer->verifyCalls);
    }

    public function testDepositWithDifferentAmountNeedsAction(): void
    {
        $this->kit->attempts->update(1, self::MOID, ['status' => 'DEPOSIT_WAITING', 'tid' => 'TID1']);
        $this->vbankTx(9000);

        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti(['payMethod' => '08'])));
        self::assertSame('NEEDS_ACTION', $this->attemptStatus());
        self::assertSame([], $this->consumer->verifyCalls);
    }

    public function testVirtualAccountDepositForAttemptNotWaitingIsIgnored(): void
    {
        // 가상계좌를 지원하지 않아 닫았던 주문(FAILED)에 입금 통보가 오면 반영하지 않는다
        $this->kit->attempts->update(1, self::MOID, ['status' => 'FAILED', 'tid' => 'TID1']);
        $this->vbankTx();

        self::assertSame('0000', $this->service()->handle(1, $this->approveNoti(['payMethod' => '08'])));
        self::assertSame([], $this->consumer->verifyCalls);
        self::assertSame('VBANK_NOT_WAITING', $this->lastNotiResult()['result_code']);
    }

    private function lastNotiResult(): array
    {
        $rows = array_values(array_filter($this->kit->logs, fn($r) => $r['step'] === 'NOTI_RESULT'));
        return $rows[count($rows) - 1];
    }
}
