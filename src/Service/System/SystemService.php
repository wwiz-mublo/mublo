<?php

namespace Mublo\Service\System;

use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Result\Result;
use Mublo\Core\App\Router;
use Mublo\Infrastructure\Cache\CacheFactory;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Storage\SecureFileService;

/**
 * SystemService
 *
 * 시스템 관리 서비스 (캐시 초기화, 마이그레이션 상태 조회/실행)
 */
class SystemService
{
    private MigrationRunner $migrationRunner;
    private Database $db;
    private DomainCache $domainCache;
    private SecureFileService $secureFileService;

    /** @var array<string, ?string> type:name → manifest icon 캐시 */
    private array $manifestIconCache = [];

    public function __construct(Database $db, DomainCache $domainCache, SecureFileService $secureFileService)
    {
        $this->db = $db;
        $this->domainCache = $domainCache;
        $this->secureFileService = $secureFileService;
        $this->migrationRunner = new MigrationRunner($db);
    }

    // =========================================================================
    // 캐시 관리
    // =========================================================================

    /**
     * 캐시 초기화
     *
     * domainId 지정 시: 해당 도메인 캐시만 초기화
     * domainId null: 전체 캐시 초기화 (모든 도메인 + named 캐시)
     *
     * @param int|null $domainId 도메인 ID (null = 전체)
     * @param string|null $domainName 도메인명 (DomainCache 키 삭제용)
     * @return Result
     */
    public function clearAllCache(?int $domainId = null, ?string $domainName = null): Result
    {
        $totalCleared = 0;

        // 1. 도메인 캐시 flush
        $cache = CacheFactory::create($domainId);
        $totalCleared += $cache->flush();

        if ($domainId !== null) {
            // 도메인 지정: 해당 도메인의 DomainCache 키만 삭제
            if ($domainName !== null) {
                $this->domainCache->delete($domainName);
                $totalCleared++;
            }
        } else {
            // 전체: named 캐시도 전부 flush
            $namedCaches = ['domains', 'menus', 'blocks'];
            foreach ($namedCaches as $name) {
                $namedCache = CacheFactory::createNamed($name);
                $totalCleared += $namedCache->flush();
            }
        }

        // 라우터 캐시 클리어
        if ($domainName !== null) {
            Router::clearRouteCache($domainName);
            $totalCleared++;
        } else {
            $totalCleared += Router::clearAllRouteCache();
        }

        // 싱글톤 인스턴스 리셋
        CacheFactory::clearInstances();

        return Result::success("캐시를 초기화했습니다. ({$totalCleared}개 항목 삭제)");
    }

    /**
     * 캐시 상태 정보
     */
    public function getCacheInfo(): array
    {
        $driver = CacheFactory::getCurrentDriver();
        $cachePath = MUBLO_ROOT_PATH . '/storage/cache/data';

        $info = [
            'driver' => $driver,
            'path' => $driver === 'file' ? '/storage/cache/data' : 'Redis',
        ];

        if ($driver === 'file' && is_dir($cachePath)) {
            $info['size'] = $this->getDirectorySize($cachePath);
            $info['size_human'] = $this->formatBytes($info['size']);
        }

        return $info;
    }

    // =========================================================================
    // 마이그레이션 관리
    // =========================================================================

    /**
     * 전체 마이그레이션 상태 조회 (Core + 활성화된 Plugin/Package만)
     *
     * @param array $enabledPlugins 활성화된 플러그인 이름 배열
     * @param array $enabledPackages 활성화된 패키지 이름 배열
     * @return array [['source'=>'core', 'name'=>'__core__', 'pending'=>[...], 'executed'=>[...]], ...]
     */
    public function getAllMigrationStatus(array $enabledPlugins = [], array $enabledPackages = []): array
    {
        $statuses = [];

        // Core
        $corePath = MUBLO_ROOT_PATH . '/database/migrations';
        if (is_dir($corePath)) {
            $status = $this->migrationRunner->getStatus('core', '__core__', $corePath);
            $statuses[] = [
                'source' => 'core',
                'name' => 'Core',
                'icon' => 'bi-gear',
                'path' => $corePath,
                'pending' => $status['pending'],
                'executed' => $status['executed'],
            ];
        }

        // Plugins (활성화된 것만)
        if (defined('MUBLO_PLUGIN_PATH') && is_dir(MUBLO_PLUGIN_PATH)) {
            foreach ($enabledPlugins as $name) {
                $dir = MUBLO_PLUGIN_PATH . '/' . $name . '/database/migrations';
                if (!is_dir($dir)) {
                    continue;
                }
                $status = $this->migrationRunner->getStatus('plugin', $name, $dir);
                $statuses[] = [
                    'source' => 'plugin',
                    'name' => $name,
                    'icon' => $this->manifestIcon('plugin', $name) ?? 'bi-puzzle',
                    'path' => $dir,
                    'pending' => $status['pending'],
                    'executed' => $status['executed'],
                ];
            }
        }

        // Packages (활성화된 것만)
        if (defined('MUBLO_PACKAGE_PATH') && is_dir(MUBLO_PACKAGE_PATH)) {
            foreach ($enabledPackages as $name) {
                $dir = MUBLO_PACKAGE_PATH . '/' . $name . '/database/migrations';
                if (!is_dir($dir)) {
                    continue;
                }
                $status = $this->migrationRunner->getStatus('package', $name, $dir);
                $statuses[] = [
                    'source' => 'package',
                    'name' => $name,
                    'icon' => $this->manifestIcon('package', $name) ?? 'bi-box',
                    'path' => $dir,
                    'pending' => $status['pending'],
                    'executed' => $status['executed'],
                ];
            }
        }

        return $statuses;
    }

    /**
     * 확장 manifest.json 의 icon 반환(없으면 null). type:name 캐시.
     */
    private function manifestIcon(string $type, string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $cacheKey = $type . ':' . $name;
        if (array_key_exists($cacheKey, $this->manifestIconCache)) {
            return $this->manifestIconCache[$cacheKey];
        }

        $dir  = $type === 'plugin' ? 'plugins' : 'packages';
        $path = MUBLO_ROOT_PATH . '/' . $dir . '/' . $name . '/manifest.json';

        $icon = null;
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && !empty($data['icon'])) {
                $icon = (string) $data['icon'];
            }
        }

        return $this->manifestIconCache[$cacheKey] = $icon;
    }

    /**
     * 미실행 마이그레이션 존재 여부
     */
    public function hasPendingMigrations(array $enabledPlugins = [], array $enabledPackages = []): bool
    {
        foreach ($this->getAllMigrationStatus($enabledPlugins, $enabledPackages) as $status) {
            if (!empty($status['pending'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 미실행 마이그레이션 전체 실행
     *
     * @return Result
     */
    public function runPendingMigrations(array $enabledPlugins = [], array $enabledPackages = []): Result
    {
        $allStatus = $this->getAllMigrationStatus($enabledPlugins, $enabledPackages);
        $totalExecuted = [];
        $errors = [];

        foreach ($allStatus as $item) {
            if (empty($item['pending'])) {
                continue;
            }

            $result = $this->migrationRunner->run($item['source'], $item['name'] === 'Core' ? '__core__' : $item['name'], $item['path']);

            if (!empty($result['executed'])) {
                foreach ($result['executed'] as $file) {
                    $totalExecuted[] = "[{$item['name']}] {$file}";
                }
            }

            if (!$result['success']) {
                $errors[] = "[{$item['name']}] " . ($result['error'] ?? '알 수 없는 오류');
                break;
            }
        }

        if (!empty($errors)) {
            return Result::failure(
                '마이그레이션 실행 중 오류가 발생했습니다: ' . implode(', ', $errors),
                ['executed' => $totalExecuted, 'errors' => $errors]
            );
        }

        if (empty($totalExecuted)) {
            return Result::success('실행할 마이그레이션이 없습니다.');
        }

        return Result::success(
            count($totalExecuted) . '개 마이그레이션을 실행했습니다.',
            ['executed' => $totalExecuted]
        );
    }

    // =========================================================================
    // 임시파일 관리
    // =========================================================================

    /**
     * 임시파일 현황 조회
     *
     * @return array{editor: array, secure: array, total: array}
     */
    public function getTempFileInfo(): array
    {
        $editorInfo = $this->getEditorTempInfo();
        $secureInfo = $this->getSecureTempInfo();

        return [
            'editor' => $editorInfo,
            'secure' => $secureInfo,
            'total' => [
                'count' => $editorInfo['count'] + $secureInfo['count'],
                'size' => $editorInfo['size'] + $secureInfo['size'],
                'size_human' => $this->formatBytes($editorInfo['size'] + $secureInfo['size']),
            ],
        ];
    }

    /**
     * 임시파일 정리 실행
     *
     * @param int $maxAgeHours 보관 시간 (시간 단위)
     * @return Result
     */
    public function cleanupTempFiles(int $maxAgeHours = 24): Result
    {
        $maxAgeSeconds = $maxAgeHours * 3600;

        // 1. 에디터 임시파일 정리
        $editorDeleted = $this->cleanupEditorTemp($maxAgeSeconds);

        // 2. 보안 임시파일 정리 (SecureFileService 활용)
        $secureDeleted = $this->secureFileService->cleanupTemp(null, $maxAgeSeconds);

        $totalDeleted = $editorDeleted + $secureDeleted;

        if ($totalDeleted === 0) {
            return Result::success('정리할 임시파일이 없습니다.', [
                'editor_deleted' => 0,
                'secure_deleted' => 0,
                'total_deleted' => 0,
            ]);
        }

        return Result::success(
            "임시파일 {$totalDeleted}개를 삭제했습니다. (에디터: {$editorDeleted}개, 보안: {$secureDeleted}개)",
            [
                'editor_deleted' => $editorDeleted,
                'secure_deleted' => $secureDeleted,
                'total_deleted' => $totalDeleted,
            ]
        );
    }

    /**
     * 에디터 임시파일 현황 (public/storage/D{n}/editor/temp/ + public/storage/editor/temp/)
     */
    private function getEditorTempInfo(): array
    {
        $count = 0;
        $size = 0;

        foreach ($this->getEditorTempDirs() as $dir) {
            [$c, $s] = $this->countDirFiles($dir);
            $count += $c;
            $size += $s;
        }

        return [
            'count' => $count,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
        ];
    }

    /**
     * 보안 임시파일 현황 (storage/files/temp/)
     */
    private function getSecureTempInfo(): array
    {
        $tempDir = MUBLO_STORAGE_PATH . '/files/temp';
        $count = 0;
        $size = 0;

        if (is_dir($tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                    $size += $file->getSize();
                }
            }
        }

        return [
            'count' => $count,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
        ];
    }

    /**
     * 에디터 임시 디렉토리 목록 수집
     *
     * @return string[]
     */
    private function getEditorTempDirs(): array
    {
        $dirs = [];
        $publicStorage = MUBLO_PUBLIC_STORAGE_PATH;

        // 레거시: public/storage/editor/temp/
        $legacyTemp = $publicStorage . '/editor/temp';
        if (is_dir($legacyTemp)) {
            $dirs[] = $legacyTemp;
        }

        // 도메인별: public/storage/D*/editor/temp/
        if (is_dir($publicStorage)) {
            $entries = scandir($publicStorage);
            foreach ($entries as $entry) {
                if (preg_match('/^D\d+$/', $entry)) {
                    $domainTemp = $publicStorage . '/' . $entry . '/editor/temp';
                    if (is_dir($domainTemp)) {
                        $dirs[] = $domainTemp;
                    }
                }
            }
        }

        return $dirs;
    }

    /**
     * 에디터 임시파일 정리
     */
    private function cleanupEditorTemp(int $maxAgeSeconds): int
    {
        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;

        foreach ($this->getEditorTempDirs() as $dir) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $filePath = $dir . '/' . $file;
                if (is_file($filePath) && filemtime($filePath) < $cutoff) {
                    if (@unlink($filePath)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * 디렉토리 내 파일 수와 총 크기 반환
     *
     * @return array{0: int, 1: int} [count, size]
     */
    private function countDirFiles(string $dir): array
    {
        $count = 0;
        $size = 0;

        if (!is_dir($dir)) {
            return [0, 0];
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $dir . '/' . $file;
            if (is_file($filePath)) {
                $count++;
                $size += filesize($filePath);
            }
        }

        return [$count, $size];
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function getDirectorySize(string $dir): int
    {
        $size = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
