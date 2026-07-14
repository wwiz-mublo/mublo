<?php

namespace Mublo\Infrastructure\Cache;

/**
 * CacheInterface
 *
 * 캐시 드라이버 공통 인터페이스
 * - FileCache, RedisCache 등이 구현
 * - 멀티테넌트 지원 (도메인별 분리)
 */
interface CacheInterface
{
    /**
     * 캐시 조회
     *
     * @param string $key 캐시 키
     * @param mixed $default 기본값
     * @return mixed 캐시 값 또는 기본값
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 캐시 저장
     *
     * @param string $key 캐시 키
     * @param mixed $value 저장할 값
     * @param int|null $ttl TTL (초), null이면 기본값 사용
     * @return bool 성공 여부
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * 캐시 존재 여부
     */
    public function has(string $key): bool;

    /**
     * 캐시 삭제
     */
    public function delete(string $key): bool;

    /**
     * 전체 캐시 삭제 (현재 도메인 범위)
     *
     * @return int 삭제된 항목 수
     */
    public function flush(): int;

    /**
     * 캐시 조회 또는 생성
     *
     * @param string $key 캐시 키
     * @param int $ttl TTL (초)
     * @param callable $callback 캐시 미스 시 호출할 콜백
     * @return mixed 캐시 값
     */
    public function remember(string $key, int $ttl, callable $callback): mixed;

    /**
     * 도메인 ID 설정 (멀티테넌트)
     */
    public function setDomainId(?int $domainId): self;

    /**
     * 현재 도메인 ID 반환
     */
    public function getDomainId(): ?int;
}
