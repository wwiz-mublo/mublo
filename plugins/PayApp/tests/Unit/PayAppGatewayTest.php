<?php
declare(strict_types=1);

namespace Tests\PayApp\Unit;

use PHPUnit\Framework\TestCase;
use Tests\PayApp\Support\PayAppTestKit;

class PayAppGatewayTest extends TestCase
{
    private PayAppTestKit $kit;

    protected function setUp(): void
    {
        $this->kit = new PayAppTestKit($this);
        $this->kit->client->responses['paycancel'] = ['state' => '1'];
    }

    private function orderData(array $override = []): array
    {
        return $override + [
            'order_no' => 'S20261109AAA',
            'order_name' => '테스트 상품',
            'amount' => 15000,
            'customer_phone' => '01012345678',
            'customer_email' => 'buyer@example.com',
            'customer_name' => '홍길동',
            'base_url' => 'https://shop.example.com',
            'consumer' => 'shop',
            'domain_id' => 1,
        ];
    }

    public function testCancelWithoutAmountIsFullCancel(): void
    {
        $this->kit->gateway()->cancel('1001', 0, '전체');

        $request = $this->kit->client->requestsFor('paycancel')[0];
        $this->assertSame('0', $request['partcancel']);
        $this->assertArrayNotHasKey('cancelprice', $request);
    }

    public function testCancelOfWholeRemainingAmountIsFullCancel(): void
    {
        $this->kit->gateway()->cancel('1001', 15000, '전체', 15000);

        $request = $this->kit->client->requestsFor('paycancel')[0];
        $this->assertSame('0', $request['partcancel']);
    }

    public function testCancelOfPartOfRemainingAmountIsPartialCancel(): void
    {
        $response = $this->kit->gateway()->cancel('1001', 5000, '부분', 15000);

        $request = $this->kit->client->requestsFor('paycancel')[0];
        $this->assertSame('1', $request['partcancel']);
        $this->assertSame('5000', $request['cancelprice']);
        $this->assertTrue($response->success);
        $this->assertSame(5000, $response->cancelAmount);
    }

    public function testCancelWithUnknownRemainingStaysPartial(): void
    {
        $this->kit->gateway()->cancel('1001', 5000, '부분');

        $this->assertSame('1', $this->kit->client->requestsFor('paycancel')[0]['partcancel']);
    }

    public function testPrepareRefusesWithoutLinkval(): void
    {
        $this->kit->configs[1]['linkval'] = '';

        $response = $this->kit->gateway()->prepare($this->orderData());

        $this->assertFalse($response->success);
        $this->assertSame('NOT_CONFIGURED', $response->errorCode);
        $this->assertSame([], $this->kit->client->requests, 'PG 에 요청을 보내지 않는다');
    }

    public function testPrepareExcludesVirtualAccountAndAsksForRetry(): void
    {
        $this->kit->client->responses['payrequest'] = ['state' => '1', 'mul_no' => '1001', 'payurl' => 'https://payapp.kr/x'];

        $response = $this->kit->gateway()->prepare($this->orderData());

        $this->assertTrue($response->success);
        $request = $this->kit->client->requestsFor('payrequest')[0];
        $types = explode(',', $request['openpaytype']);
        $this->assertNotContains('vbank', $types);
        $this->assertContains('card', $types);
        $this->assertSame('y', $request['checkretry']);
        $this->assertSame('buyer@example.com', $request['recvemail']);
        $this->assertStringEndsWith('/pay-app/callback/feedback?by=shop', $request['feedbackurl']);
    }
}
