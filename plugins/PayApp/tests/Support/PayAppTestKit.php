<?php
declare(strict_types=1);

namespace Tests\PayApp\Support;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Plugin\PayApp\PayAppGateway;
use Mublo\Plugin\PayApp\Service\PayAppConfigService;
use Mublo\Plugin\PayApp\Service\PayAppFeedbackService;
use PHPUnit\Framework\TestCase;

/**
 * 테스트용 조립 도구 — DB 없이 도메인별 설정을 메모리에 두고, HTTP 는 가짜 클라이언트로 대신한다.
 */
final class PayAppTestKit
{
    public const USERID = 'myshop';
    public const LINKKEY = 'LINKKEY-SECRET-123';
    public const LINKVAL = 'LINKVAL-SECRET-456';

    /** @var array<int, array<string, mixed>> 도메인 → 설정. 없는 도메인은 기본값(빈 비밀) */
    public array $configs = [];

    /** @var list<string> 출력된 로그 */
    public array $logs = [];

    public FakePayAppClient $client;
    public FakeConsumer $consumer;
    public PayAppConfigService $configService;

    public function __construct(TestCase $test)
    {
        $this->configs[1] = [
            'userid' => self::USERID,
            'linkkey' => self::LINKKEY,
            'linkval' => self::LINKVAL,
            'requestkey' => '',
            'mode' => 'live',
            'feedbackurl_path' => '/pay-app/callback/feedback',
            'feedback_ip_allowlist' => '',
        ];

        $this->client = new FakePayAppClient(self::USERID, self::LINKKEY, self::LINKVAL);
        $this->consumer = new FakeConsumer();

        $builder = new \PHPUnit\Framework\MockObject\MockBuilder($test, PayAppConfigService::class);
        $config = $builder->disableOriginalConstructor()->getMock();
        $config->method('getConfig')->willReturnCallback(fn(int $domainId) => $this->configs[$domainId] ?? [
            'userid' => '', 'linkkey' => '', 'linkval' => '', 'requestkey' => '',
            'mode' => 'live', 'feedbackurl_path' => '/pay-app/callback/feedback', 'feedback_ip_allowlist' => '',
        ]);
        $this->configService = $config;
    }

    /**
     * 게이트웨이 — 설정 배열대로 가짜 클라이언트를 만든다. 요청 기록은 $this->client 하나에 모은다.
     */
    public function gateway(int $domainId = 1): PayAppGateway
    {
        return new PayAppGateway($this->configService, $domainId, function (array $config): FakePayAppClient {
            $client = new FakePayAppClient(
                (string) $config['userid'],
                (string) $config['linkkey'],
                (string) $config['linkval'],
                (string) ($config['requestkey'] ?? '')
            );
            // 응답 지정·요청 기록을 공유한다
            $client->responses = &$this->client->responses;
            $client->requests = &$this->client->requests;
            return $client;
        });
    }

    public function service(): PayAppFeedbackService
    {
        $registry = new ContractRegistry();
        $registry->register(PaymentConsumerInterface::class, 'shop', $this->consumer);

        return new PayAppFeedbackService(
            $this->configService,
            $registry,
            fn(int $domainId) => $this->gateway($domainId),
            function (string $line): void {
                $this->logs[] = $line;
            }
        );
    }

    /** 인증을 통과하는 통보 본문 */
    public static function feedback(array $override = []): array
    {
        return $override + [
            'userid' => self::USERID,
            'linkkey' => self::LINKKEY,
            'linkval' => self::LINKVAL,
            'mul_no' => '1001',
            'pay_state' => '4',
            'pay_type' => '1',
            'price' => '15000',
            'var1' => 'S20261109AAA',
            'var2' => '1',
        ];
    }

    /** payinfo 응답 */
    public static function payinfo(int $state, string $var1 = 'S20261109AAA', int $price = 15000): array
    {
        return [
            'state' => '1',
            'usingstate' => (string) $state,
            'var1' => $var1,
            'goodprice' => (string) $price,
            'paytypestr' => '신용카드',
        ];
    }

    public function logText(): string
    {
        return implode("\n", $this->logs);
    }
}
