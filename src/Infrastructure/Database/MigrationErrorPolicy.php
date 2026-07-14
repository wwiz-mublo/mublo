<?php
namespace Mublo\Infrastructure\Database;

/**
 * 마이그레이션에서 무시할 수 있는 멱등성 오류를 SQL 문맥과 함께 판정한다.
 *
 * 오류번호만으로 판정하면 UPDATE/SELECT의 컬럼 오타까지 성공으로 처리할 수 있다.
 * 따라서 허용된 DDL 작업과 그 작업에서 예상 가능한 오류의 조합만 인정한다.
 */
final class MigrationErrorPolicy
{
    public static function canIgnorePdo(\PDOException $exception, string $sql): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = isset($exception->errorInfo[1])
            ? (int) $exception->errorInfo[1]
            : self::extractDriverCode($exception->getMessage());

        if ($driverCode > 0) {
            return self::canIgnoreMysqlError($driverCode, $sql);
        }

        return match ($sqlState) {
            '42S21' => self::isAlterAddColumn($sql),
            '42S22' => self::isAlterDropColumn($sql),
            default => false,
        };
    }

    public static function canIgnoreMysqli(int $errorCode, string $sql): bool
    {
        return self::canIgnoreMysqlError($errorCode, $sql);
    }

    private static function canIgnoreMysqlError(int $errorCode, string $sql): bool
    {
        return match ($errorCode) {
            1060 => self::isAlterAddColumn($sql),
            1054 => self::isAlterDropColumn($sql),
            1061 => self::isAddIndex($sql),
            1091 => self::isAlterDropSchemaObject($sql),
            1050 => self::isCreateTable($sql),
            // 1072(Key column doesn't exist)는 ADD INDEX의 실제 결함일 수 있어 항상 실패한다.
            default => false,
        };
    }

    private static function isAlterAddColumn(string $sql): bool
    {
        return self::hasSingleAlterOperation($sql) && self::matches(
            $sql,
            '/^ALTER\s+TABLE\b[\s\S]*\bADD\s+(?:COLUMN\s+)?(?!(?:INDEX|KEY|UNIQUE|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN|PRIMARY)\b)/i'
        );
    }

    private static function isAlterDropColumn(string $sql): bool
    {
        return self::hasSingleAlterOperation($sql)
            && self::matches($sql, '/^ALTER\s+TABLE\b[\s\S]*\bDROP\s+COLUMN\b/i');
    }

    private static function isAddIndex(string $sql): bool
    {
        return self::hasSingleAlterOperation($sql) && self::matches(
            $sql,
            '/^(?:ALTER\s+TABLE\b[\s\S]*\bADD\s+(?:(?:UNIQUE|FULLTEXT|SPATIAL)\s+)?(?:INDEX|KEY)\b|CREATE\s+(?:UNIQUE\s+)?INDEX\b)/i'
        );
    }

    private static function isAlterDropSchemaObject(string $sql): bool
    {
        return self::hasSingleAlterOperation($sql) && self::matches(
            $sql,
            '/^ALTER\s+TABLE\b[\s\S]*\bDROP\s+(?:COLUMN|INDEX|KEY|FOREIGN\s+KEY|CONSTRAINT)\b/i'
        );
    }

    private static function isCreateTable(string $sql): bool
    {
        return self::matches($sql, '/^CREATE\s+TABLE\b/i');
    }

    private static function matches(string $sql, string $pattern): bool
    {
        return preg_match($pattern, self::sqlForClassification($sql)) === 1;
    }

    /**
     * 복합 ALTER는 한 작업의 중복 오류로 전체 문장이 실패할 수 있으므로 무시하지 않는다.
     * 예: ADD COLUMN A, ADD COLUMN B에서 A가 중복이면 B도 적용되지 않는다.
     */
    private static function hasSingleAlterOperation(string $sql): bool
    {
        $classificationSql = self::sqlForClassification($sql);
        return preg_match_all('/\b(?:ADD|DROP)\b/i', $classificationSql) === 1;
    }

    private static function sqlForClassification(string $sql): string
    {
        // 주석과 문자열 리터럴의 ADD/DROP 단어가 DDL 작업 수로 집계되지 않게 제거한다.
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', ' ', $sql) ?? $sql;
        $sql = preg_replace("/'(?:''|\\\\.|[^'\\\\])*'|\"(?:\"\"|\\\\.|[^\"\\\\])*\"/s", "''", $sql) ?? $sql;
        // 문자열 안의 '--'와 '#'이 주석으로 오인되지 않도록 문자열 마스킹 뒤 처리한다.
        $sql = preg_replace('/(?:--|#)[^\r\n]*/', ' ', $sql) ?? $sql;

        return ltrim($sql);
    }

    private static function extractDriverCode(string $message): int
    {
        if (preg_match('/\b(1050|1054|1060|1061|1072|1091)\b/', $message, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
