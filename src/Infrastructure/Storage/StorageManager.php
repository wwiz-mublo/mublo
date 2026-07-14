<?php
namespace Mublo\Infrastructure\Storage;

/**
 * StorageManager
 *
 * 스토리지 팩토리/매니저
 * - 다중 디스크 관리
 * - 기본 디스크 설정
 */
class StorageManager
{
    private array $disks = [];
    private string $defaultDisk = 'local';

    public function __construct()
    {
        // 기본 로컬 디스크 등록
        $this->disks['local'] = new LocalStorage();
    }

    /**
     * 디스크 인스턴스 반환
     *
     * @param string|null $name 디스크 이름 (null이면 기본 디스크)
     * @return StorageInterface
     */
    public function disk(?string $name = null): StorageInterface
    {
        $name = $name ?? $this->defaultDisk;

        if (!isset($this->disks[$name])) {
            throw new \InvalidArgumentException("Storage disk '{$name}' not found.");
        }

        return $this->disks[$name];
    }

    /**
     * 디스크 등록
     *
     * @param string $name 디스크 이름
     * @param StorageInterface $storage 스토리지 인스턴스
     * @return self
     */
    public function addDisk(string $name, StorageInterface $storage): self
    {
        $this->disks[$name] = $storage;
        return $this;
    }

    /**
     * 기본 디스크 설정
     *
     * @param string $name 디스크 이름
     * @return self
     */
    public function setDefaultDisk(string $name): self
    {
        if (!isset($this->disks[$name])) {
            throw new \InvalidArgumentException("Storage disk '{$name}' not found.");
        }

        $this->defaultDisk = $name;
        return $this;
    }

    /**
     * 기본 디스크 이름 반환
     */
    public function getDefaultDisk(): string
    {
        return $this->defaultDisk;
    }

    /**
     * 등록된 디스크 목록
     */
    public function getDisks(): array
    {
        return array_keys($this->disks);
    }

    // === 기본 디스크 프록시 메서드 ===

    public function put(string $path, string $contents): bool
    {
        return $this->disk()->put($path, $contents);
    }

    public function get(string $path): ?string
    {
        return $this->disk()->get($path);
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function copy(string $from, string $to): bool
    {
        return $this->disk()->copy($from, $to);
    }

    public function move(string $from, string $to): bool
    {
        return $this->disk()->move($from, $to);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    public function size(string $path): ?int
    {
        return $this->disk()->size($path);
    }

    public function files(string $directory, bool $recursive = false): array
    {
        return $this->disk()->files($directory, $recursive);
    }

    public function makeDirectory(string $path): bool
    {
        return $this->disk()->makeDirectory($path);
    }

    public function deleteDirectory(string $directory): bool
    {
        return $this->disk()->deleteDirectory($directory);
    }
}
