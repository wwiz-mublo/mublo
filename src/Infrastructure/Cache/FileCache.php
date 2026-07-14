<?php

namespace Mublo\Infrastructure\Cache;

/**
 * FileCache
 *
 * 파일 기반 캐시 클래스
 * - CacheInterface 구현
 * - TTL 기반 자동 만료
 * - 멀티테넌트 지원 (도메인별 분리)
 *
 * 성능 최적화:
 * - PHP return 형식 → OpCache가 메모리에 캐싱 (serialize 대비 5~10배 빠름)
 * - 해시 서브디렉토리 분산 → inode 병목 방지 (ab/cd/hash.php)
 * - 원자적 쓰기 (temp + rename) → 읽기 시 race condition 방지
 */
class FileCache implements CacheInterface
{
    private string $cachePath;
    private int $defaultTtl;
    private ?int $domainId = null;
    private bool $hasOpcache;

    private const CACHE_EXTENSION = '.php';
    private const LEGACY_EXTENSION = '.cache';

    /**
     * @param string|null $cachePath 캐시 디렉토리 경로
     * @param int $defaultTtl 기본 TTL (초)
     * @param int|null $domainId 도메인 ID (멀티테넌트)
     */
    public function __construct(
        ?string $cachePath = null,
        int $defaultTtl = 3600,
        ?int $domainId = null
    ) {
        $this->defaultTtl = $defaultTtl;
        $this->domainId = $domainId;
        $this->cachePath = $cachePath ?? $this->getDefaultCachePath();
        $this->hasOpcache = function_exists('opcache_invalidate');

        $this->ensureDirectory($this->cachePath);
    }

    /**
     * 기본 캐시 경로
     */
    private function getDefaultCachePath(): string
    {
        $basePath = dirname(__DIR__, 3) . '/storage/cache/data';

        // 도메인별 디렉토리
        if ($this->domainId) {
            return $basePath . '/d' . $this->domainId;
        }

        return $basePath . '/global';
    }

    /**
     * 디렉토리 생성
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * 도메인 ID 설정
     */
    public function setDomainId(?int $domainId): self
    {
        $this->domainId = $domainId;
        $this->cachePath = $this->getDefaultCachePath();
        $this->ensureDirectory($this->cachePath);

        return $this;
    }

    /**
     * 현재 도메인 ID 반환
     */
    public function getDomainId(): ?int
    {
        return $this->domainId;
    }

    /**
     * 캐시 조회
     *
     * OpCache가 PHP return 파일을 메모리에 캐싱하므로
     * 두 번째 이후 접근은 디스크 I/O 없이 메모리에서 로드됩니다.
     *
     * 확률적 정리: 1/1000 확률로 만료 캐시 100개까지 자동 정리
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 확률적 정리 (1/1000 요청마다, 최대 100개)
        if (random_int(1, 1000) === 1) {
            $this->cleanup(100);
        }

        $filePath = $this->getCacheFilePath($key);

        if (!file_exists($filePath)) {
            return $default;
        }

        try {
            // OpCache가 이 include를 메모리에 캐싱
            $data = @include $filePath;

            if (!is_array($data) || !isset($data['e']) || !isset($data['v'])) {
                $this->deleteFile($filePath);
                return $default;
            }

            // TTL 만료 체크
            if ($data['e'] < time()) {
                $this->deleteFile($filePath);
                return $default;
            }

            return $data['v'];
        } catch (\Throwable $e) {
            $this->deleteFile($filePath);
            return $default;
        }
    }

    /**
     * 캐시 저장
     *
     * PHP return 형식으로 저장하여 OpCache 활용.
     * temp 파일 + rename으로 원자적 쓰기 보장.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $filePath = $this->getCacheFilePath($key);
        $ttl = $ttl ?? $this->defaultTtl;

        $data = [
            'k' => $key,
            'v' => $value,
            'c' => time(),
            'e' => time() + $ttl,
        ];

        try {
            $dir = dirname($filePath);
            $this->ensureDirectory($dir);

            // PHP return 형식 + 직접 접근 차단 가드
            $content = "<?php\ndefined('MUBLO_ROOT_PATH') or exit;\nreturn " . var_export($data, true) . ';';

            // 원자적 쓰기: temp → rename (읽기 시 깨진 파일 방지)
            $tmpFile = $filePath . '.' . getmypid() . '.tmp';

            if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
                return false;
            }

            // OpCache 무효화 (기존 파일 갱신 시)
            if ($this->hasOpcache && file_exists($filePath)) {
                opcache_invalidate($filePath, true);
            }

            $result = rename($tmpFile, $filePath);

            // rename 후 stat 캐시 초기화 + OpCache 재무효화
            if ($result) {
                clearstatcache(true, $filePath);
                if ($this->hasOpcache) {
                    opcache_invalidate($filePath, true);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            // tmp 파일 정리
            if (isset($tmpFile) && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            return false;
        }
    }

    /**
     * 캐시 존재 여부
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 캐시 삭제
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getCacheFilePath($key);
        return $this->deleteFile($filePath);
    }

    /**
     * 전체 캐시 삭제 (현재 도메인 범위)
     */
    public function flush(): int
    {
        return $this->flushDirectory($this->cachePath);
    }

    /**
     * 캐시 조회 또는 생성
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * 만료된 캐시 정리
     *
     * @param int $limit 최대 삭제 수 (0 = 무제한, cron용)
     * @return int 삭제된 파일 수
     */
    public function cleanup(int $limit = 0): int
    {
        return $this->cleanupDirectory($this->cachePath, $limit);
    }

    /**
     * 캐시 파일 경로 생성
     *
     * 해시 서브디렉토리 분산: ab/cd/abcd...ef.php
     * 디렉토리당 최대 256개 서브디렉토리 × 256 = 65,536개 분산
     */
    private function getCacheFilePath(string $key): string
    {
        $hash = hash('sha256', $key);

        // 해시 앞 4자리로 2단계 서브디렉토리 (ab/cd/)
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);

        return $this->cachePath . '/' . $dir1 . '/' . $dir2 . '/' . $hash . self::CACHE_EXTENSION;
    }

    /**
     * 캐시 TTL 조회
     *
     * @return int 남은 TTL (초), -1이면 만료됨, -2면 키 없음
     */
    public function ttl(string $key): int
    {
        $filePath = $this->getCacheFilePath($key);

        if (!file_exists($filePath)) {
            return -2;
        }

        try {
            $data = @include $filePath;

            if (!is_array($data) || !isset($data['e'])) {
                return -2;
            }

            $remaining = $data['e'] - time();
            return $remaining > 0 ? $remaining : -1;
        } catch (\Throwable $e) {
            return -2;
        }
    }

    /**
     * 증가 (카운터)
     */
    public function increment(string $key, int $value = 1): int|false
    {
        $current = $this->get($key, 0);

        if (!is_numeric($current)) {
            return false;
        }

        $new = (int) $current + $value;

        // 기존 TTL 유지
        $ttl = $this->getRemainingTtl($key);
        $this->set($key, $new, $ttl);

        return $new;
    }

    /**
     * 감소 (카운터)
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * 캐시 파일 여부 (신규 .php + 구 .cache 모두 매칭)
     */
    private function isCacheFile(string $filename): bool
    {
        return str_ends_with($filename, self::CACHE_EXTENSION)
            || str_ends_with($filename, self::LEGACY_EXTENSION);
    }

    /**
     * 파일 삭제 + OpCache 무효화
     */
    private function deleteFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return true;
        }

        // OpCache에서도 제거
        if ($this->hasOpcache) {
            opcache_invalidate($filePath, true);
        }

        return @unlink($filePath);
    }

    /**
     * 기존 키의 남은 TTL 조회 (increment/decrement용)
     */
    private function getRemainingTtl(string $key): int
    {
        $filePath = $this->getCacheFilePath($key);

        if (!file_exists($filePath)) {
            return $this->defaultTtl;
        }

        try {
            $data = @include $filePath;
            if (is_array($data) && isset($data['e'])) {
                $remaining = $data['e'] - time();
                return $remaining > 0 ? $remaining : $this->defaultTtl;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $this->defaultTtl;
    }

    /**
     * 디렉토리 내 모든 캐시 파일 삭제 (재귀)
     */
    private function flushDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $this->isCacheFile($item->getFilename())) {
                if ($this->hasOpcache) {
                    opcache_invalidate($item->getPathname(), true);
                }
                if (@unlink($item->getPathname())) {
                    $count++;
                }
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname()); // 빈 디렉토리 정리
            }
        }

        return $count;
    }

    /**
     * 만료된 캐시 정리 (재귀)
     *
     * @param string $dir 대상 디렉토리
     * @param int $limit 최대 삭제 수 (0 = 무제한)
     */
    private function cleanupDirectory(string $dir, int $limit = 0): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $now = time();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            // 배치 제한 도달 시 중단
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            if (!$item->isFile() || !$this->isCacheFile($item->getFilename())) {
                continue;
            }

            // 구 형식(.cache) 파일은 무조건 삭제
            if (str_ends_with($item->getFilename(), self::LEGACY_EXTENSION)) {
                if (@unlink($item->getPathname())) {
                    $count++;
                }
                continue;
            }

            try {
                $data = @include $item->getPathname();

                $expired = !is_array($data)
                    || !isset($data['e'])
                    || $data['e'] < $now;

                if ($expired) {
                    if ($this->hasOpcache) {
                        opcache_invalidate($item->getPathname(), true);
                    }
                    if (@unlink($item->getPathname())) {
                        $count++;
                    }
                }
            } catch (\Throwable $e) {
                if (@unlink($item->getPathname())) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
