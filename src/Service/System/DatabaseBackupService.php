<?php

namespace Mublo\Service\System;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Database\Database;
use PDO;
use PDOStatement;

/**
 * MySQL 데이터베이스를 웹 접근 불가 임시 디렉토리에 SQL로 백업한다.
 *
 * 외부 mysqldump 실행 권한이 없는 공유 호스팅에서도 동작하도록 PDO만 사용한다.
 * 생성된 파일의 다운로드와 삭제는 Controller/FileResponse가 담당한다.
 */
class DatabaseBackupService
{
    private const INSERT_BATCH_SIZE = 100;
    private const STALE_FILE_SECONDS = 3600;

    private Database $db;
    private string $backupDir;

    public function __construct(Database $db, ?string $backupDir = null)
    {
        $this->db = $db;
        $this->backupDir = $backupDir
            ?? rtrim(MUBLO_STORAGE_PATH, '/\\') . '/temp/database-backups';
    }

    /**
     * 전체 데이터베이스 백업 파일을 생성한다.
     */
    public function create(): Result
    {
        if (!$this->ensureBackupDirectory()) {
            return Result::failure('백업 임시 디렉토리를 준비할 수 없습니다.');
        }

        $this->cleanupStaleFiles();

        $lockPath = $this->backupDir . '/backup.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return Result::failure('다른 데이터베이스 백업이 진행 중입니다. 잠시 후 다시 시도해주세요.');
        }

        $useGzip = function_exists('gzopen') && function_exists('gzwrite');
        $suffix = $useGzip ? '.sql.gz' : '.sql';
        $fileName = 'mublo-db-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . $suffix;
        $finalPath = $this->backupDir . '/' . $fileName;
        $partialPath = $finalPath . '.part';
        $handle = null;

        try {
            $handle = $useGzip ? gzopen($partialPath, 'wb6') : fopen($partialPath, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('백업 파일을 생성할 수 없습니다.');
            }

            $this->dump($this->db->getPdo(), $handle, $useGzip);

            if ($useGzip) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            $handle = null;

            if (!@rename($partialPath, $finalPath)) {
                throw new \RuntimeException('백업 파일을 완료 상태로 전환할 수 없습니다.');
            }

            @chmod($finalPath, 0600);
            clearstatcache(true, $finalPath);

            return Result::success('데이터베이스 백업이 완료되었습니다.', [
                'filePath' => $finalPath,
                'fileName' => $fileName,
                'mimeType' => $useGzip ? 'application/gzip' : 'application/sql',
                'size' => (int) (filesize($finalPath) ?: 0),
            ]);
        } catch (\Throwable $e) {
            error_log('[DatabaseBackup] ' . $e->getMessage());

            if (is_resource($handle)) {
                $useGzip ? gzclose($handle) : fclose($handle);
            }
            @unlink($partialPath);
            @unlink($finalPath);

            return Result::failure('데이터베이스 백업 중 오류가 발생했습니다.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param resource $handle
     */
    private function dump(PDO $pdo, mixed $handle, bool $gzip): void
    {
        $objects = $this->listObjects($pdo);
        $tables = array_keys(array_filter($objects, static fn(string $type): bool => $type === 'BASE TABLE'));
        $views = array_keys(array_filter($objects, static fn(string $type): bool => $type === 'VIEW'));
        $startedTransaction = false;

        $write = function (string $sql) use ($handle, $gzip): void {
            $written = $gzip ? gzwrite($handle, $sql) : fwrite($handle, $sql);
            if ($written === false) {
                throw new \RuntimeException('백업 파일 쓰기에 실패했습니다.');
            }
        };

        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            $write("-- Mublo database backup\n");
            $write('-- Created at: ' . date(DATE_ATOM) . "\n");
            $write("SET NAMES utf8mb4;\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\n");
            $write("SET UNIQUE_CHECKS=0;\n\n");

            foreach (array_reverse($views) as $view) {
                $write('DROP VIEW IF EXISTS ' . $this->quoteIdentifier($view) . ";\n");
            }
            if ($views !== []) {
                $write("\n");
            }

            foreach ($tables as $table) {
                $this->dumpTable($pdo, $table, $write);
            }

            foreach ($views as $view) {
                $this->dumpView($pdo, $view, $write);
            }

            $write("SET UNIQUE_CHECKS=1;\n");
            $write("SET FOREIGN_KEY_CHECKS=1;\n");

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function listObjects(PDO $pdo): array
    {
        $statement = $pdo->query('SHOW FULL TABLES');
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('데이터베이스 객체 목록을 조회할 수 없습니다.');
        }

        $objects = [];
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $name = (string) ($row[0] ?? '');
            $type = strtoupper((string) ($row[1] ?? 'BASE TABLE'));
            if ($name !== '') {
                $objects[$name] = $type;
            }
        }
        $statement->closeCursor();

        return $objects;
    }

    private function dumpTable(PDO $pdo, string $table, callable $write): void
    {
        $quotedTable = $this->quoteIdentifier($table);
        $createStatement = $pdo->query('SHOW CREATE TABLE ' . $quotedTable);
        $createRow = $createStatement?->fetch(PDO::FETCH_ASSOC);
        $createStatement?->closeCursor();
        $createSql = is_array($createRow) ? (string) ($createRow['Create Table'] ?? array_values($createRow)[1] ?? '') : '';
        if ($createSql === '') {
            throw new \RuntimeException("테이블 생성문을 조회할 수 없습니다: {$table}");
        }

        $write("-- Table: {$table}\n");
        $write('DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
        $write($createSql . ";\n\n");

        $columnsStatement = $pdo->query('SHOW FULL COLUMNS FROM ' . $quotedTable);
        $columnRows = $columnsStatement?->fetchAll(PDO::FETCH_ASSOC);
        $columnsStatement?->closeCursor();
        if (!is_array($columnRows)) {
            throw new \RuntimeException("테이블 컬럼을 조회할 수 없습니다: {$table}");
        }

        $columns = [];
        $binaryColumns = [];
        foreach ($columnRows as $column) {
            $name = (string) ($column['Field'] ?? '');
            $extra = strtoupper((string) ($column['Extra'] ?? ''));
            if ($name === '' || str_contains($extra, 'GENERATED')) {
                continue;
            }
            $columns[] = $name;
            if (preg_match('/(?:blob|binary|varbinary|bit)/i', (string) ($column['Type'] ?? '')) === 1) {
                $binaryColumns[$name] = true;
            }
        }

        if ($columns === []) {
            return;
        }

        $quotedColumns = array_map($this->quoteIdentifier(...), $columns);
        $dataStatement = $pdo->query(
            'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $quotedTable
        );
        if (!$dataStatement instanceof PDOStatement) {
            throw new \RuntimeException("테이블 데이터를 조회할 수 없습니다: {$table}");
        }

        $rows = [];
        while (($row = $dataStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->quoteValue($pdo, $row[$column] ?? null, isset($binaryColumns[$column]));
            }
            $rows[] = '(' . implode(', ', $values) . ')';

            if (count($rows) >= self::INSERT_BATCH_SIZE) {
                $this->writeInsert($write, $quotedTable, $quotedColumns, $rows);
                $rows = [];
            }
        }
        $dataStatement->closeCursor();

        if ($rows !== []) {
            $this->writeInsert($write, $quotedTable, $quotedColumns, $rows);
        }
        $write("\n");
    }

    private function dumpView(PDO $pdo, string $view, callable $write): void
    {
        $statement = $pdo->query('SHOW CREATE VIEW ' . $this->quoteIdentifier($view));
        $row = $statement?->fetch(PDO::FETCH_ASSOC);
        $statement?->closeCursor();
        $sql = is_array($row) ? (string) ($row['Create View'] ?? array_values($row)[1] ?? '') : '';
        if ($sql === '') {
            throw new \RuntimeException("뷰 생성문을 조회할 수 없습니다: {$view}");
        }

        // 다른 서버에 없는 DB 계정 때문에 복원이 실패하지 않도록 DEFINER를 제거한다.
        $sql = preg_replace('/\sDEFINER=`[^`]+`@`[^`]+`/i', '', $sql) ?? $sql;
        $write("-- View: {$view}\n{$sql};\n\n");
    }

    /**
     * @param string[] $quotedColumns
     * @param string[] $rows
     */
    private function writeInsert(callable $write, string $quotedTable, array $quotedColumns, array $rows): void
    {
        $write(
            'INSERT INTO ' . $quotedTable
            . ' (' . implode(', ', $quotedColumns) . ") VALUES\n"
            . implode(",\n", $rows) . ";\n"
        );
    }

    private function quoteValue(PDO $pdo, mixed $value, bool $binary): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $value = (string) $value;
        if ($binary) {
            return $value === '' ? "''" : '0x' . bin2hex($value);
        }

        $quoted = $pdo->quote($value);
        return $quoted !== false ? $quoted : '0x' . bin2hex($value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function ensureBackupDirectory(): bool
    {
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0700, true) && !is_dir($this->backupDir)) {
            return false;
        }

        @chmod($this->backupDir, 0700);
        return is_writable($this->backupDir);
    }

    private function cleanupStaleFiles(): void
    {
        $threshold = time() - self::STALE_FILE_SECONDS;
        foreach (glob($this->backupDir . '/mublo-db-backup-*') ?: [] as $file) {
            if (is_file($file) && (filemtime($file) ?: 0) < $threshold) {
                @unlink($file);
            }
        }
    }
}
