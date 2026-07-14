<?php

namespace Tests\Support;

use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;
use PHPUnit\Framework\Attributes\Test;

trait DataResetterContractTests
{
    /** @return class-string<DataResettableInterface> */
    abstract protected function resetterClass(): string;

    abstract protected function resetCategory(): string;

    /** @return list<string> */
    abstract protected function expectedTables(): array;

    abstract protected function expectedTablesCleared(): int;

    abstract protected function expectedDeleteCount(): int;

    protected function createResetter(Database $db): DataResettableInterface
    {
        $class = $this->resetterClass();
        return new $class($db);
    }

    #[Test]
    public function testResetUsesDomainScopedDeletesAndReportsClearedTables(): void
    {
        $checkedTables = [];
        $executed = [];
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturnCallback(
            function (string $table) use (&$checkedTables): bool {
                $checkedTables[] = $table;
                return true;
            }
        );
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$executed): int {
                $executed[] = [$sql, $params];
                return 1;
            }
        );

        $resetter = $this->createResetter($db);
        $result = $resetter->reset($this->resetCategory(), 987654);

        $this->assertSame($this->expectedTablesCleared(), $result->tablesCleared);
        $this->assertSame($this->expectedTables(), $checkedTables);

        $deletes = array_values(array_filter(
            $executed,
            static fn(array $query): bool => str_contains(strtoupper($query[0]), 'DELETE')
        ));
        $this->assertCount($this->expectedDeleteCount(), $deletes);
        foreach ($deletes as [$sql, $params]) {
            $this->assertStringContainsString('domain_id', $sql);
            $this->assertContains(987654, $params);
        }
    }

    #[Test]
    public function testDatabaseFailureIsNotTreatedAsMissingTable(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willThrowException(new \RuntimeException('database unavailable'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $this->createResetter($db)->reset($this->resetCategory(), 1);
    }

    #[Test]
    public function testUnknownCategoryDoesNotTouchDatabase(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('tableExists');
        $result = $this->createResetter($db)->reset('unknown', 1);

        $this->assertSame(0, $result->tablesCleared);
    }
}
