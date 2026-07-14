<?php

namespace Tests\Unit\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Security\RateLimiter;
use Mublo\Infrastructure\Cache\CacheInterface;

class RateLimiterTest extends TestCase
{
    /**
     * 인메모리 CacheInterface 스텁 (TTL 무시 — 단위 테스트용).
     */
    private function memoryCache(): CacheInterface
    {
        return new class implements CacheInterface {
            private array $store = [];
            public function get(string $key, mixed $default = null): mixed { return $this->store[$key] ?? $default; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->store[$key] = $value; return true; }
            public function has(string $key): bool { return isset($this->store[$key]); }
            public function delete(string $key): bool { unset($this->store[$key]); return true; }
            public function flush(): int { $n = count($this->store); $this->store = []; return $n; }
            public function remember(string $key, int $ttl, callable $callback): mixed {
                if (!isset($this->store[$key])) { $this->store[$key] = $callback(); }
                return $this->store[$key];
            }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };
    }

    public function testAllowsUpToLimitThenDenies(): void
    {
        $limiter = new RateLimiter($this->memoryCache());

        // 최대 3회 허용
        $this->assertTrue($limiter->attempt('k', 3, 600));
        $this->assertTrue($limiter->attempt('k', 3, 600));
        $this->assertTrue($limiter->attempt('k', 3, 600));

        // 4번째는 초과
        $this->assertFalse($limiter->attempt('k', 3, 600));
    }

    public function testSeparateKeysAreIndependent(): void
    {
        $limiter = new RateLimiter($this->memoryCache());

        $this->assertTrue($limiter->attempt('ip-a', 1, 600));
        $this->assertFalse($limiter->attempt('ip-a', 1, 600));

        // 다른 키는 영향 없음
        $this->assertTrue($limiter->attempt('ip-b', 1, 600));
    }

    public function testZeroLimitTreatedAsUnlimited(): void
    {
        $limiter = new RateLimiter($this->memoryCache());
        $this->assertTrue($limiter->attempt('k', 0, 600));
    }

    public function testFailsOpenOnCacheError(): void
    {
        $brokenCache = new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed { throw new \RuntimeException('cache down'); }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { throw new \RuntimeException('cache down'); }
            public function has(string $key): bool { return false; }
            public function delete(string $key): bool { return false; }
            public function flush(): int { return 0; }
            public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };

        $limiter = new RateLimiter($brokenCache);

        // 캐시 장애 시 요청을 막지 않아야 한다 (가용성)
        $this->assertTrue($limiter->attempt('k', 1, 600));
    }
}
