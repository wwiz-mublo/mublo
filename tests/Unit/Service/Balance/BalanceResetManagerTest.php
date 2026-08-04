<?php

namespace Tests\Unit\Service\Balance;

use Mublo\Infrastructure\Database\Database;
use Mublo\Service\Balance\BalanceResetManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BalanceResetManagerTest extends TestCase
{
    #[Test]
    public function testResetSourceRequiresOuterTransaction(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(false);
        $db->expects($this->never())->method('select');

        $this->expectException(\LogicException::class);

        (new BalanceResetManager($db))->resetSource(1, 'plugin', 'MemberPoint');
    }

    #[Test]
    public function testResetSourceUsesFullSourceIdentityLocksMembersAndReconciles(): void
    {
        $selects = [];
        $executes = [];

        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(true);
        $db->method('select')->willReturnCallback(
            function (string $sql, array $params) use (&$selects): array {
                $selects[] = [$sql, $params];

                return count($selects) === 1
                    ? [['member_id' => 11], ['member_id' => 10], ['member_id' => 11]]
                    : [['member_id' => 10], ['member_id' => 11]];
            }
        );
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$executes): int {
                $executes[] = [$sql, $params];

                return count($executes) === 1 ? 3 : 2;
            }
        );

        $deleted = (new BalanceResetManager($db))->resetSource(7, 'plugin', 'MemberPoint');

        $this->assertSame(3, $deleted);
        $this->assertCount(2, $selects);
        $this->assertStringContainsString('source_type = ?', $selects[0][0]);
        $this->assertStringContainsString('source_name = ?', $selects[0][0]);
        $this->assertSame([7, 'plugin', 'MemberPoint'], $selects[0][1]);
        $this->assertStringContainsString('FOR UPDATE', $selects[1][0]);
        $this->assertSame([7, 10, 11], $selects[1][1]);

        $this->assertCount(2, $executes);
        $this->assertStringContainsString('DELETE FROM balance_logs', $executes[0][0]);
        $this->assertStringContainsString('source_type = ?', $executes[0][0]);
        $this->assertSame([7, 'plugin', 'MemberPoint'], $executes[0][1]);
        $this->assertStringContainsString('UPDATE members', $executes[1][0]);
        $this->assertStringContainsString('SUM(bl.amount)', $executes[1][0]);
        $this->assertSame([7, 7, 10, 11], $executes[1][1]);
    }

    #[Test]
    public function testResetSourceDoesNothingWhenSourceHasNoLogs(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(true);
        $db->method('select')->willReturn([]);
        $db->expects($this->never())->method('execute');

        $deleted = (new BalanceResetManager($db))->resetSource(1, 'plugin', 'MemberPoint');

        $this->assertSame(0, $deleted);
    }

    #[Test]
    public function testResetSourcePropagatesDatabaseFailure(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(true);
        $db->method('select')->willThrowException(new \RuntimeException('database unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        (new BalanceResetManager($db))->resetSource(1, 'plugin', 'MemberPoint');
    }
}
