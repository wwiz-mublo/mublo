<?php

namespace Mublo\Infrastructure\Session;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Mublo\Infrastructure\Redis\RedisManager;

/**
 * RedisSessionHandler
 *
 * Redis 기반 세션 핸들러
 * - PHP SessionHandlerInterface 구현
 * - Redis에 세션 데이터 저장
 * - TTL 기반 자동 만료
 * - 멀티테넌트 지원 (도메인별 분리)
 *
 * SessionUpdateTimestampHandlerInterface 구현은 필수다 — 커스텀 핸들러는
 * validateId() 가 있어야 session.use_strict_mode 가 실제로 동작한다.
 * 미구현이면 공격자가 심은 미발급 세션 ID 를 PHP 가 그대로 채택해
 * 픽세이션 방어가 무력화된다(파일 드라이버만 보호되는 상태였음).
 */
class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private string $prefix;
    private int $ttl;

    /**
     * @param int $ttl 세션 TTL (초)
     * @param int|null $domainId 도메인 ID (멀티테넌트)
     */
    public function __construct(int $ttl = 7200, ?int $domainId = null)
    {
        $this->ttl = $ttl;

        // 도메인별 prefix 설정
        $this->prefix = $domainId
            ? "sess:d{$domainId}:"
            : 'sess:';
    }

    /**
     * 세션 열기
     */
    public function open(string $path, string $name): bool
    {
        try {
            return RedisManager::isConnected();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 세션 닫기
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * 세션 데이터 읽기
     */
    public function read(string $id): string|false
    {
        try {
            $redis = RedisManager::getInstance();
            $data = $redis->get($this->prefix . $id);

            return $data !== false ? $data : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 세션 데이터 쓰기
     */
    public function write(string $id, string $data): bool
    {
        try {
            $redis = RedisManager::getInstance();
            return $redis->setex($this->prefix . $id, $this->ttl, $data);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 세션 삭제
     */
    public function destroy(string $id): bool
    {
        try {
            $redis = RedisManager::getInstance();
            $redis->del($this->prefix . $id);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 가비지 컬렉션 (Redis TTL이 처리하므로 불필요)
     */
    public function gc(int $max_lifetime): int|false
    {
        // Redis TTL이 자동으로 만료 처리
        return 0;
    }

    /**
     * 세션 ID 유효성 검증 (session.use_strict_mode 지원)
     *
     * 서버가 발급한 적 없는 ID 면 false → PHP 가 새 ID 를 발급한다.
     * 검증 불가(Redis 장애)도 미발급으로 간주한다(fail-closed) —
     * 새 ID 가 발급될 뿐 요청 자체가 실패하지는 않는다.
     */
    public function validateId(string $id): bool
    {
        try {
            $redis = RedisManager::getInstance();
            return (bool) $redis->exists($this->prefix . $id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 세션 데이터 변경 없이 TTL 만 갱신 (session.lazy_write 지원)
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $redis = RedisManager::getInstance();
            return (bool) $redis->expire($this->prefix . $id, $this->ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
