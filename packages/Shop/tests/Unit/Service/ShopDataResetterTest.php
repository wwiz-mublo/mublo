<?php
namespace Tests\Shop\Unit\Service;
use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Service\ShopDataResetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
final class ShopDataResetterTest extends TestCase
{
    #[Test]
    public function transactionResetPurgesDomainRowsAndShopLedger(): void
    {
        $queries = [];
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$queries): int { $queries[] = [$sql, $params]; return 1; });
        $gateway = $this->createMock(BalanceResetGatewayInterface::class);
        $gateway->expects($this->once())->method('resetSource')->with(9, 'package', 'Shop')->willReturn(3);
        $result = (new ShopDataResetter($db, $gateway))->reset('shop_transactions', 9);
        $this->assertGreaterThan(1, $result->tablesCleared);
        foreach ($queries as [, $params]) $this->assertContains(9, $params);
    }
}
