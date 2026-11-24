<?php
declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTransactionCallbacksTest extends TestCase
{
    public function testAfterCommitRunsOnlyAfterRootCommit(): void
    {
        $pdo = new CallbackTestPdo();
        $db = new Database($pdo);
        $events = [];

        $db->transaction(function (Database $db) use (&$events): void {
            $db->afterCommit(function () use (&$events): void { $events[] = 'commit'; });
            $events[] = 'inside';
            self::assertSame(['inside'], $events);
        });

        self::assertSame(['inside', 'commit'], $events);
        self::assertFalse($pdo->inTransaction());
    }

    public function testNestedCommitCallbacksWaitForRootCommitInRegistrationOrder(): void
    {
        $db = new Database(new CallbackTestPdo());
        $events = [];

        $db->transaction(function (Database $db) use (&$events): void {
            $db->afterCommit(function () use (&$events): void { $events[] = 'outer-1'; });
            $db->transaction(function (Database $db) use (&$events): void {
                $db->afterCommit(function () use (&$events): void { $events[] = 'inner'; });
            });
            self::assertSame([], $events);
            $db->afterCommit(function () use (&$events): void { $events[] = 'outer-2'; });
        });

        self::assertSame(['outer-1', 'inner', 'outer-2'], $events);
    }

    public function testNestedRollbackRunsItsRollbackCallbacksAndDiscardsCommitCallbacks(): void
    {
        $pdo = new CallbackTestPdo();
        $db = new Database($pdo);
        $events = [];

        $db->transaction(function (Database $db) use (&$events, $pdo): void {
            try {
                $db->transaction(function (Database $db) use (&$events): void {
                    $db->afterCommit(function () use (&$events): void { $events[] = 'inner-commit'; });
                    $db->afterRollback(function () use (&$events): void { $events[] = 'inner-rollback'; });
                    throw new \RuntimeException('stop');
                });
            } catch (DatabaseException) {
                self::assertTrue($pdo->inTransaction());
            }
            $db->afterCommit(function () use (&$events): void { $events[] = 'outer-commit'; });
        });

        self::assertSame(['inner-rollback', 'outer-commit'], $events);
        self::assertContains('ROLLBACK TO SAVEPOINT mublo_sp_1', $pdo->statements);
    }

    public function testOuterRollbackRunsRollbackCallbacksFromSuccessfulNestedScope(): void
    {
        $db = new Database(new CallbackTestPdo());
        $events = [];

        try {
            $db->transaction(function (Database $db) use (&$events): void {
                $db->afterRollback(function () use (&$events): void { $events[] = 'outer'; });
                $db->transaction(function (Database $db) use (&$events): void {
                    $db->afterRollback(function () use (&$events): void { $events[] = 'inner'; });
                });
                throw new \RuntimeException('rollback root');
            });
        } catch (DatabaseException) {
        }

        self::assertSame(['outer', 'inner'], $events);
    }

    public function testExternalTransactionCallbacksWaitForDatabaseCommit(): void
    {
        $pdo = new CallbackTestPdo();
        $pdo->beginTransaction();
        $db = new Database($pdo);
        $events = [];

        $db->transaction(function (Database $db) use (&$events): void {
            $db->afterCommit(function () use (&$events): void { $events[] = 'commit'; });
        });

        self::assertSame([], $events);
        $db->commit();
        self::assertSame(['commit'], $events);
    }

    public function testAfterCommitOutsideTransactionRunsImmediately(): void
    {
        $db = new Database(new CallbackTestPdo());
        $called = false;
        $db->afterCommit(function () use (&$called): void { $called = true; });
        self::assertTrue($called);
    }

    public function testAfterRollbackOutsideTransactionIsRejected(): void
    {
        $db = new Database(new CallbackTestPdo());
        $this->expectException(\LogicException::class);
        $db->afterRollback(static function (): void {});
    }
}

final class CallbackTestPdo extends PDO
{
    private bool $active = false;

    /** @var list<string> */
    public array $statements = [];

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        if ($this->active) {
            throw new \PDOException('already active');
        }
        $this->active = true;
        return true;
    }

    public function commit(): bool
    {
        $this->active = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->active = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        return 0;
    }
}
