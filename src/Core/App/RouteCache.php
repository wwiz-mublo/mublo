<?php
declare(strict_types=1);

namespace Mublo\Core\App;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as CompiledDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;

/**
 * 라우터 내부의 파일 캐시. 읽기·재생성·삭제가 같은 도메인 잠금을 사용한다.
 * 잠금 파일은 교체하거나 삭제하지 않는다 — 기다리는 프로세스와 inode를 공유해야 한다.
 */
final class RouteCache
{
    private const TTL = 3600;

    public function __construct(private readonly string $directory)
    {
    }

    public function filePath(string $domain, string $signature): string
    {
        return $this->directory . '/' . $this->safeDomain($domain) . '.' . $signature . '.cache.php';
    }

    /** @param callable(RouteCollector): void $defineRoutes */
    public function getDispatcher(string $domain, string $signature, callable $defineRoutes): Dispatcher
    {
        $file = $this->filePath($domain, $signature);
        $lock = $this->openLock($domain);
        if ($lock === null) {
            return new CompiledDispatcher($this->compile($defineRoutes));
        }

        try {
            if (!$this->acquire($lock, LOCK_SH)) {
                return new CompiledDispatcher($this->compile($defineRoutes));
            }
            $data = $this->read($file);
            if ($data !== null) {
                return new CompiledDispatcher($data);
            }

            // 공유 잠금에서 배타 잠금으로 바꿀 때는 교착을 피하려고 먼저 해제한다.
            // 그 사이 다른 요청이 재생성했을 수 있으므로 배타 잠금 안에서 다시 읽는다.
            flock($lock, LOCK_UN);
            if (!$this->acquire($lock, LOCK_EX)) {
                return new CompiledDispatcher($this->compile($defineRoutes));
            }
            $data = $this->read($file);
            if ($data === null) {
                // 정의·컴파일 오류는 캐시 I/O 오류가 아니다. 예외를 그대로 호출자에게 전달한다.
                $data = $this->compile($defineRoutes);
                if ($this->write($file, $data)) {
                    $this->deleteFiles($domain, $file);
                }
            }
            return new CompiledDispatcher($data);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param callable(RouteCollector): void $defineRoutes */
    private function compile(callable $defineRoutes): array
    {
        $collector = new RouteCollector(new Std(), new DataGenerator());
        $defineRoutes($collector);
        return $collector->getData();
    }

    private function read(string $file): ?array
    {
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        if ($mtime === false || time() - $mtime > self::TTL) {
            return null;
        }

        try {
            // 외부 도구/이전 버전이 잠금 없이 지우거나 남긴 불완전한 파일도 캐시 미스로 처리한다.
            ob_start();
            try {
                $data = @include $file;
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if ($output !== '') {
                $this->report('unexpected cache output', $file);
                return null;
            }
            if (!is_array($data) || array_keys($data) !== [0, 1]
                || !is_array($data[0]) || !is_array($data[1])) {
                $this->report('invalid cache', $file);
                return null;
            }
            foreach ($data[0] as $routes) {
                if (!is_array($routes)) {
                    $this->report('invalid static routes', $file);
                    return null;
                }
            }
            foreach ($data[1] as $groups) {
                if (!is_array($groups)) {
                    $this->report('invalid variable route groups', $file);
                    return null;
                }
                foreach ($groups as $group) {
                    if (!is_array($group) || !is_string($group['regex'] ?? null)
                        || !is_array($group['routeMap'] ?? null)) {
                        $this->report('invalid variable routes', $file);
                        return null;
                    }
                }
            }
            return $data;
        } catch (\Throwable $e) {
            $this->report('cache read failed: ' . $e->getMessage(), $file);
            return null;
        }
    }

    private function write(string $file, array $data): bool
    {
        $temporary = null;
        try {
            // 같은 디렉터리에 기록하고 완성된 파일만 rename으로 공개한다.
            $temporary = @tempnam($this->directory, '.route-');
            if ($temporary === false) {
                $this->report('temporary file creation failed', $file);
                return false;
            }
            if (realpath(dirname($temporary)) !== realpath($this->directory)) {
                $this->report('temporary file is outside cache directory', $file);
                return false;
            }
            $content = '<?php return ' . var_export($data, true) . ';';
            if (@file_put_contents($temporary, $content) !== strlen($content)) {
                $this->report('cache write failed', $file);
                return false;
            }
            if (!@rename($temporary, $file)) {
                $this->report('cache replacement failed', $file);
                return false;
            }
            $this->invalidateMetadata($file);
            return true;
        } catch (\Throwable $e) {
            $this->report('cache write failed: ' . $e->getMessage(), $file);
            return false;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** 파일이 없으면 성공. 권한 등으로 지우지 못하면 false. */
    public function clear(string $domain): bool
    {
        return $this->clearDomain($domain)['success'] ?? false;
    }

    /** 도메인별로 잠금을 얻어 삭제하며, 잠금 파일은 남긴다. */
    public function clearAll(): int
    {
        $count = 0;
        $domains = $this->domains();
        // 최초 생성 중에는 캐시 파일이 아직 없다. 잠금 파일도 발견해야 그 생성 뒤에 지울 수 있다.
        foreach (glob($this->directory . '/*.lock') ?: [] as $file) {
            $domains[] = str_replace('_', ':', basename($file, '.lock'));
        }
        foreach (array_unique($domains) as $domain) {
            $count += $this->clearDomain($domain)['deleted'] ?? 0;
        }
        return $count;
    }

    /** @return list<string> */
    public function domains(): array
    {
        $domains = [];
        foreach (glob($this->directory . '/*.cache.php') ?: [] as $file) {
            $base = basename($file, '.cache.php');
            $domain = preg_replace('/\.(?:[a-f0-9]{12}|legacy)$/', '', $base) ?? $base;
            $domains[] = str_replace('_', ':', $domain);
        }
        return array_values(array_unique($domains));
    }

    /** @return array{deleted: int, success: bool}|null */
    private function clearDomain(string $domain): ?array
    {
        if (!is_dir($this->directory)) {
            return ['deleted' => 0, 'success' => true];
        }
        $lock = $this->openLock($domain);
        if ($lock === null) {
            return null;
        }
        try {
            if (!$this->acquire($lock, LOCK_EX)) {
                return null;
            }
            return $this->deleteFiles($domain);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 배타 잠금 안에서만 호출. 새 캐시 공개에 성공한 뒤에만 구 시그니처를 제거한다.
     * @return array{deleted: int, success: bool}
     */
    private function deleteFiles(string $domain, ?string $keep = null): array
    {
        $pattern = '/^' . preg_quote($this->safeDomain($domain), '/')
            . '(?:\.(?:[a-f0-9]{12}|legacy))?\.cache\.php$/';
        $count = 0;
        $success = true;
        foreach (glob($this->directory . '/*.cache.php') ?: [] as $file) {
            if ($file === $keep || preg_match($pattern, basename($file)) !== 1) {
                continue;
            }
            if (@unlink($file)) {
                $this->invalidateMetadata($file);
                $count++;
            } else {
                clearstatcache(true, $file);
                if (file_exists($file)) {
                    $this->report('cache deletion failed', $file);
                    $success = false;
                }
            }
        }
        return ['deleted' => $count, 'success' => $success];
    }

    /** @return resource|null */
    private function openLock(string $domain)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            $this->report('cache directory creation failed', $this->directory);
            return null;
        }
        $path = $this->directory . '/' . $this->safeDomain($domain) . '.lock';
        $lock = @fopen($path, 'c');
        if ($lock === false) {
            $this->report('lock file open failed', $path);
            return null;
        }
        return $lock;
    }

    /** @param resource $lock */
    private function acquire($lock, int $operation): bool
    {
        if (@flock($lock, $operation)) {
            return true;
        }
        $this->report('cache lock failed', $this->directory);
        return false;
    }

    private function invalidateMetadata(string $file): void
    {
        clearstatcache(true, $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function safeDomain(string $domain): string
    {
        return str_replace([':', '/', '\\'], '_', $domain);
    }

    private function report(string $message, string $file): void
    {
        error_log('[RouteCache] ' . $message . ': ' . $file);
    }
}
