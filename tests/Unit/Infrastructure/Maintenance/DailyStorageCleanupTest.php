<?php

namespace Tests\Unit\Infrastructure\Maintenance;

use DateTimeImmutable;
use DateTimeZone;
use Mublo\Infrastructure\Maintenance\DailyStorageCleanup;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DailyStorageCleanupTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir()
            . '/mublo-daily-cleanup-'
            . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        parent::tearDown();
    }

    public function testCleansExpiredSessionsAndAllLogLayoutsOncePerKoreanDay(): void
    {
        // UTC 18:30은 다음 날 한국 시간 03:30이다.
        $now = new DateTimeImmutable('2026-07-26 18:30:00', new DateTimeZone('UTC'));
        $old = $now->getTimestamp() - (8 * 86400);
        $recent = $now->getTimestamp() - (6 * 86400);

        $oldSession = $this->createFile('sessions/sess_old', $old);
        $recentSession = $this->createFile('sessions/sess_recent', $recent);
        $unrelatedSessionFile = $this->createFile('sessions/README.txt', $old);
        $rootLog = $this->createFile('logs/block_error.log', $old);
        $domainLog = $this->createFile('logs/D1/error/2026-07-18.log', $old);
        $resetLog = $this->createFile('logs/reset/reset_20260718.log', $old);
        $compressedLog = $this->createFile('logs/D1/query/2026-07-18.log.gz', $old);
        $recentLog = $this->createFile('logs/D1/app/2026-07-26.log', $recent);
        $unrelatedLogFile = $this->createFile('logs/D1/app/context.json', $old);

        $cleanup = new DailyStorageCleanup(
            $this->storagePath,
            static fn (): DateTimeImmutable => $now,
            7,
            7
        );

        $result = $cleanup->runIfDue();

        $this->assertSame([
            'executed' => true,
            'completed' => true,
            'sessions_deleted' => 1,
            'logs_deleted' => 4,
        ], $result);
        $this->assertFileDoesNotExist($oldSession);
        $this->assertFileDoesNotExist($rootLog);
        $this->assertFileDoesNotExist($domainLog);
        $this->assertFileDoesNotExist($resetLog);
        $this->assertFileDoesNotExist($compressedLog);
        $this->assertFileExists($recentSession);
        $this->assertFileExists($unrelatedSessionFile);
        $this->assertFileExists($recentLog);
        $this->assertFileExists($unrelatedLogFile);

        $this->assertSame([
            'executed' => false,
            'completed' => false,
            'sessions_deleted' => 0,
            'logs_deleted' => 0,
        ], $cleanup->runIfDue());
    }

    public function testDoesNotRunOutsideKoreanMaintenanceWindow(): void
    {
        $now = new DateTimeImmutable('2026-07-27 05:00:00', new DateTimeZone('Asia/Seoul'));
        $oldSession = $this->createFile('sessions/sess_old', $now->getTimestamp() - (8 * 86400));

        $cleanup = new DailyStorageCleanup(
            $this->storagePath,
            static fn (): DateTimeImmutable => $now,
            7,
            7
        );

        $this->assertFalse($cleanup->runIfDue()['executed']);
        $this->assertFileExists($oldSession);
        $this->assertDirectoryDoesNotExist($this->storagePath . '/framework/maintenance');
    }

    public function testUsesIndependentRetentionPeriods(): void
    {
        $now = new DateTimeImmutable('2026-07-27 03:30:00', new DateTimeZone('Asia/Seoul'));
        $timestamp = $now->getTimestamp() - (10 * 86400);
        $session = $this->createFile('sessions/sess_keep_for_fourteen_days', $timestamp);
        $log = $this->createFile('logs/D1/app/delete_after_three_days.log', $timestamp);

        $cleanup = new DailyStorageCleanup(
            $this->storagePath,
            static fn (): DateTimeImmutable => $now,
            14,
            3
        );
        $result = $cleanup->runIfDue();

        $this->assertSame(0, $result['sessions_deleted']);
        $this->assertSame(1, $result['logs_deleted']);
        $this->assertFileExists($session);
        $this->assertFileDoesNotExist($log);
    }

    public function testSkipsSessionFileLockedByAnotherRequest(): void
    {
        $now = new DateTimeImmutable('2026-07-27 03:30:00', new DateTimeZone('Asia/Seoul'));
        $session = $this->createFile('sessions/sess_locked', $now->getTimestamp() - (8 * 86400));
        $handle = fopen($session, 'r+');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $cleanup = new DailyStorageCleanup(
                $this->storagePath,
                static fn (): DateTimeImmutable => $now,
                7,
                7
            );
            $result = $cleanup->runIfDue();

            $this->assertSame(0, $result['sessions_deleted']);
            $this->assertFileExists($session);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function createFile(string $relativePath, int $modifiedAt): string
    {
        $path = $this->storagePath . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, 'test');
        touch($path, $modifiedAt);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($path);
    }
}
