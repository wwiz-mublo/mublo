<?php
declare(strict_types=1);

namespace Tests\TestPay\Unit;

use Mublo\Plugin\TestPay\TestPayProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestPayProviderTest extends TestCase
{
    #[DataProvider('allowedEnvs')]
    public function testRegistersOnlyInDevelopmentEnvironments(string $env): void
    {
        self::assertTrue(TestPayProvider::isAllowedEnvironment($env));
    }

    /** 운영값·오타·대소문자 변형·빈 값은 모두 거부한다 */
    #[DataProvider('refusedEnvs')]
    public function testRefusesUnknownEnvironments(string $env): void
    {
        self::assertFalse(TestPayProvider::isAllowedEnvironment($env));
    }

    public static function allowedEnvs(): array
    {
        return [['local'], ['development'], ['dev'], ['testing']];
    }

    public static function refusedEnvs(): array
    {
        return [['production'], ['prod'], ['Production'], ['staging'], ['Local'], [''], [' local']];
    }
}
