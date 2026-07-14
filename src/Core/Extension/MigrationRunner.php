<?php
namespace Mublo\Core\Extension;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\MigrationErrorPolicy;
use Mublo\Infrastructure\Database\SqlStatementSplitter;

/**
 * MigrationRunner
 *
 * Core / Plugin / Package DB 마이그레이션 통합 실행 및 추적.
 *
 * 추적 테이블: schema_migrations
 *   - source : 'core' | 'plugin' | 'package'
 *   - name   : 식별자 (__core__, Banner, Shop 등)
 *   - file   : SQL 파일명
 *   - checksum : 실행 당시 파일 raw bytes의 SHA-256
 *
 * 사용 예:
 * ```php
 * // Core
 * $runner->run('core', '__core__', '/path/to/migrations');
 *
 * // Plugin
 * $runner->run('plugin', 'Banner', '/path/to/plugin/migrations');
 *
 * // Package
 * $runner->run('package', 'Shop', '/path/to/package/migrations');
 * ```
 */
class MigrationRunner
{
    private Database $db;
    private SqlStatementSplitter $splitter;
    private string $trackingTable = 'schema_migrations';
    private bool $trackingTableReady = false;

    public function __construct(Database $db, ?SqlStatementSplitter $splitter = null)
    {
        $this->db = $db;
        $this->splitter = $splitter ?? new SqlStatementSplitter();
    }

    /**
     * 마이그레이션 상태 조회
     *
     * @return array{pending: string[], executed: string[], drift: array<int, array{file: string, expected: string, actual: string}>, baselined: string[]}
     */
    public function getStatus(string $source, string $name, string $migrationPath): array
    {
        $allFiles = $this->getMigrationFiles($migrationPath);

        if (empty($allFiles)) {
            return ['pending' => [], 'executed' => [], 'drift' => [], 'baselined' => []];
        }

        $this->ensureTrackingTable();

        $records = $this->getExecutedMigrationRecords($source, $name);
        $executed = array_keys($records);

        $pending = [];
        $drift = [];
        $baselined = [];
        foreach ($allFiles as $file) {
            $filename = basename($file);
            if (!in_array($filename, $executed, true)) {
                $pending[] = $filename;
                continue;
            }

            $actual = hash_file('sha256', $file);
            if ($actual === false) {
                throw new \RuntimeException("Migration checksum could not be calculated: {$filename}");
            }

            $expected = $records[$filename];
            if ($expected === null || $expected === '') {
                $this->baselineChecksum($source, $name, $filename, $actual);
                $baselined[] = $filename;
                continue;
            }

            if (!hash_equals($expected, $actual)) {
                $drift[] = [
                    'file' => $filename,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        return [
            'pending'  => $pending,
            'executed' => $executed,
            'drift' => $drift,
            'baselined' => $baselined,
        ];
    }

    /**
     * 미실행 마이그레이션 실행
     *
     * @return array ['success' => bool, 'executed' => [...], 'error' => string|null]
     */
    public function run(string $source, string $name, string $migrationPath): array
    {
        try {
            $status = $this->getStatus($source, $name, $migrationPath);
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'executed' => [],
                'error' => '[tracking] ' . $e->getMessage(),
            ];
        }

        if (!empty($status['drift'])) {
            $first = $status['drift'][0];
            return [
                'success' => false,
                'executed' => [],
                'error' => sprintf(
                    '[%s] Migration checksum mismatch (recorded=%s, current=%s)',
                    $first['file'],
                    $first['expected'],
                    $first['actual']
                ),
            ];
        }

        if (empty($status['pending'])) {
            return ['success' => true, 'executed' => [], 'error' => null];
        }

        $executed = [];
        $pdo = $this->db->getPdo();

        foreach ($status['pending'] as $filename) {
            $filePath = rtrim($migrationPath, '/\\') . '/' . $filename;

            if (!is_file($filePath)) {
                continue;
            }

            try {
                $sql = file_get_contents($filePath);
                if ($sql === false) {
                    return [
                        'success' => false,
                        'executed' => $executed,
                        'error' => "[{$filename}] Migration file could not be read",
                    ];
                }
                $checksum = hash('sha256', $sql);

                // @optional-table 주석 파싱: 해당 테이블 부재 시 관련 쿼리 에러를 무시
                $optionalTables = $this->parseOptionalTables($sql);

                // 문자열 리터럴·주석 안의 ; 를 올바르게 보존하면서 문(statement) 분할
                $queries = $this->splitter->split($sql);

                foreach ($queries as $query) {
                    try {
                        // query()로 실행하여 결과셋을 PDOStatement로 받고 즉시 해제
                        // exec()는 SET @var=(SELECT ...) 등의 내부 결과셋을 소비하지 못함
                        $stmt = $pdo->query($query);
                        if ($stmt) {
                            $stmt->closeCursor();
                        }
                    } catch (\PDOException $e) {
                        // "Table doesn't exist" (1146) 에러이고 optional-table에 해당하면 스킵
                        if ($e->getCode() === '42S02' && !empty($optionalTables) && $this->isOptionalTableError($e->getMessage(), $optionalTables)) {
                            continue;
                        }
                        // 허용된 DDL 문맥에서만 중복 컬럼/키 등 멱등성 오류를 무시한다.
                        if (MigrationErrorPolicy::canIgnorePdo($e, $query)) {
                            continue;
                        }
                        throw $e;
                    }
                }

                $this->recordMigration($source, $name, $filename, $checksum);
                $executed[] = $filename;
            } catch (\PDOException $e) {
                return [
                    'success'  => false,
                    'executed' => $executed,
                    'error'    => "[{$filename}] " . $e->getMessage(),
                ];
            }
        }

        return ['success' => true, 'executed' => $executed, 'error' => null];
    }

    /**
     * 마이그레이션을 실행됨으로 마킹 (실제 SQL 실행 없이)
     *
     * Installer가 설치 완료 시 이미 실행한 파일들을 이력에 등록할 때 사용.
     */
    public function markExecuted(
        string $source,
        string $name,
        string $filename,
        ?string $checksum = null
    ): void
    {
        $this->ensureTrackingTable();
        $this->recordMigration($source, $name, $filename, $checksum);
    }

    /**
     * 추적 테이블 생성 (없을 때만) + 레거시 이전
     */
    private function ensureTrackingTable(): void
    {
        if ($this->trackingTableReady) {
            return;
        }

        $pdo = $this->db->getPdo();

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->trackingTable}` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source`      ENUM('core', 'plugin', 'package') NOT NULL DEFAULT 'plugin',
            `name`        VARCHAR(100) NOT NULL,
            `file`        VARCHAR(200) NOT NULL,
            `checksum`    CHAR(64) NULL,
            `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_migration` (`source`, `name`, `file`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $column = $pdo->query("SHOW COLUMNS FROM `{$this->trackingTable}` LIKE 'checksum'");
        $hasChecksum = $column !== false && $column->fetchColumn() !== false;
        if ($column !== false) {
            $column->closeCursor();
        }

        if (!$hasChecksum) {
            try {
                $pdo->exec(
                    "ALTER TABLE `{$this->trackingTable}` ADD COLUMN `checksum` CHAR(64) NULL AFTER `file`"
                );
            } catch (\PDOException $e) {
                // 동시에 들어온 다른 요청이 먼저 컬럼을 추가한 경우만 허용한다.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }

        $this->trackingTableReady = true;
    }

    /**
     * SQL 파일 목록 (이름순 정렬)
     */
    private function getMigrationFiles(string $migrationPath): array
    {
        if (!is_dir($migrationPath)) {
            return [];
        }

        $files = glob(rtrim($migrationPath, '/\\') . '/*.sql');

        if ($files === false) {
            return [];
        }

        sort($files);
        return $files;
    }

    /**
     * 이미 실행된 파일명 목록
     */
    private function getExecutedMigrations(string $source, string $name): array
    {
        return array_keys($this->getExecutedMigrationRecords($source, $name));
    }

    /**
     * @return array<string, string|null> 파일명 => 실행 당시 checksum
     */
    private function getExecutedMigrationRecords(string $source, string $name): array
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT `file`, `checksum` FROM `{$this->trackingTable}` WHERE `source` = ? AND `name` = ? ORDER BY `id` ASC"
        );
        $stmt->execute([$source, $name]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $records = [];
        foreach ($rows as $row) {
            // 테스트/레거시 adapter가 단일 file 값만 반환하는 경우도 NULL checksum으로 수용한다.
            if (is_string($row)) {
                $records[$row] = null;
                continue;
            }
            if (is_array($row) && isset($row['file'])) {
                $records[(string) $row['file']] = isset($row['checksum'])
                    ? (string) $row['checksum']
                    : null;
            }
        }
        return $records;
    }

    private function baselineChecksum(
        string $source,
        string $name,
        string $filename,
        string $checksum
    ): void {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE `{$this->trackingTable}` SET `checksum` = ? "
            . "WHERE `source` = ? AND `name` = ? AND `file` = ? AND `checksum` IS NULL"
        );
        $stmt->execute([$checksum, $source, $name, $filename]);
        error_log("Migration checksum baseline registered [{$source}:{$name}:{$filename}]");
    }

    /**
     * SQL 내 @optional-table 주석에서 테이블명 파싱
     *
     * 형식: -- @optional-table: table1, table2
     * 해당 테이블이 존재하지 않아서 발생하는 에러를 무시함
     *
     * @return string[] 선택적 테이블명 배열
     */
    private function parseOptionalTables(string $sql): array
    {
        if (preg_match('/--\s*@optional-table:\s*(.+)/i', $sql, $matches)) {
            return array_map('trim', explode(',', $matches[1]));
        }
        return [];
    }

    /**
     * PDOException 메시지가 optional 테이블 부재로 인한 것인지 확인
     */
    private function isOptionalTableError(string $message, array $optionalTables): bool
    {
        foreach ($optionalTables as $table) {
            if (stripos($message, $table) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 실행 이력 기록
     */
    private function recordMigration(
        string $source,
        string $name,
        string $filename,
        ?string $checksum = null
    ): void
    {
        $stmt = $this->db->getPdo()->prepare(
            "INSERT IGNORE INTO `{$this->trackingTable}` (source, name, file, checksum) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$source, $name, $filename, $checksum]);
    }
}
