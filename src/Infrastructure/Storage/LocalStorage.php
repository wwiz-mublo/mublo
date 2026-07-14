<?php
namespace Mublo\Infrastructure\Storage;

/**
 * LocalStorage
 *
 * 로컬 파일시스템 스토리지 구현
 * - 멀티 도메인 지원 (D{domain_id} 폴더 구조)
 */
class LocalStorage implements StorageInterface
{
    private string $basePath;
    private string $baseUrl;

    public function __construct(?string $basePath = null, ?string $baseUrl = null)
    {
        $this->basePath = $basePath ?? (defined('MUBLO_STORAGE_PATH') ? MUBLO_STORAGE_PATH : 'storage');
        $this->baseUrl = $baseUrl ?? '/storage';
    }

    /**
     * 도메인별 경로 생성
     *
     * @param int $domainId 도메인 ID
     * @param string $subPath 하위 경로
     * @return string
     */
    public function domainPath(int $domainId, string $subPath = ''): string
    {
        $path = 'D' . $domainId;
        if ($subPath) {
            $path .= '/' . ltrim($subPath, '/');
        }
        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->getFullPath($path);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($fullPath, $contents) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function putStream(string $path, $resource): bool
    {
        $fullPath = $this->getFullPath($path);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dest = fopen($fullPath, 'wb');
        if (!$dest) {
            return false;
        }

        stream_copy_to_stream($resource, $dest);
        fclose($dest);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $contents = file_get_contents($fullPath);
        return $contents === false ? null : $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(string $path)
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        return fopen($fullPath, 'rb') ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return true;
        }

        return unlink($fullPath);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        return file_exists($this->getFullPath($path));
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $from, string $to): bool
    {
        $fromPath = $this->getFullPath($from);
        $toPath = $this->getFullPath($to);

        if (!file_exists($fromPath)) {
            return false;
        }

        $dir = dirname($toPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return copy($fromPath, $toPath);
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $from, string $to): bool
    {
        $fromPath = $this->getFullPath($from);
        $toPath = $this->getFullPath($to);

        if (!file_exists($fromPath)) {
            return false;
        }

        $dir = dirname($toPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return rename($fromPath, $toPath);
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $path): ?int
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        return filesize($fullPath) ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): ?int
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        return filemtime($fullPath) ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function files(string $directory, bool $recursive = false): array
    {
        $fullPath = $this->getFullPath($directory);

        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativePath = str_replace($this->basePath . '/', '', $file->getPathname());
                    $files[] = $relativePath;
                }
            }
        } else {
            $items = scandir($fullPath);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $itemPath = $fullPath . '/' . $item;
                if (is_file($itemPath)) {
                    $files[] = $directory . '/' . $item;
                }
            }
        }

        return $files;
    }

    /**
     * 디렉토리 목록
     */
    public function directories(string $directory): array
    {
        $fullPath = $this->getFullPath($directory);

        if (!is_dir($fullPath)) {
            return [];
        }

        $dirs = [];
        $items = scandir($fullPath);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $fullPath . '/' . $item;
            if (is_dir($itemPath)) {
                $dirs[] = $directory . '/' . $item;
            }
        }

        return $dirs;
    }

    /**
     * {@inheritdoc}
     */
    public function makeDirectory(string $path): bool
    {
        $fullPath = $this->getFullPath($path);

        if (is_dir($fullPath)) {
            return true;
        }

        return mkdir($fullPath, 0755, true);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $directory): bool
    {
        $fullPath = $this->getFullPath($directory);

        if (!is_dir($fullPath)) {
            return true;
        }

        return $this->removeDirectory($fullPath);
    }

    /**
     * 디렉토리 크기 계산 (재귀)
     */
    public function directorySize(string $directory): int
    {
        $fullPath = $this->getFullPath($directory);

        if (!is_dir($fullPath)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * 도메인별 사용량 조회
     */
    public function getDomainUsage(int $domainId): int
    {
        return $this->directorySize($this->domainPath($domainId));
    }

    /**
     * 전체 경로 반환
     */
    public function getFullPath(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    /**
     * 기본 경로 반환
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * 디렉토리 재귀 삭제
     */
    private function removeDirectory(string $path): bool
    {
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        return rmdir($path);
    }
}
