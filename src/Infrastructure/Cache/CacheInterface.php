<?php
declare(strict_types=1);

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
     * 카운터 원자적 증가
     *
     * get() 후 set() 하는 방식은 동시 요청에서 증가분이 유실된다. 레이트 리밋처럼
     * 카운트가 곧 방어선인 곳에서는 그 유실이 제한 우회가 되므로, 드라이버가
     * 원자성을 책임진다.
     *
     * @param string   $key   캐시 키
     * @param int      $value 증가량 (음수면 감소)
     * @param int|null $ttl   키가 새로 만들어질 때만 적용할 TTL(초).
     *                        이미 있는 키의 만료 시각은 건드리지 않는다 —
     *                        증가할 때마다 갱신하면 윈도우가 끝없이 밀린다.
     * @return int|false 증가 후 값. 실패 시 false
     */
    public function increment(string $key, int $value = 1, ?int $ttl = null): int|false;

    /**
     * 도메인 ID 설정 (멀티테넌트)
     */
    public function setDomainId(?int $domainId): self;

    /**
     * 현재 도메인 ID 반환
     */
    public function getDomainId(): ?int;
}
