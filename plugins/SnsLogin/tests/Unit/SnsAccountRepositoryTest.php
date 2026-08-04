<?php
namespace Tests\SnsLogin\Unit;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use PHPUnit\Framework\TestCase;

class SnsAccountRepositoryTest extends TestCase
{
    public function testTokenUpdatePreservesExistingRefreshTokenWhenProviderOmitsIt(): void
    {
        $updated = null;
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->with('id', '=', 3)->willReturnSelf();
        $query->expects($this->once())->method('update')->willReturnCallback(
            function (array $data) use (&$updated): int {
                $updated = $data;
                return 1;
            },
        );
        $database = $this->createMock(Database::class);
        $database->method('table')->with('plugin_sns_login_accounts')->willReturn($query);
        $encryption = $this->createMock(SensitiveValueCodecInterface::class);
        $encryption->method('encrypt')->willReturnCallback(fn(string $value): string => 'encrypted:' . $value);

        $repository = new SnsAccountRepository($database, $encryption);
        $repository->updateTokens(3, 'new-access', null, '2026-07-27 10:00:00');

        $this->assertSame('encrypted:new-access', $updated['access_token']);
        $this->assertArrayNotHasKey('refresh_token', $updated);
    }
}
