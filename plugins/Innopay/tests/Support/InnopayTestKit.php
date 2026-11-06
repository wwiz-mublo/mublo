<?php
declare(strict_types=1);

namespace Tests\Innopay\Support;

use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Innopay\InnopayGateway;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository;
use Mublo\Plugin\Innopay\Repository\InnopayLogRepository;
use Mublo\Plugin\Innopay\Service\InnopayConfigService;
use Mublo\Plugin\Innopay\Service\InnopayLogger;
use PHPUnit\Framework\TestCase;

/**
 * 테스트용 조립 도구 — DB 없이 결제 시도·로그를 메모리에 두고, HTTP 는 가짜 전송으로 대신한다.
 */
final class InnopayTestKit
{
    /** @var list<array> 기록된 로그 행 */
    public array $logs = [];

    /** @var list<array{method:string,url:string,headers:array,body:?array}> 보낸 HTTP 요청 */
    public array $requests = [];

    /** @var array<string, callable|array> 경로 접두어 → 응답 [httpCode, body(array|string), error] 또는 callable */
    public array $routes = [];

    public array $config = [
        'mid' => 'testpay01m',
        'merchant_key' => 'MKEY',
        'cancel_pwd' => 'CPWD',
        'pay_method' => 'CARD',
        'default_buyer_email' => '',
        'logo_url' => '',
        'auto_cancel' => 1,
    ];

    public MemoryAttemptRepository $attempts;
    public InnopayLogger $logger;
    public InnopayConfigService $configService;

    public function __construct(private TestCase $test)
    {
        $this->attempts = new MemoryAttemptRepository($this->mock(Database::class));

        $logRepo = $this->mock(InnopayLogRepository::class);
        $logRepo->method('insert')->willReturnCallback(function (array $row): void {
            $this->logs[] = $row;
        });
        $this->logger = new InnopayLogger($logRepo);

        $config = $this->mock(InnopayConfigService::class);
        $config->method('getConfig')->willReturnCallback(fn() => $this->config);
        $this->configService = $config;
    }

    public function gateway(int $domainId = 1): InnopayGateway
    {
        return new InnopayGateway($this->configService, $this->attempts, $this->logger, $domainId, $this->transport(...));
    }

    /** @return list<string> */
    public function steps(): array
    {
        return array_column($this->logs, 'step');
    }

    public function stepRow(string $step): ?array
    {
        foreach ($this->logs as $row) {
            if ($row['step'] === $step) {
                return $row;
            }
        }
        return null;
    }

    /** @return array{0:int,1:string,2:string} */
    private function transport(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body !== null ? json_decode($body, true) : null,
        ];

        $path = substr($url, strlen('https://api.innopay.co.kr'));
        foreach ($this->routes as $prefix => $response) {
            if (str_starts_with($path, $prefix)) {
                $response = is_callable($response) ? $response($method, $path, $body) : $response;
                [$code, $payload, $error] = $response + [200, [], ''];
                return [$code, is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : (string) $payload, $error];
            }
        }

        return [404, '{"success":false,"error":{"code":"404","message":"not routed"}}', ''];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mock(string $class): object
    {
        $builder = new \PHPUnit\Framework\MockObject\MockBuilder($this->test, $class);
        return $builder->disableOriginalConstructor()->getMock();
    }

    /** 이노페이 Transaction 객체 (승인 완료) */
    public static function transaction(string $tid, string $moid, int $amt, int $status = 0, string $payMethod = 'CARD'): array
    {
        return [
            'mid' => 'testpay01m',
            'tid' => $tid,
            'otid' => $tid,
            'moid' => $moid,
            'payMethod' => $payMethod,
            'amt' => $amt,
            'taxFreeAmt' => 0,
            'status' => $status,
            'authNum' => '12345678',
            'approvedAt' => '2026-11-06 12:00:00',
            'receiptUrl' => 'https://pg.innopay.co.kr/pay/issue/CardIssue.jsp?TID=' . $tid,
            'card' => ['fnName' => '국민', 'cardQuota' => '00', 'cardNum' => '12345678****1234'],
        ];
    }
}
