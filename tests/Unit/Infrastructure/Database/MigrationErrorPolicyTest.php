<?php

namespace Tests\Unit\Infrastructure\Database;

use Mublo\Infrastructure\Database\MigrationErrorPolicy;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MigrationErrorPolicyTest extends TestCase
{
    #[DataProvider('mysqliErrorProvider')]
    public function testMysqliErrorRequiresMatchingDdlContext(int $errorCode, string $sql, bool $expected): void
    {
        $this->assertSame($expected, MigrationErrorPolicy::canIgnoreMysqli($errorCode, $sql));
    }

    public static function mysqliErrorProvider(): array
    {
        return [
            'duplicate column while adding column' => [1060, 'ALTER TABLE members ADD COLUMN nickname VARCHAR(50)', true],
            'duplicate column with leading comment' => [1060, "-- compatibility\nALTER TABLE members ADD COLUMN nickname VARCHAR(50)", true],
            'duplicate in multi action alter is not ignored' => [1060, 'ALTER TABLE members ADD COLUMN nickname VARCHAR(50), ADD COLUMN phone VARCHAR(20)', false],
            'comment marker in literal cannot hide compound alter' => [1060, "ALTER TABLE members ADD COLUMN nickname VARCHAR(50) COMMENT '-- label', ADD COLUMN phone VARCHAR(20)", false],
            'duplicate column does not hide update typo' => [1060, 'UPDATE members SET nickname = NULL', false],
            'unknown column while dropping column' => [1054, 'ALTER TABLE members DROP COLUMN legacy_name', true],
            'unknown column does not hide dml typo' => [1054, 'UPDATE members SET misspelled_column = 1', false],
            'duplicate key while adding key' => [1061, 'ALTER TABLE members ADD UNIQUE KEY uk_name (name)', true],
            'duplicate key does not hide column ddl' => [1061, 'ALTER TABLE members ADD COLUMN name VARCHAR(50)', false],
            'missing drop target' => [1091, 'ALTER TABLE members DROP INDEX missing_index', true],
            'missing key column is never idempotent' => [1072, 'ALTER TABLE members ADD INDEX idx_missing (missing_column)', false],
            'existing table during create' => [1050, 'CREATE TABLE members (id INT PRIMARY KEY)', true],
            'existing table code does not hide select failure' => [1050, 'SELECT * FROM members', false],
        ];
    }

    public function testPdoUnknownColumnInDmlIsNotIgnored(): void
    {
        $exception = new PolicyPdoException('42S22', 1054, 'Unknown column misspelled_column');

        $this->assertFalse(MigrationErrorPolicy::canIgnorePdo(
            $exception,
            'UPDATE members SET misspelled_column = 1'
        ));
    }

    public function testPdoDuplicateColumnInAlterAddIsIgnored(): void
    {
        $exception = new PolicyPdoException('42S21', 1060, 'Duplicate column nickname');

        $this->assertTrue(MigrationErrorPolicy::canIgnorePdo(
            $exception,
            'ALTER TABLE members ADD COLUMN nickname VARCHAR(50)'
        ));
    }
}

class PolicyPdoException extends PDOException
{
    public function __construct(string $sqlState, int $driverCode, string $message)
    {
        parent::__construct($message);
        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, $driverCode, $message];
    }
}
