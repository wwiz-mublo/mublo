<?php
declare(strict_types=1);

namespace Mublo\Service\Migration;

use Mublo\Infrastructure\Database\Database;
use Mublo\Core\Extension\MigrationRunner;

/**
 * CoreMigrationService
 *
 * Core DB 마이그레이션 파일 추적 및 자동 실행.
 * MigrationRunner를 사용하여 schema_migrations 테이블에
 * source='core', name='__core__' 로 이력 관리.
 *
 * 실행 시점: 관리자 기본설정 진입 시 (SettingsController.index)
 */
class CoreMigrationService
{
    private const SOURCE = 'core';
    private const NAME   = '__core__';

    private MigrationRunner $runner;
    private string $migrationsPath;

    public function __construct(Database $db)
    {
        $this->runner = new MigrationRunner($db);
        $this->migrationsPath = MUBLO_ROOT_PATH . '/database/migrations';
    }

    public function runPending(): array
    {
        return $this->runner->run(self::SOURCE, self::NAME, $this->migrationsPath);
    }

    public function hasPending(): bool
    {
        $status = $this->runner->getStatus(self::SOURCE, self::NAME, $this->migrationsPath);
        return !empty($status['pending']) || !empty($status['drift']);
    }

    public function getStatus(): array
    {
        return $this->runner->getStatus(self::SOURCE, self::NAME, $this->migrationsPath);
    }

    public function markAsExecuted(array $filenames): void
    {
        foreach ($filenames as $filename) {
            $path = $this->migrationsPath . '/' . $filename;
            $checksum = is_file($path) ? hash_file('sha256', $path) : null;
            $this->runner->markExecuted(
                self::SOURCE,
                self::NAME,
                $filename,
                $checksum === false ? null : $checksum
            );
        }
    }
}
