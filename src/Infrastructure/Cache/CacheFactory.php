<?php

namespace Mublo\Infrastructure\Cache;

use Mublo\Infrastructure\Redis\RedisManager;

/**
 * CacheFactory
 *
 * 캐시 드라이버 팩토리
 * - config/security.php의 cache_driver 설정에 따라 캐시 인스턴스 생성
 * - Redis 사용 불가 시 파일 캐시로 자동 fallback
 * - 멀티테넌트 지원
 */
class CacheFactory
{
    private static array $instances = [];

    /**
     * 캐시 인스턴스 생성
     *
     * @param int|null $domainId 도메인 ID (멀티테넌트)
     * @param string $prefix 캐시 prefix
     * @param int $ttl 기본 TTL (초)
     * @return CacheInterface
     */
    public static function create(
        ?int $domainId = null,
        string $prefix = '',
        int $ttl = 3600
    ): CacheInterface {
        $driver = self::getDriver();

        if ($driver === 'redis' && self::isRedisAvailable()) {
            return new RedisCache($prefix, $ttl, $domainId);
        }

        return new FileCache(null, $ttl, $domainId);
    }

    /**
     * 싱글톤 인스턴스 반환 (도메인별)
     *
     * @param int|null $domainId 도메인 ID
     * @return CacheInterface
     */
    public static function getInstance(?int $domainId = null): CacheInterface
    {
        $key = $domainId ?? 'global';

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::create($domainId);
        }

        return self::$instances[$key];
    }

    /**
     * 드라이버 설정 로드
     */
    private static function getDriver(): string
    {
        $configPath = dirname(__DIR__, 3) . '/config/security.php';

        if (file_exists($configPath)) {
            $config = require $configPath;
            return $config['cache_driver'] ?? 'file';
        }

        return 'file';
    }

    /**
     * Redis 사용 가능 여부
     */
    private static function isRedisAvailable(): bool
    {
        if (!RedisManager::isAvailable()) {
            return false;
        }

        try {
            return RedisManager::isConnected();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 네임스페이스 전용 캐시 인스턴스 생성
     *
     * global/ 과 독립된 전용 경로/키공간을 사용하는 캐시를 생성한다.
     * - File: storage/cache/data/{name}/
     * - Redis: prefix = "{name}:"
     *
     * 사용처: DomainCache('domains'), SnsLoginCache('sns') 등
     * 전역 캐시(CacheInterface)의 setDomainId() 영향을 받지 않는다.
     *
     * @param string $name 네임스페이스 이름 (영문 소문자 권장)
     * @param int $ttl 기본 TTL (초)
     * @return CacheInterface
     */
    public static function createNamed(string $name, int $ttl = 3600): CacheInterface
    {
        $driver = self::getDriver();

        if ($driver === 'redis' && self::isRedisAvailable()) {
            return new RedisCache($name . ':', $ttl);
        }

        $path = dirname(__DIR__, 3) . '/storage/cache/data/' . $name;
        return new FileCache($path, $ttl);
    }

    /**
     * 인스턴스 캐시 초기화 (테스트용)
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
    }

    /**
     * 현재 사용 중인 드라이버 반환
     */
    public static function getCurrentDriver(): string
    {
        $driver = self::getDriver();

        if ($driver === 'redis' && !self::isRedisAvailable()) {
            return 'file';  // fallback
        }

        return $driver;
    }
}
