<?php
declare(strict_types=1);

namespace Tests\PayApp\Unit;

use Mublo\Plugin\PayApp\PayAppClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\PayApp\Support\FakePayAppClient;

class PayAppClientTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function incompleteConfigs(): array
    {
        return [
            '전부 빈 값' => ['', '', ''],
            'userid 없음' => ['', 'KEY', 'VAL'],
            'linkkey 없음' => ['shop', '', 'VAL'],
            'linkval 없음' => ['shop', 'KEY', ''],
        ];
    }

    #[DataProvider('incompleteConfigs')]
    public function testVerifyFeedbackRejectsWhenAnyConfiguredValueIsEmpty(string $userid, string $linkkey, string $linkval): void
    {
        $client = new PayAppClient($userid, $linkkey, $linkval);

        // 설정과 똑같은 값을 보내도(빈 값끼리 비교) 통과하면 안 된다
        $this->assertFalse($client->verifyFeedback(['userid' => $userid, 'linkkey' => $linkkey, 'linkval' => $linkval]));
        $this->assertFalse($client->verifyFeedback([]));
    }

    public function testVerifyFeedbackAcceptsMatchingValues(): void
    {
        $client = new PayAppClient('shop', 'KEY', 'VAL');

        $this->assertTrue($client->verifyFeedback(['userid' => 'shop', 'linkkey' => 'KEY', 'linkval' => 'VAL']));
        $this->assertFalse($client->verifyFeedback(['userid' => 'shop', 'linkkey' => 'KEY', 'linkval' => 'WRONG']));
    }

    public function testVerifyFeedbackChecksHashWhenRequestKeyIsSet(): void
    {
        $client = new PayAppClient('shop', 'KEY', 'VAL', 'REQKEY');
        $base = ['userid' => 'shop', 'linkkey' => 'KEY', 'linkval' => 'VAL', 'responsedate' => '20261109120000'];

        $this->assertFalse($client->verifyFeedback($base), 'hash 없으면 거부');
        $this->assertFalse($client->verifyFeedback($base + ['hash' => hash('sha256', 'shop20261109120000WRONG')]));
        $this->assertTrue($client->verifyFeedback($base + ['hash' => strtoupper(hash('sha256', 'shop20261109120000REQKEY'))]));
    }

    public function testMaskSecretsHidesLinkKeyAndValue(): void
    {
        $masked = PayAppClient::maskSecrets(['linkkey' => 'K', 'linkval' => 'V', 'hash' => 'H', 'mul_no' => '1']);

        $this->assertSame(['linkkey' => '***', 'linkval' => '***', 'hash' => '***', 'mul_no' => '1'], $masked);
    }

    public function testPayCancelFullAndPartialRequestShape(): void
    {
        $client = new FakePayAppClient('shop', 'KEY', 'VAL');
        $client->responses['paycancel'] = ['state' => '1'];

        $client->payCancel('1001', '사유');
        $client->payCancel('1001', '사유', 3000);

        [$full, $partial] = $client->requests;
        $this->assertSame('0', $full['partcancel']);
        $this->assertArrayNotHasKey('cancelprice', $full);
        $this->assertArrayNotHasKey('hash', $full, '결제 KEY 가 없으면 hash 를 싣지 않는다');
        $this->assertSame('1', $partial['partcancel']);
        $this->assertSame('3000', $partial['cancelprice']);
    }

    public function testPayCancelSignsRequestWhenRequestKeyIsSet(): void
    {
        $client = new FakePayAppClient('shop', 'KEY', 'VAL', 'REQKEY');
        $client->responses['paycancel'] = ['state' => '1'];

        $client->payCancel('1001');

        $request = $client->requests[0];
        $this->assertMatchesRegularExpression('/^\d{14}$/', $request['requestdate']);
        $this->assertSame(hash('sha256', 'shop' . $request['requestdate'] . 'REQKEY'), $request['hash']);
    }
}
