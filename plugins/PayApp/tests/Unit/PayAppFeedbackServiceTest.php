<?php
declare(strict_types=1);

namespace Tests\PayApp\Unit;

use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Result\Result;
use PHPUnit\Framework\TestCase;
use Tests\PayApp\Support\PayAppTestKit;

class PayAppFeedbackServiceTest extends TestCase
{
    private const ORDER = 'S20261109AAA';

    private PayAppTestKit $kit;

    protected function setUp(): void
    {
        $this->kit = new PayAppTestKit($this);
        $this->kit->client->responses['paycancel'] = ['state' => '1'];
    }

    private function handle(array $data, string $by = 'shop', string $ip = '203.0.113.10'): \Mublo\Plugin\PayApp\Service\PayAppFeedbackOutcome
    {
        return $this->kit->service()->handle($data, $by, 1, $ip);
    }

    // ── 인증 ────────────────────────────────────────────────────────────

    public function testFeedbackForUnconfiguredDomainIsRejected(): void
    {
        // 설정이 없는 도메인 2 를 지목하고 비밀을 비워 보낸다 — 예전엔 빈 값끼리 비교가 통과했다
        $outcome = $this->handle(PayAppTestKit::feedback([
            'var2' => '2', 'userid' => '', 'linkkey' => '', 'linkval' => '',
        ]));

        $this->assertSame(403, $outcome->status);
        $this->assertSame('FAIL', $outcome->body);
        $this->assertSame([], $this->kit->consumer->verifyCalls);
        $this->assertSame([], $this->kit->client->requests);
    }

    public function testFeedbackWithWrongLinkvalIsRejected(): void
    {
        $outcome = $this->handle(PayAppTestKit::feedback(['linkval' => 'guess']));

        $this->assertSame(403, $outcome->status);
        $this->assertSame([], $this->kit->consumer->verifyCalls);
    }

    public function testFeedbackOutsideIpAllowlistIsRejected(): void
    {
        $this->kit->configs[1]['feedback_ip_allowlist'] = '198.51.100.0/24';

        $this->assertSame(403, $this->handle(PayAppTestKit::feedback(), 'shop', '203.0.113.10')->status);
        $this->assertSame(200, $this->handle(PayAppTestKit::feedback(), 'shop', '198.51.100.7')->status);
    }

    // ── 결제완료 ─────────────────────────────────────────────────────────

    public function testPaidFeedbackIsVerifiedByConsumer(): void
    {
        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertTrue($outcome->isOk());
        $this->assertSame([[1, self::ORDER, 'payapp', '1001']], $this->kit->consumer->verifyCalls);
        $this->assertSame([], $this->kit->client->requestsFor('paycancel'));
    }

    public function testConsumerFailureAutoCancelsAtPayApp(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('주문을 찾을 수 없습니다.'), Result::failure('주문을 찾을 수 없습니다.')];
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(4);

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertTrue($outcome->isOk(), '재전송해도 결과가 같으므로 SUCCESS');
        $this->assertSame('AUTO_CANCELLED', $outcome->note);
        $cancel = $this->kit->client->requestsFor('paycancel');
        $this->assertCount(1, $cancel);
        $this->assertSame('1001', $cancel[0]['mul_no']);
        $this->assertSame('0', $cancel[0]['partcancel'], '전액 취소');
    }

    public function testConsumerFailureRecheckSuccessDoesNotCancel(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('PG 결제가 완료 상태가 아닙니다.')];
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(4);

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertSame('PAID_ON_RECHECK', $outcome->note);
        $this->assertSame([], $this->kit->client->requestsFor('paycancel'));
    }

    public function testConsumerFailureButAlreadyPaidDoesNotCancel(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('결제 검증 가능한 상태가 아닙니다')];
        $this->kit->consumer->prepared = new PreparedPayment('PAID', 'payapp', 15000, true);

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertSame('ALREADY_PAID', $outcome->note);
        $this->assertSame([], $this->kit->client->requests);
    }

    public function testConsumerExceptionAsksForRetryWithoutCancel(): void
    {
        $this->kit->consumer->verifyResults = [new \RuntimeException('DB down')];

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertSame(500, $outcome->status);
        $this->assertSame('FAIL', $outcome->body);
        $this->assertSame([], $this->kit->client->requestsFor('paycancel'));
    }

    public function testPayinfoFailureAsksForRetryWithoutCancel(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('x')];
        $this->kit->client->responses['payinfo'] = ['state' => '-1', 'errorMessage' => 'cURL error'];

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertSame(500, $outcome->status);
        $this->assertSame([], $this->kit->client->requestsFor('paycancel'));
    }

    public function testPaymentOfAnotherOrderIsNotCancelled(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('x')];
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(4, 'OTHER-ORDER');

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertTrue($outcome->isOk());
        $this->assertSame([], $this->kit->client->requestsFor('paycancel'));
        $this->assertStringContainsString('[CRITICAL]', $this->kit->logText());
    }

    public function testCancelRejectedByPayAppLogsCriticalAndStopsRetry(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('x'), Result::failure('x')];
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(4);
        $this->kit->client->responses['paycancel'] = ['state' => '0', 'errno' => '80000', 'errorMessage' => '정산 완료'];

        $outcome = $this->handle(PayAppTestKit::feedback(['pay_type' => '7', 'vbank' => '국민', 'vbankno' => '123-456']));

        $this->assertTrue($outcome->isOk());
        $this->assertSame('NEEDS_ACTION', $outcome->note);
        $this->assertStringContainsString('[PayApp][CRITICAL]', $this->kit->logText());
        $this->assertStringContainsString('가상계좌 입금', $this->kit->logText());
        $this->assertStringContainsString('mul_no=1001', $this->kit->logText());
    }

    public function testCancelTransportErrorAsksForRetry(): void
    {
        $this->kit->consumer->verifyResults = [Result::failure('x'), Result::failure('x')];
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(4);
        $this->kit->client->responses['paycancel'] = ['state' => '-1', 'errorMessage' => 'cURL error: timeout'];

        $outcome = $this->handle(PayAppTestKit::feedback());

        $this->assertSame(500, $outcome->status);
        $this->assertStringContainsString('[CRITICAL]', $this->kit->logText());
    }

    // ── 가상계좌 발급 ────────────────────────────────────────────────────

    public function testVirtualAccountWaitingIsNotFinalizedAndIsCancelled(): void
    {
        $outcome = $this->handle(PayAppTestKit::feedback(['pay_state' => '10', 'pay_type' => '7', 'vbankno' => '123-456']));

        $this->assertTrue($outcome->isOk());
        $this->assertSame('VBANK_CANCELLED', $outcome->note);
        $this->assertSame([], $this->kit->consumer->verifyCalls, '주문을 확정하지 않는다');
        $this->assertSame([], $this->kit->consumer->refundCalls);
        $this->assertCount(1, $this->kit->client->requestsFor('paycancel'));
    }

    // ── 취소·환불 ────────────────────────────────────────────────────────

    public function testUnpaidRequestCancelIsNotRecordedAsRefund(): void
    {
        foreach (['8', '32'] as $state) {
            $this->assertTrue($this->handle(PayAppTestKit::feedback(['pay_state' => $state]))->isOk());
        }
        $this->assertSame([], $this->kit->consumer->refundCalls);
    }

    public function testFullRefundIsRecordedAfterPayAppConfirms(): void
    {
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(9);

        $outcome = $this->handle(PayAppTestKit::feedback(['pay_state' => '9', 'cancelmemo' => '고객 요청']));

        $this->assertSame('REFUND_RECORDED', $outcome->note);
        $this->assertCount(1, $this->kit->consumer->refundCalls);
        $call = $this->kit->consumer->refundCalls[0];
        $this->assertTrue($call['fullCancel']);
        $this->assertSame('1001', $call['transactionId']);
        $this->assertSame('고객 요청', $call['reason']);
    }

    public function testPartialRefundUsesPriceAndOriginalMulNo(): void
    {
        $this->kit->client->responses['payinfo'] = function (array $request): array {
            return $request['mul_no'] === '1001' ? PayAppTestKit::payinfo(71) : ['state' => '0'];
        };

        $outcome = $this->handle(PayAppTestKit::feedback([
            'pay_state' => '71', 'mul_no' => '2002', 'orig_mul_no' => '1001', 'price' => '3000', 'orig_price' => '15000',
        ]));

        $this->assertSame('REFUND_RECORDED', $outcome->note);
        $call = $this->kit->consumer->refundCalls[0];
        $this->assertFalse($call['fullCancel']);
        $this->assertSame(3000, $call['amount']);
        $this->assertSame('2002', $call['transactionId']);
    }

    public function testRefundForPaymentOfAnotherOrderIsNotRecorded(): void
    {
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(9, 'OTHER-ORDER');

        $outcome = $this->handle(PayAppTestKit::feedback(['pay_state' => '9']));

        $this->assertTrue($outcome->isOk());
        $this->assertSame([], $this->kit->consumer->refundCalls);
    }

    public function testRefundForNonPayAppOrderIsNotRecorded(): void
    {
        $this->kit->consumer->prepared = new PreparedPayment('PAID', 'innopay', 15000, true);
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(9);

        $this->handle(PayAppTestKit::feedback(['pay_state' => '9']));

        $this->assertSame([], $this->kit->consumer->refundCalls);
    }

    public function testRefundNotYetCancelledAtPayAppAsksForRetry(): void
    {
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(4);

        $outcome = $this->handle(PayAppTestKit::feedback(['pay_state' => '9']));

        $this->assertSame(500, $outcome->status);
        $this->assertSame([], $this->kit->consumer->refundCalls);
    }

    public function testRefundRecordingExceptionAsksForRetry(): void
    {
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(64);
        $this->kit->consumer->refundResult = new \RuntimeException('DB down');

        $outcome = $this->handle(PayAppTestKit::feedback(['pay_state' => '64']));

        $this->assertSame(500, $outcome->status);
        $this->assertSame('FAIL', $outcome->body);
    }

    public function testRefundRecordingFailureAsksForRetry(): void
    {
        $this->kit->client->responses['payinfo'] = PayAppTestKit::payinfo(64);
        $this->kit->consumer->refundResult = Result::failure('주문을 찾을 수 없습니다.');

        $outcome = $this->handle(PayAppTestKit::feedback(['pay_state' => '64']));

        $this->assertSame(500, $outcome->status);
        $this->assertStringContainsString('[CRITICAL]', $this->kit->logText());
    }

    // ── 로그 ────────────────────────────────────────────────────────────

    public function testSecretsAreMaskedInLogPayload(): void
    {
        // 주문번호 없는 환불 통보는 본문 전체를 로그로 남기는 경로다
        $this->handle(PayAppTestKit::feedback(['pay_state' => '9', 'var1' => '']));
        // 인증 실패 경로
        $this->handle(PayAppTestKit::feedback(['linkkey' => 'ATTACKER-KEY']));

        $log = $this->kit->logText();
        $this->assertStringContainsString('Refund webhook missing order/mul_no', $log);
        $this->assertStringNotContainsString(PayAppTestKit::LINKKEY, $log);
        $this->assertStringNotContainsString(PayAppTestKit::LINKVAL, $log);
        $this->assertStringNotContainsString('ATTACKER-KEY', $log);
    }
}
