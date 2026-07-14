<?php

namespace Tests\Unit\Service\System;

use Mublo\Infrastructure\Database\Database;
use Mublo\Service\System\DatabaseBackupService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/mublo-db-backup-test-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->backupDir);
        parent::tearDown();
    }

    public function testCreatesRestorableSqlWithTablesDataBinaryValuesAndViews(): void
    {
        $pdo = new DatabaseBackupTestPdo();
        $service = new DatabaseBackupService(new Database($pdo), $this->backupDir);

        $result = $service->create();

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertFileExists($result->get('filePath'));
        $this->assertTrue($pdo->committed);
        $this->assertFalse($pdo->rolledBack);

        $contents = file_get_contents($result->get('filePath'));
        if (str_ends_with((string) $result->get('fileName'), '.gz')) {
            $contents = gzdecode((string) $contents);
        }

        $this->assertIsString($contents);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `members`;', $contents);
        $this->assertStringContainsString('CREATE TABLE `members`', $contents);
        $this->assertStringContainsString('INSERT INTO `members` (`id`, `name`, `payload`) VALUES', $contents);
        $this->assertStringContainsString("('1', '홍길동', 0x0001)", $contents);
        $this->assertStringNotContainsString('INSERT INTO `members` (`id`, `name`, `payload`, `generated_name`)', $contents);
        $this->assertStringContainsString('DROP VIEW IF EXISTS `active_members`;', $contents);
        $this->assertStringContainsString('CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `active_members`', $contents);
        $this->assertStringNotContainsString('DEFINER=`root`@`localhost`', $contents);
    }
}

class DatabaseBackupTestPdo extends PDO
{
    public bool $transaction = false;
    public bool $committed = false;
    public bool $rolledBack = false;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $rows = match (true) {
            $query === 'SHOW FULL TABLES' => [
                ['members', 'BASE TABLE'],
                ['active_members', 'VIEW'],
            ],
            $query === 'SHOW CREATE TABLE `members`' => [[
                'Table' => 'members',
                'Create Table' => 'CREATE TABLE `members` (`id` int NOT NULL, `name` varchar(50), `payload` blob, `generated_name` varchar(50) GENERATED ALWAYS AS (`name`) STORED)',
            ]],
            $query === 'SHOW FULL COLUMNS FROM `members`' => [
                ['Field' => 'id', 'Type' => 'int', 'Extra' => ''],
                ['Field' => 'name', 'Type' => 'varchar(50)', 'Extra' => ''],
                ['Field' => 'payload', 'Type' => 'blob', 'Extra' => ''],
                ['Field' => 'generated_name', 'Type' => 'varchar(50)', 'Extra' => 'STORED GENERATED'],
            ],
            $query === 'SELECT `id`, `name`, `payload` FROM `members`' => [
                ['id' => '1', 'name' => '홍길동', 'payload' => "\x00\x01"],
            ],
            $query === 'SHOW CREATE VIEW `active_members`' => [[
                'View' => 'active_members',
                'Create View' => 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_members` AS select `members`.`id` AS `id` from `members`',
            ]],
            default => throw new \RuntimeException('Unexpected query: ' . $query),
        };

        return new DatabaseBackupTestStatement($rows);
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        $this->committed = true;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        $this->rolledBack = true;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }
}

class DatabaseBackupTestStatement extends PDOStatement
{
    private int $cursor = 0;

    /** @param array<int, array<int|string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
