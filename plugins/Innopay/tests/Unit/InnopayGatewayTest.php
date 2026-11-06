<?php
declare(strict_types=1);

namespace Tests\Innopay\Unit;

use Mublo\Plugin\Innopay\InnopayGateway;
use PHPUnit\Framework\TestCase;
use Tests\Innopay\Support\InnopayTestKit;

class InnopayGatewayTest extends TestCase
{
    private InnopayTestKit $kit;

    protected function setUp(): void
    {
        $this->kit = new InnopayTestKit($this);
    }

    private function orderData(array $override = []): array
    {
        return $override + [
            'order_no' => 'S20261106ABCDEF',
            'amount' => 15000,
            'order_name' => '테스트 상품 외 1건',
            'customer_name' => '홍길동',
            'customer_phone' => '010-1234-5678',
            'domain_id' => 1,
            'consumer' => 'shop',
            'base_url' => 'https://shop.example.com',
            'success_url' => 'https://shop.example.com/shop/order/S20261106ABCDEF/complete',
            'fail_url' => 'https://shop.example.com/shop/checkout',
        ];
    }

    // ── prepare ─────────────────────────────────────────────────────────

    public function testPrepareBuildsPayParamsAndRecordsAttempt(): void
    {
        $res = $this->kit->gateway()->prepare($this->orderData());

        self::assertTrue($res->success);
        self::assertSame('', $res->transactionId, 'tid 는 결제창 인증 후에 생긴다');

        $params = $res->data['pg_response']['pay_params'];
        self::assertSame('CARD', $params['payMethod']);
        self::assertSame('testpay01m', $params['mid']);
        self::assertSame('S20261106ABCDEF', $params['moid']);
        self::assertSame('15000', $params['amt']);
        self::assertSame('01012345678', $params['buyerTel']);
        self::assertSame('noreply@shop.example.com', $params['buyerEmail'], '이메일이 없으면 도메인으로 채운다');
        self::assertSame('https://shop.example.com/innopay/callback/return', $params['returnUrl']);
        self::assertArrayNotHasKey('merchant_key', $params);
        self::assertStringNotContainsString('MKEY', json_encode($res->toArray()), 'Merchant-Key 는 브라우저로 나가지 않는다');

        $attempt = $this->kit->attempts->find(1, 'S20261106ABCDEF');
        self::assertSame('READY', $attempt['status']);
        self::assertSame('shop', $attempt['consumer']);
        self::assertSame(15000, $attempt['amount']);
        self::assertContains('PREPARE', $this->kit->steps());
    }

    public function testBuyerEmailPrefersOrderThenConfigDefault(): void
    {
        $this->kit->config['default_buyer_email'] = 'order@shop.example.com';

        $fromOrder = $this->kit->gateway()->prepare($this->orderData(['customer_email' => 'buyer@example.com']));
        self::assertSame('buyer@example.com', $fromOrder->data['pg_response']['pay_params']['buyerEmail']);

        $fromConfig = $this->kit->gateway()->prepare($this->orderData(['customer_email' => '']));
        self::assertSame('order@shop.example.com', $fromConfig->data['pg_response']['pay_params']['buyerEmail']);
    }

    public function testPrepareRejectsWhenNotConfigured(): void
    {
        $this->kit->config['merchant_key'] = '';
        $res = $this->kit->gateway()->prepare($this->orderData());

        self::assertFalse($res->success);
        self::assertSame('NOT_CONFIGURED', $res->errorCode);
        self::assertSame('warning', $this->kit->stepRow('PREPARE')['level']);
    }

    public function testPrepareRejectsOrderNoOutsideInnopayFormat(): void
    {
        $res = $this->kit->gateway()->prepare($this->orderData(['order_no' => 'AB#12']));
        self::assertSame('INVALID_ORDER_NO', $res->errorCode);
    }

    public function testPrepareRejectsReturnUrlLongerThan100(): void
    {
        $res = $this->kit->gateway()->prepare($this->orderData(['base_url' => 'https://' . str_repeat('a', 90) . '.com']));
        self::assertSame('RETURN_URL_TOO_LONG', $res->errorCode);
    }

    public function testPrepareRefusesWhileAttemptIsApproved(): void
    {
        $this->kit->gateway()->prepare($this->orderData());
        $this->kit->attempts->update(1, 'S20261106ABCDEF', ['status' => 'APPROVED']);

        $res = $this->kit->gateway()->prepare($this->orderData());
        self::assertSame('ALREADY_PROCESSING', $res->errorCode);
    }

    public function testPrepareAllowsRetryAfterFailure(): void
    {
        $this->kit->gateway()->prepare($this->orderData());
        $this->kit->attempts->update(1, 'S20261106ABCDEF', ['status' => 'FAILED']);

        self::assertTrue($this->kit->gateway()->prepare($this->orderData())->success);
        self::assertSame('READY', $this->kit->attempts->find(1, 'S20261106ABCDEF')['status']);
    }

    public function testEmptyPayMethodIsSentForSelectionWindow(): void
    {
        $this->kit->config['pay_method'] = '';
        $params = $this->kit->gateway()->prepare($this->orderData())->data['pg_response']['pay_params'];

        self::assertArrayHasKey('payMethod', $params);
        self::assertSame('', $params['payMethod']);
    }

    // ── verify ──────────────────────────────────────────────────────────

    public function testVerifyApprovedTransaction(): void
    {
        $tx = InnopayTestKit::transaction('TID1', 'S20261106ABCDEF', 14000);
        $tx['taxFreeAmt'] = 1000;
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];

        $res = $this->kit->gateway()->verify('TID1');

        self::assertTrue($res->success);
        self::assertSame(15000, $res->amount, '총 결제금액 = amt + taxFreeAmt');
        self::assertSame('S20261106ABCDEF', $res->orderNo);
        self::assertSame('CARD', $res->paymentMethod);
        self::assertSame('12345678', $res->approvalNo);
        self::assertSame('DONE', $res->status);

        $req = $this->kit->requests[0];
        self::assertSame('GET', $req['method']);
        self::assertSame('testpay01m', $req['headers']['MID']);
        self::assertSame('MKEY', $req['headers']['Merchant-Key']);
        self::assertSame(['QUERY_TID_REQ', 'QUERY_TID_RES'], $this->kit->steps());
    }

    public function testVerifyEasyPayReadsEpayObject(): void
    {
        // 간편결제 승인 응답 — card 대신 epay 객체가 온다 (이노페이 통합간편결제 문서 예시)
        $tx = InnopayTestKit::transaction('TID1', 'S20261106ABCDEF', 15000, 0, 'EPAY');
        unset($tx['card']);
        $tx['epay'] = ['epayCl' => '01', 'acquCardCode' => '01', 'acquCardName' => '비씨'];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];

        $res = $this->kit->gateway()->verify('TID1');

        self::assertTrue($res->success);
        self::assertSame('CARD', $res->paymentMethod);
        self::assertSame('비씨', $res->data['card_company']);
        self::assertSame('카카오페이', $res->data['epay_type']);
    }

    public function testVerifyCancelledTransactionIsNotSuccessAndHasNoAmount(): void
    {
        // 전체취소된 거래 — 실제 응답처럼 amt 가 빠지고 cancel.amt 만 온다
        $tx = InnopayTestKit::transaction('TID1', 'S20261106ABCDEF', 0, 1);
        unset($tx['amt'], $tx['taxFreeAmt']);
        $tx['cancel'] = ['message' => 'test', 'amt' => 15000];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];

        $res = $this->kit->gateway()->verify('TID1');

        self::assertFalse($res->success);
        self::assertNull($res->amount);
        self::assertSame('CANCELLED', $res->status);
    }

    public function testVerifyQueryError(): void
    {
        $this->kit->routes['/v1/transactions/'] = [400, ['success' => false, 'error' => ['code' => '1011', 'message' => '존재하지 않는 결제 정보']]];

        $res = $this->kit->gateway()->verify('NOPE');

        self::assertFalse($res->success);
        self::assertSame('1011', $res->errorCode);
        self::assertSame('warning', $this->kit->stepRow('QUERY_TID_RES')['level']);
    }

    // ── cancel ──────────────────────────────────────────────────────────

    public function testCancelRequiresCancelPassword(): void
    {
        $this->kit->config['cancel_pwd'] = '';
        $res = $this->kit->gateway()->cancel('TID1', 1000);

        self::assertFalse($res->success);
        self::assertSame('NOT_CONFIGURED', $res->errorCode);
        self::assertSame([], $this->kit->requests, '비밀번호가 없으면 API 를 부르지 않는다');
    }

    public function testFullCancelSendsJsonWithPasswordAndMasksItInLog(): void
    {
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => InnopayTestKit::transaction('TID1', 'M1234567', 15000)]];
        $this->kit->routes['/api/cancelApi'] = [200, ['resultCode' => '2001', 'resultMsg' => '취소 성공', 'pgApprovalAmt' => '15000', 'pgCancelTid' => 'CTID1']];

        $res = $this->kit->gateway()->cancel('TID1', 15000, '고객 요청');

        self::assertTrue($res->success);
        self::assertSame(15000, $res->cancelAmount);
        self::assertSame('CANCELLED', $res->status);
        self::assertSame('CTID1', $res->data['cancel_tid']);

        $cancelReq = $this->kit->requests[1];
        self::assertSame('POST', $cancelReq['method']);
        self::assertStringStartsWith('application/json', $cancelReq['headers']['Content-Type']);
        self::assertSame('01', $cancelReq['body']['svcCd']);
        self::assertSame('0', $cancelReq['body']['partialCancelCode']);
        self::assertSame('CPWD', $cancelReq['body']['cancelPwd']);

        $logged = $this->kit->stepRow('CANCEL_REQ')['request'];
        self::assertStringNotContainsString('CPWD', $logged);
        self::assertStringNotContainsString('MKEY', json_encode($this->kit->logs));
    }

    public function testPartialCancelUsesRemainingFromPartialCancels(): void
    {
        $tx = InnopayTestKit::transaction('TID1', 'M1234567', 10000);
        $tx['partialCancels'] = [['tid' => 'P1', 'amt' => 3000]];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];
        $this->kit->routes['/api/cancelApi'] = [200, ['resultCode' => '2001', 'pgApprovalAmt' => '2000']];

        $res = $this->kit->gateway()->cancel('TID1', 2000);

        self::assertTrue($res->success);
        self::assertSame('1', $this->kit->requests[1]['body']['partialCancelCode'], '잔액 7000 중 2000 → 부분취소');
        self::assertSame('PARTIAL_CANCELLED', $res->status);
    }

    public function testCancelWholeRemainderWhenAmountIsZero(): void
    {
        $tx = InnopayTestKit::transaction('TID1', 'M1234567', 10000);
        $tx['partialCancels'] = [['tid' => 'P1', 'amt' => 3000]];
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => $tx]];
        $this->kit->routes['/api/cancelApi'] = [200, ['resultCode' => '2001']];

        $this->kit->gateway()->cancel('TID1', 0);

        self::assertSame('7000', $this->kit->requests[1]['body']['cancelAmt']);
        self::assertSame('0', $this->kit->requests[1]['body']['partialCancelCode']);
    }

    public function testCancelFailureReportsInnopayCode(): void
    {
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => InnopayTestKit::transaction('TID1', 'M1234567', 10000)]];
        $this->kit->routes['/api/cancelApi'] = [200, ['resultCode' => '2012', 'resultMsg' => '취소 해당거래 없음']];

        $res = $this->kit->gateway()->cancel('TID1', 10000);

        self::assertFalse($res->success);
        self::assertSame('2012', $res->errorCode);
        self::assertStringContainsString('취소 해당거래 없음', $res->errorMessage);
    }

    public function testVirtualAccountIsNotAutoCancellable(): void
    {
        $this->kit->routes['/v1/transactions/TID1'] = [200, ['success' => true, 'data' => InnopayTestKit::transaction('TID1', 'M1234567', 10000, 0, 'VBANK')]];

        $res = $this->kit->gateway()->cancel('TID1', 10000);

        self::assertSame('UNSUPPORTED_METHOD', $res->errorCode);
        self::assertCount(1, $this->kit->requests, '취소 API 는 부르지 않는다');
    }

    // ── 매핑 ────────────────────────────────────────────────────────────

    public function testServiceCodeAndPayMethodMapping(): void
    {
        self::assertSame('01', InnopayGateway::serviceCode('EPAY'));
        self::assertSame('02', InnopayGateway::serviceCode('BANK'));
        self::assertNull(InnopayGateway::serviceCode('VBANK'));
        self::assertSame('BANK', InnopayGateway::mapPayMethod('EBANK'));
        self::assertSame('CARD', InnopayGateway::mapPayMethod('OPCARD'));
    }

    public function testCheckoutScriptRegistersHandlerWithoutPreloadingSdk(): void
    {
        $js = $this->kit->gateway()->getCheckoutScript();

        self::assertStringContainsString("MubloPayHandlers['innopay']", $js);
        self::assertStringContainsString("typeof innopay !== 'undefined'", $js, 'SDK 는 const 선언이라 window 속성이 아니다');
        self::assertStringContainsString('https://pg.innopay.co.kr/tpay/js/v1/innopay.js', $js);
    }
}
