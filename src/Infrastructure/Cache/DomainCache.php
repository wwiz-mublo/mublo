<?php

namespace Mublo\Infrastructure\Cache;

use Mublo\Entity\Domain\Domain;

/**
 * DomainCache
 *
 * 도메인 정보 캐시 어댑터
 * - CacheInterface에 위임 (FileCache 또는 RedisCache)
 * - Domain ↔ array 변환만 담당
 * - 키 접두사 'domain:' 으로 네임스페이스 격리
 *
 * 이전 방식 (제거됨):
 * - 자체 파일 I/O, 암호화, checksum → 불필요한 복잡성
 * - CacheInterface가 이미 제공하는 기능을 중복 구현
 */
class DomainCache
{
    private CacheInterface $cache;
    private int $ttl;

    private const KEY_PREFIX = 'domain:';
    private const DEFAULT_TTL = 600;

    /**
     * @param CacheInterface|null $cache 캐시 드라이버 (null이면 CacheFactory에서 생성)
     * @param int $ttl 기본 TTL (초)
     */
    public function __construct(
        ?CacheInterface $cache = null,
        int $ttl = self::DEFAULT_TTL
    ) {
        // CacheFactory::getInstance() 공유 싱글톤을 사용하지 않는다.
        // Application::run()이 setDomainId()를 호출해 공유 싱글톤의 cachePath를
        // domain별 경로(d1/)로 바꾸면, 이후 invalidate()가 엉뚱한 경로를
        // 바라보게 되어 캐시 무효화가 실패한다.
        // → createNamed('domains')로 독립 전용 경로(storage/cache/data/domains/)를 사용한다.
        // global/ 과 격리되어 도메인 캐시만 선택적 flush도 가능해진다.
        $this->cache = $cache ?? CacheFactory::createNamed('domains', $ttl);
        $this->ttl = $ttl;
    }

    /**
     * 캐시에서 Domain 조회
     */
    public function get(string $domainName): ?Domain
    {
        $data = $this->cache->get($this->key($domainName));

        if (!is_array($data)) {
            return null;
        }

        try {
            return Domain::fromArray($data);
        } catch (\Throwable $e) {
            // 손상된 캐시 데이터 정리
            $this->delete($domainName);
            return null;
        }
    }

    /**
     * Domain을 캐시에 저장
     */
    public function set(Domain $domain): bool
    {
        return $this->cache->set(
            $this->key($domain->getDomain()),
            $domain->toArray(),
            $this->ttl
        );
    }

    /**
     * 캐시 삭제
     */
    public function delete(string $domainName): bool
    {
        return $this->cache->delete($this->key($domainName));
    }

    /**
     * 캐시 존재 여부
     */
    public function has(string $domainName): bool
    {
        return $this->get($domainName) !== null;
    }

    /**
     * 전체 캐시 삭제
     */
    public function flush(): int
    {
        return $this->cache->flush();
    }

    /**
     * 만료된 캐시 정리 (CacheInterface에 위임)
     */
    public function cleanup(): int
    {
        if (method_exists($this->cache, 'cleanup')) {
            return $this->cache->cleanup();
        }

        return 0;
    }

    /**
     * 현재 드라이버 반환 (호환용)
     */
    public function getDriver(): string
    {
        return CacheFactory::getCurrentDriver();
    }

    /**
     * 캐시 키 생성
     */
    private function key(string $domainName): string
    {
        return self::KEY_PREFIX . strtolower($domainName);
    }
}
