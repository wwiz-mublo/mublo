<?php
/**
 * Database Migration Script
 *
 * Usage: php database/migrate.php
 *
 * 전제:
 * - .env 와 config/database.php 가 준비되어 있어야 함
 * - 이미 설치된 환경에서 누락된 core/package 마이그레이션을 반영할 때 사용
 */

require_once __DIR__ . '/../bootstrap.php';

use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\DatabaseManager;

function getDefaultPackages(string $packageRoot): array
{
    if (!is_dir($packageRoot)) {
        return [];
    }

    $defaults = [];
    foreach (glob($packageRoot . '/*/manifest.json') ?: [] as $manifestFile) {
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            continue;
        }

        if (!empty($manifest['default']) && !empty($manifest['name'])) {
            $defaults[] = (string) $manifest['name'];
        }
    }

    sort($defaults);
    return $defaults;
}

try {
    echo "===========================================\n";
    echo "Database Migration\n";
    echo "===========================================\n\n";

    $dbManager = DatabaseManager::getInstance()->loadFromEnv();
    $db = $dbManager->connect();
    $runner = new MigrationRunner($db);

    echo "✓ Database connected successfully\n\n";

    $executedTotal = 0;

    $corePath = __DIR__ . '/migrations';
    $coreResult = $runner->run('core', '__core__', $corePath);
    if (!$coreResult['success']) {
        throw new RuntimeException('Core migration failed: ' . $coreResult['error']);
    }

    $coreExecuted = $coreResult['executed'] ?? [];
    echo "Core migrations: " . count($coreExecuted) . " executed\n";
    foreach ($coreExecuted as $file) {
        echo "  - {$file}\n";
    }
    $executedTotal += count($coreExecuted);

    $defaultPackages = getDefaultPackages(MUBLO_PACKAGE_PATH);
    foreach ($defaultPackages as $packageName) {
        $packagePath = MUBLO_PACKAGE_PATH . '/' . $packageName . '/database/migrations';
        if (!is_dir($packagePath)) {
            continue;
        }

        $result = $runner->run('package', $packageName, $packagePath);
        if (!$result['success']) {
            throw new RuntimeException("Package migration failed [{$packageName}]: " . $result['error']);
        }

        $executed = $result['executed'] ?? [];
        echo "{$packageName} package migrations: " . count($executed) . " executed\n";
        foreach ($executed as $file) {
            echo "  - {$file}\n";
        }
        $executedTotal += count($executed);
    }

    echo "\n===========================================\n";
    echo "Migration completed: {$executedTotal} file(s) executed\n";
    echo "===========================================\n";
} catch (Throwable $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
