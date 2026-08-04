<?php
declare(strict_types=1);

namespace Mublo\Infrastructure\Cache;

use Mublo\Infrastructure\Redis\RedisManager;

/**
 * RedisCache
 *
 * Redis 기반 캐시 클래스
 * - CacheInterface 구현
 * - TTL 기반 자동 만료
 * - 멀티테넌트 지원 (도메인별 분리)
 */
class RedisCache implements CacheInterface
{
    private string $prefix;
    private int $defaultTtl;
    private ?int $domainId = null;

    /**
     * @param string $prefix 캐시 prefix
     * @param int $defaultTtl 기본 TTL (초)
     * @param int|null $domainId 도메인 ID (멀티테넌트)
     */
    public function __construct(
        string $prefix = 'cache:',
        int $defaultTtl = 3600,
        ?int $domainId = null
    ) {
        $this->defaultTtl = $defaultTtl;
        $this->domainId = $domainId;

        // 도메인별 prefix 설정
        $this->prefix = $domainId
            ? "cache:d{$domainId}:{$prefix}"
            : "cache:{$prefix}";
    }

    /**
     * 도메인 ID 설정
     */
    public function setDomainId(?int $domainId): self
    {
        $this->domainId = $domainId;
        $basePrefix = preg_replace('/^cache:(d\d+:)?/', '', $this->prefix);
        $this->prefix = $domainId
            ? "cache:d{$domainId}:{$basePrefix}"
            : "cache:{$basePrefix}";

        return $this;
    }

    /**
     * 캐시 조회
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $redis = RedisManager::getInstance();
            $data = $redis->get($this->prefix . $key);

            if ($data === false) {
                return $default;
            }

            $decoded = self::decode((string) $data);
            // 디코드 실패(레거시/손상 데이터) 또는 null 저장은 캐시 미스로 취급
            return $decoded ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * 값 JSON 인코딩 (자동 PHP 직렬화를 대체 — 객체 인젝션 방지)
     */
    private static function encode(mixed $value): string|false
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 값 JSON 디코딩 (연관배열). 실패 시 null.
     */
    private static function decode(string $data): mixed
    {
        $decoded = json_decode($data, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * 캐시 저장
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $encoded = self::encode($value);
            if ($encoded === false) {
                return false;
            }

            $redis = RedisManager::getInstance();
            $ttl = $ttl ?? $this->defaultTtl;

            return $redis->setex($this->prefix . $key, $ttl, $encoded);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 캐시 존재 여부
     */
    public function has(string $key): bool
    {
        try {
            $redis = RedisManager::getInstance();
            return $redis->exists($this->prefix . $key) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 캐시 삭제
     */
    public function delete(string $key): bool
    {
        try {
            $redis = RedisManager::getInstance();
            $redis->del($this->prefix . $key);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 패턴 기반 삭제
     */
    public function deletePattern(string $pattern): int
    {
        try {
            $redis = RedisManager::getInstance();
            $deletedCount = 0;
            $iterator = null;
            $matchPattern = $this->prefix . $pattern;

            // KEYS 대신 SCAN을 사용하여 대량의 키 삭제 시 Redis 블로킹 방지
            while (false !== ($keys = $redis->scan($iterator, $matchPattern, 100))) {
                if (!empty($keys)) {
                    $deletedCount += $redis->del($keys);
                }
            }

            return $deletedCount;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 전체 캐시 삭제 (현재 도메인 범위)
     */
    public function flush(): int
    {
        return $this->deletePattern('*');
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
     * 캐시 TTL 조회
     */
    public function ttl(string $key): int
    {
        try {
            $redis = RedisManager::getInstance();
            return $redis->ttl($this->prefix . $key);
        } catch (\Throwable $e) {
            return -2;  // 키 없음
        }
    }

    /**
     * 캐시 만료 시간 연장
     */
    public function touch(string $key, int $ttl): bool
    {
        try {
            $redis = RedisManager::getInstance();
            return $redis->expire($this->prefix . $key, $ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 증가 (카운터) — INCRBY 자체가 원자적이다.
     */
    public function increment(string $key, int $value = 1, ?int $ttl = null): int|false
    {
        try {
            $redis = RedisManager::getInstance();
            $fullKey = $this->prefix . $key;

            // 없을 때만 0 으로 만들면서 만료를 건다. SET NX EX 가 이 둘을 한 번에
            // 처리하므로, 두 요청이 동시에 들어와도 만료가 두 번 걸리지 않는다.
            // 이미 있는 키는 건드리지 않아 윈도우가 밀리지 않는다.
            if ($ttl !== null && $ttl > 0) {
                $redis->set($fullKey, 0, ['nx', 'ex' => $ttl]);
            }

            return $redis->incrBy($fullKey, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 감소 (카운터)
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        try {
            $redis = RedisManager::getInstance();
            return $redis->decrBy($this->prefix . $key, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 현재 도메인 ID 반환
     */
    public function getDomainId(): ?int
    {
        return $this->domainId;
    }
}
