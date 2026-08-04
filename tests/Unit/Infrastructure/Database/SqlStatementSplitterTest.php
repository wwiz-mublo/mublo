<?php

namespace Tests\Unit\Infrastructure\Database;

use Mublo\Infrastructure\Database\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

class SqlStatementSplitterTest extends TestCase
{
    private SqlStatementSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new SqlStatementSplitter();
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->splitter->split(''));
        $this->assertSame([], $this->splitter->split('   '));
        $this->assertSame([], $this->splitter->split(';;;'));
    }

    public function testSingleStatementWithoutTrailingSemicolon(): void
    {
        $this->assertSame(
            ['SELECT 1'],
            $this->splitter->split('SELECT 1')
        );
    }

    public function testMultipleStatements(): void
    {
        $sql = "SELECT 1; SELECT 2; SELECT 3;";
        $this->assertSame(
            ['SELECT 1', 'SELECT 2', 'SELECT 3'],
            $this->splitter->split($sql)
        );
    }

    public function testLineCommentsAreStripped(): void
    {
        $sql = <<<SQL
            -- header comment
            SELECT 1;
            -- second comment
            SELECT 2;
        SQL;
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('SELECT 1', $result[0]);
        $this->assertStringContainsString('SELECT 2', $result[1]);
        $this->assertStringNotContainsString('header comment', $result[0]);
    }

    public function testHashLineComments(): void
    {
        $sql = "SELECT 1; # comment\nSELECT 2;";
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
    }

    public function testBlockCommentsAreStripped(): void
    {
        $sql = "/* header */ SELECT 1; /* middle */ SELECT 2;";
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringNotContainsString('header', $result[0]);
        $this->assertStringNotContainsString('middle', $result[1]);
    }

    public function testSemicolonInsideLineCommentIsNotASeparator(): void
    {
        // This was the exact bug that broke 047 migration.
        $sql = <<<SQL
            -- 보수적 기본값; Phase 1 에서 재분류
            INSERT INTO t VALUES (1);
        SQL;
        $result = $this->splitter->split($sql);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('INSERT INTO t', $result[0]);
    }

    public function testSemicolonInsideBlockCommentIsNotASeparator(): void
    {
        $sql = "/* first; second; third */ INSERT INTO t VALUES (1);";
        $result = $this->splitter->split($sql);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('INSERT INTO t', $result[0]);
    }

    public function testSemicolonInsideSingleQuotedStringIsNotASeparator(): void
    {
        $sql = "INSERT INTO t VALUES ('hello; world'); SELECT 1;";
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringContainsString("'hello; world'", $result[0]);
    }

    public function testSemicolonInsideDoubleQuotedStringIsNotASeparator(): void
    {
        $sql = 'INSERT INTO t VALUES ("a;b"); SELECT 1;';
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('"a;b"', $result[0]);
    }

    public function testSemicolonInsideBacktickIdentifierIsNotASeparator(): void
    {
        // Unusual but legal — backticked identifier with a semicolon
        $sql = 'SELECT `col;umn` FROM t; SELECT 1;';
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('`col;umn`', $result[0]);
    }

    public function testBackslashEscapeInSingleQuotedString(): void
    {
        $sql = "INSERT INTO t VALUES ('it\\'s'); SELECT 1;";
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringContainsString("'it\\'s'", $result[0]);
    }

    public function testDoubledQuoteEscapeInSingleQuotedString(): void
    {
        // SQL 표준: '' 는 문자열 안의 ' 이스케이프
        $sql = "INSERT INTO t VALUES ('it''s'); SELECT 1;";
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringContainsString("'it''s'", $result[0]);
    }

    public function testInlineCommentAfterStatement(): void
    {
        $sql = "SELECT 1; -- trailing comment\nSELECT 2;";
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
    }

    public function testCommentOnlyStatementIsDropped(): void
    {
        $sql = "-- just a comment\n/* and another */;";
        $this->assertSame([], $this->splitter->split($sql));
    }

    public function testBugFromMigration047(): void
    {
        // 실제 MigrationRunner 가 깨진 케이스 재현.
        $sql = <<<SQL
            -- Phase 0.5: 기존 domain_configs 전 도메인에 land_configs 행 주입
            --
            -- 규칙:
            --   domain_id = 1 → shop_tier='root'
            --   그 외       → shop_tier='direct' (보수적 기본값; Phase 1 에서 재분류)
            --

            INSERT INTO `land_configs` (`domain_id`, `shop_tier`) VALUES (1, 'root');
        SQL;
        $result = $this->splitter->split($sql);
        $this->assertCount(1, $result, '주석 안의 ; 로 인해 쪼개지면 안 된다');
        $this->assertStringContainsString('INSERT INTO', $result[0]);
        $this->assertStringContainsString("VALUES (1, 'root')", $result[0]);
    }

    public function testComplexMigrationWithAllFeatures(): void
    {
        $sql = <<<SQL
            -- header with ; semicolon
            /* block comment; also with ; */
            CREATE TABLE t (
                id INT,
                name VARCHAR(50) DEFAULT 'a;b',  -- default has ;
                note TEXT COMMENT 'note with ; inside'
            );

            INSERT INTO t VALUES (1, 'x\\'s', 'line1;line2');

            -- trailing comment
        SQL;
        $result = $this->splitter->split($sql);
        $this->assertCount(2, $result);
        $this->assertStringStartsWith('CREATE TABLE', $result[0]);
        $this->assertStringStartsWith('INSERT INTO', $result[1]);
        $this->assertStringContainsString("'a;b'", $result[0]);
        $this->assertStringContainsString("'line1;line2'", $result[1]);
    }

    public function testStatementsAreTrimmed(): void
    {
        $sql = "   SELECT 1   ;   SELECT 2   ;   ";
        $this->assertSame(['SELECT 1', 'SELECT 2'], $this->splitter->split($sql));
    }
}
