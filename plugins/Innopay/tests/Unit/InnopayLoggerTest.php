<?php
declare(strict_types=1);

namespace Tests\Innopay\Unit;

use Mublo\Plugin\Innopay\Repository\InnopayLogRepository;
use Mublo\Plugin\Innopay\Service\InnopayLogger;
use PHPUnit\Framework\TestCase;

class InnopayLoggerTest extends TestCase
{
    public function testMaskHidesSecretsAtAnyDepth(): void
    {
        $masked = InnopayLogger::mask([
            'headers' => ['Merchant-Key' => 'MKEY', 'Payment-Token' => 'PTOKEN', 'MID' => 'testpay01m'],
            'body' => ['cancelPwd' => 'CPWD', 'refundAcctNo' => '1234567890', 'cancelAmt' => '1000'],
            'paymentToken' => 'PTOKEN',
            'buyer' => ['tel' => '01012345678', 'name' => '홍길동'],
        ]);

        self::assertSame('***', $masked['headers']['Merchant-Key']);
        self::assertSame('***', $masked['headers']['Payment-Token']);
        self::assertSame('testpay01m', $masked['headers']['MID']);
        self::assertSame('***', $masked['body']['cancelPwd']);
        self::assertSame('******7890', $masked['body']['refundAcctNo']);
        self::assertSame('1000', $masked['body']['cancelAmt']);
        self::assertSame('***', $masked['paymentToken']);
        self::assertSame('*******5678', $masked['buyer']['tel']);
        self::assertSame('홍길동', $masked['buyer']['name']);
    }

    public function testLogWritesMaskedJsonAndSurvivesRepositoryFailure(): void
    {
        $rows = [];
        $repo = $this->createMock(InnopayLogRepository::class);
        $repo->method('insert')->willReturnCallback(function (array $row) use (&$rows) {
            $rows[] = $row;
        });

        (new InnopayLogger($repo))->log(1, 'APPROVE_REQ', [
            'moid' => 'M1234567', 'request' => ['headers' => ['Merchant-Key' => 'SECRET']],
        ]);

        self::assertSame('APPROVE_REQ', $rows[0]['step']);
        self::assertStringNotContainsString('SECRET', $rows[0]['request']);

        $broken = $this->createMock(InnopayLogRepository::class);
        $broken->method('insert')->willThrowException(new \RuntimeException('db down'));
        (new InnopayLogger($broken))->log(1, 'RETURN');   // 예외가 밖으로 나오지 않아야 한다
        $this->addToAssertionCount(1);
    }
}
