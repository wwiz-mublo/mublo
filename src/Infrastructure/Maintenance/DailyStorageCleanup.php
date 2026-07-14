<?php

namespace Mublo\Infrastructure\Maintenance;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Mublo\Core\Env\Env;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 웹 요청을 기회로 하루 한 번 오래된 파일 세션과 로그를 정리한다.
 *
 * 별도 스케줄러가 없는 설치 환경을 위한 opportunistic maintenance다.
 * 한국 시간 03:00~05:00 사이 첫 요청만 정리를 수행하며, 잠금 파일로
 * 동시 실행을 막는다. 해당 시간대에 요청이 없었던 날은 정리를 건너뛴다.
 */
final class DailyStorageCleanup
{
    private const TIMEZONE = 'Asia/Seoul';
    private const WINDOW_START_HOUR = 3;
    private const WINDOW_END_HOUR = 5;
    private const DEFAULT_RETENTION_DAYS = 7;
    private const MAX_RETENTION_DAYS = 3650;
    private const MAX_DELETIONS_PER_TYPE = 1000;

    /** @var Closure(): DateTimeImmutable */
    private Closure $nowProvider;
    private int $sessionRetentionDays;
    private int $logRetentionDays;

    /**
     * @param callable(): DateTimeImmutable|null $nowProvider 테스트용 현재 시각 공급자
     */
    public function __construct(
        private readonly string $storagePath,
        ?callable $nowProvider = null,
        ?int $sessionRetentionDays = null,
        ?int $logRetentionDays = null
    ) {
        $this->nowProvider = $nowProvider !== null
            ? Closure::fromCallable($nowProvider)
            : static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        $this->sessionRetentionDays = $this->resolveRetentionDays(
            $sessionRetentionDays,
            'SESSION_RETENTION_DAYS'
        );
        $this->logRetentionDays = $this->resolveRetentionDays($logRetentionDays, 'LOG_RETENTION_DAYS');
    }

    /**
     * @return array{executed: bool, completed: bool, sessions_deleted: int, logs_deleted: int}
     */
    public function runIfDue(): array
    {
        $skipped = [
            'executed' => false,
            'completed' => false,
            'sessions_deleted' => 0,
            'logs_deleted' => 0,
        ];

        $now = ($this->nowProvider)()->setTimezone(new DateTimeZone(self::TIMEZONE));
        $hour = (int) $now->format('G');
        if ($hour < self::WINDOW_START_HOUR || $hour >= self::WINDOW_END_HOUR) {
            return $skipped;
        }

        $maintenancePath = rtrim($this->storagePath, '/\\') . '/framework/maintenance';
        if (!$this->ensureDirectory($maintenancePath)) {
            return $skipped;
        }

        $lock = @fopen($maintenancePath . '/daily-storage-cleanup.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return $skipped;
        }

        try {
            $today = $now->format('Y-m-d');
            rewind($lock);
            if (trim((string) stream_get_contents($lock)) === $today) {
                return $skipped;
            }

            [$sessionsDeleted, $sessionsCompleted] = $this->cleanupSessions($now);
            [$logsDeleted, $logsCompleted] = $this->cleanupLogs($now);
            $completed = $sessionsCompleted && $logsCompleted;

            // 삭제 상한에 걸렸다면 완료 표시를 하지 않는다. 같은 새벽 시간대의 다음
            // 요청이 남은 파일을 이어서 정리하므로 한 요청의 지연은 제한된다.
            if ($completed) {
                rewind($lock);
                ftruncate($lock, 0);
                fwrite($lock, $today);
                fflush($lock);
            }

            return [
                'executed' => true,
                'completed' => $completed,
                'sessions_deleted' => $sessionsDeleted,
                'logs_deleted' => $logsDeleted,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{0: int, 1: bool} */
    private function cleanupSessions(DateTimeImmutable $now): array
    {
        $sessionPath = rtrim($this->storagePath, '/\\') . '/sessions';
        if (!is_dir($sessionPath)) {
            return [0, true];
        }

        $cutoff = $now->getTimestamp() - ($this->sessionRetentionDays * 86400);
        $deleted = 0;
        $files = new FilesystemIterator($sessionPath, FilesystemIterator::SKIP_DOTS);

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !str_starts_with($file->getFilename(), 'sess_')) {
                continue;
            }
            if ($file->getMTime() >= $cutoff) {
                continue;
            }
            if ($deleted >= self::MAX_DELETIONS_PER_TYPE) {
                return [$deleted, false];
            }
            if ($this->deleteUnlockedSessionFile($file->getPathname(), $cutoff)) {
                $deleted++;
            }
        }

        return [$deleted, true];
    }

    /** @return array{0: int, 1: bool} */
    private function cleanupLogs(DateTimeImmutable $now): array
    {
        $logPath = rtrim($this->storagePath, '/\\') . '/logs';
        if (!is_dir($logPath)) {
            return [0, true];
        }

        $cutoff = $now->getTimestamp() - ($this->logRetentionDays * 86400);
        $deleted = 0;
        $directory = new RecursiveDirectoryIterator($logPath, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator(
            $directory,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!preg_match('/\.log(?:\.gz)?$/i', $file->getFilename()) || $file->getMTime() >= $cutoff) {
                continue;
            }
            if ($deleted >= self::MAX_DELETIONS_PER_TYPE) {
                return [$deleted, false];
            }
            if (@unlink($file->getPathname())) {
                $deleted++;
            }
        }

        return [$deleted, true];
    }

    private function deleteUnlockedSessionFile(string $path, int $cutoff): bool
    {
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false || $mtime >= $cutoff) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }

        // Unix에서는 잠금을 유지한 채 unlink할 수 있다. Windows는 열린 파일 삭제를
        // 허용하지 않으므로 잠금 직후 시각을 재검증한 뒤 핸들을 닫고 삭제한다.
        if (PHP_OS_FAMILY === 'Windows') {
            flock($handle, LOCK_UN);
            fclose($handle);
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            return $mtime !== false && $mtime < $cutoff && @unlink($path);
        }

        $deleted = @unlink($path);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $deleted;
    }

    private function resolveRetentionDays(?int $override, string $envKey): int
    {
        $value = $override ?? Env::get($envKey, self::DEFAULT_RETENTION_DAYS);
        if (!is_numeric($value)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $value;
        if ($days < 1) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return min($days, self::MAX_RETENTION_DAYS);
    }

    private function ensureDirectory(string $path): bool
    {
        return is_dir($path) || (@mkdir($path, 0775, true) && is_dir($path));
    }
}
