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
            public function increment(string $key, int $value = 1, ?int $ttl = null): int|false {
                $new = (int) ($this->store[$key] ?? 0) + $value;
                $this->store[$key] = $new;
                return $new;
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
            public function increment(string $key, int $value = 1, ?int $ttl = null): int|false { throw new \RuntimeException('cache down'); }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };

        $limiter = new RateLimiter($brokenCache);

        // 캐시 장애 시 요청을 막지 않아야 한다 (가용성)
        $this->assertTrue($limiter->attempt('k', 1, 600));
    }

    public function testFailsOpenWhenDriverCannotIncrement(): void
    {
        // 예외 대신 false 를 돌려주는 드라이버도 같은 fail-open 이어야 한다.
        $cache = new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { return false; }
            public function has(string $key): bool { return false; }
            public function delete(string $key): bool { return false; }
            public function flush(): int { return 0; }
            public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
            public function increment(string $key, int $value = 1, ?int $ttl = null): int|false { return false; }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };

        $this->assertTrue((new RateLimiter($cache))->attempt('k', 1, 600));
    }

    public function testUsesAtomicIncrementRatherThanReadThenWrite(): void
    {
        // 읽고-쓰기 방식이면 동시 요청이 같은 값을 읽어 증가분이 유실되고 한도가
        // 무의미해진다. 리미터가 get()/set() 을 쓰지 않는다는 것 자체를 고정한다.
        $cache = new class implements CacheInterface {
            public array $calls = [];
            private int $count = 0;
            public function get(string $key, mixed $default = null): mixed { $this->calls[] = 'get'; return $default; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->calls[] = 'set'; return true; }
            public function has(string $key): bool { return false; }
            public function delete(string $key): bool { return false; }
            public function flush(): int { return 0; }
            public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
            public function increment(string $key, int $value = 1, ?int $ttl = null): int|false {
                $this->calls[] = 'increment';
                return $this->count += $value;
            }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };

        (new RateLimiter($cache))->attempt('k', 3, 600);

        $this->assertSame(['increment'], $cache->calls);
    }

    public function testWindowExpiryIsSetOnlyFromTheWindowLength(): void
    {
        // TTL 이 윈도우보다 짧으면 카운터가 윈도우 도중에 사라져 제한이 리셋된다.
        $cache = new class implements CacheInterface {
            public ?int $seenTtl = null;
            private int $count = 0;
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { return true; }
            public function has(string $key): bool { return false; }
            public function delete(string $key): bool { return false; }
            public function flush(): int { return 0; }
            public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
            public function increment(string $key, int $value = 1, ?int $ttl = null): int|false {
                $this->seenTtl = $ttl;
                return ++$this->count;
            }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };

        (new RateLimiter($cache))->attempt('k', 3, 600);

        $this->assertNotNull($cache->seenTtl);
        $this->assertGreaterThanOrEqual(600, $cache->seenTtl);
    }
}
