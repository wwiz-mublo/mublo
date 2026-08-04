<?php

namespace Tests\Unit\Core\Extension;

use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\Database;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MigrationRunnerTest extends TestCase
{
    public function testTrackingTableUniqueKeyIncludesSource(): void
    {
        $pdo = new RecordingMigrationPdo();
        $runner = new MigrationRunner(new Database($pdo));

        $runner->markExecuted('plugin', 'SharedName', '001_create.sql');

        $this->assertStringContainsString(
            'UNIQUE KEY `uk_migration` (`source`, `name`, `file`)',
            $pdo->executedSql[0]
        );
        $this->assertSame(
            ['plugin', 'SharedName', '001_create.sql', null],
            $pdo->statements[0]->executions[0]
        );
    }

    public function testExecutedMigrationLookupIsScopedBySourceAndName(): void
    {
        $pdo = new RecordingMigrationPdo(['001_create.sql']);
        $runner = new MigrationRunner(new Database($pdo));
        $method = (new ReflectionClass($runner))->getMethod('getExecutedMigrations');
        $method->setAccessible(true);

        $executed = $method->invoke($runner, 'package', 'SharedName');

        $this->assertSame(['001_create.sql'], $executed);
        $this->assertStringContainsString('WHERE `source` = ? AND `name` = ?', $pdo->preparedSql[0]);
        $this->assertSame(['package', 'SharedName'], $pdo->statements[0]->executions[0]);
    }

    public function testUnknownColumnInDmlFailsWithoutRecordingMigration(): void
    {
        $directory = sys_get_temp_dir() . '/mublo_migration_' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/001_bad_update.sql', 'UPDATE members SET misspelled_column = 1;');

        try {
            $pdo = new FailingMigrationPdo();
            $runner = new MigrationRunner(new Database($pdo));

            $result = $runner->run('core', '__core__', $directory);

            $this->assertFalse($result['success']);
            $this->assertSame([], $result['executed']);
            $this->assertStringContainsString('001_bad_update.sql', $result['error']);
            $this->assertStringContainsString('misspelled_column', $result['error']);
            $this->assertCount(1, $pdo->preparedSql, '실패한 migration은 INSERT 이력을 준비하면 안 됩니다.');
            $this->assertStringStartsWith('SELECT', ltrim($pdo->preparedSql[0]));
        } finally {
            @unlink($directory . '/001_bad_update.sql');
            @rmdir($directory);
        }
    }

    public function testChangedExecutedMigrationIsReportedAsDrift(): void
    {
        $directory = sys_get_temp_dir() . '/mublo_migration_' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $path = $directory . '/001_create.sql';
        file_put_contents($path, 'CREATE TABLE example (id INT);');

        try {
            $pdo = new RecordingMigrationPdo([[
                'file' => '001_create.sql',
                'checksum' => str_repeat('0', 64),
            ]]);
            $runner = new MigrationRunner(new Database($pdo));

            $result = $runner->run('core', '__core__', $directory);

            $this->assertFalse($result['success']);
            $this->assertSame([], $result['executed']);
            $this->assertStringContainsString('checksum mismatch', $result['error']);
            $this->assertStringContainsString('001_create.sql', $result['error']);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testLegacyMigrationChecksumIsBaselined(): void
    {
        $directory = sys_get_temp_dir() . '/mublo_migration_' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $path = $directory . '/001_create.sql';
        file_put_contents($path, 'CREATE TABLE example (id INT);');

        try {
            $pdo = new RecordingMigrationPdo([[
                'file' => '001_create.sql',
                'checksum' => null,
            ]]);
            $runner = new MigrationRunner(new Database($pdo));

            $status = $runner->getStatus('core', '__core__', $directory);

            $this->assertSame(['001_create.sql'], $status['baselined']);
            $this->assertSame([], $status['drift']);
            $this->assertSame(
                [hash_file('sha256', $path), 'core', '__core__', '001_create.sql'],
                $pdo->statements[1]->executions[0]
            );
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}

class RecordingMigrationPdo extends PDO
{
    /** @var string[] */
    public array $executedSql = [];

    /** @var string[] */
    public array $preparedSql = [];

    /** @var RecordingMigrationStatement[] */
    public array $statements = [];

    /** @param array<int, mixed> $rows */
    public function __construct(private array $rows = [])
    {
    }

    public function exec(string $statement): int|false
    {
        $this->executedSql[] = $statement;
        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;
        $statement = new RecordingMigrationStatement($this->rows);
        $this->statements[] = $statement;
        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_starts_with(ltrim($query), 'SHOW COLUMNS')) {
            return new RecordingMigrationStatement([['Field' => 'checksum']]);
        }

        return new RecordingMigrationStatement([]);
    }
}

class RecordingMigrationStatement extends PDOStatement
{
    /** @var array<int, array<int, mixed>|null> */
    public array $executions = [];

    /** @param array<int, mixed> $rows */
    public function __construct(private array $rows)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->executions[] = $params;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[0] ?? false;
        if (is_array($row)) {
            return array_values($row)[$column] ?? false;
        }

        return $column === 0 ? $row : false;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

class FailingMigrationPdo extends RecordingMigrationPdo
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_starts_with(ltrim($query), 'SHOW COLUMNS')) {
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        $exception = new \PDOException('Unknown column misspelled_column');
        $exception->errorInfo = ['42S22', 1054, 'Unknown column misspelled_column'];
        throw $exception;
    }
}
