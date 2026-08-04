<?php
declare(strict_types=1);

namespace Mublo\Infrastructure\Security;

use Mublo\Infrastructure\Cache\CacheInterface;

/**
 * RateLimiter
 *
 * 캐시 기반 고정 윈도우 레이트 리미터.
 * 공개(비인증) 엔드포인트의 남용(대량 업로드 등)을 IP 등 키 단위로 제한한다.
 *
 * - 고정 윈도우: time()을 windowSeconds로 나눈 버킷 단위로 카운트 → 윈도우마다 리셋
 * - fail-open: 캐시 오류 시 요청을 막지 않는다(가용성). 남용 방지는 방어층이지 인증 경계가 아님.
 */
class RateLimiter
{
    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * 시도 1회를 소비하고 허용 여부를 반환한다.
     *
     * @param string $key           제한 키 (예: "editor-upload:{ip}")
     * @param int    $maxAttempts   윈도우당 최대 허용 횟수
     * @param int    $windowSeconds 윈도우 길이(초)
     * @return bool  true=허용, false=한도 초과
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if ($maxAttempts <= 0 || $windowSeconds <= 0) {
            return true;
        }

        try {
            $bucket = (int) floor(time() / $windowSeconds);
            $cacheKey = $this->cacheKey($key, $bucket);

            // 읽고 나서 쓰면 동시 요청이 같은 값을 읽어 증가분이 유실되고, 병렬로
            // 때리는 쪽은 한도를 사실상 무시할 수 있다. 드라이버의 원자적 증가를 쓴다.
            // 만료는 윈도우 경과 후 정리되도록 약간의 여유를 둔다.
            $count = $this->cache->increment($cacheKey, 1, $windowSeconds + 5);

            if ($count === false) {
                // 카운트를 올릴 수 없으면 제한을 강제할 근거가 없다 — fail-open.
                error_log('[SECURITY] RateLimiter::attempt 증가 실패 — 제한 미적용: ' . $key);
                return true;
            }

            return $count <= $maxAttempts;
        } catch (\Throwable $e) {
            // 캐시 장애로 제한을 확인할 수 없으면 요청은 허용하되(가용성) 로깅한다.
            error_log('[SECURITY] RateLimiter::attempt 실패 — 제한 미적용: ' . $e->getMessage());
            return true;
        }
    }

    private function cacheKey(string $key, int $bucket): string
    {
        return 'ratelimit:' . sha1($key) . ':' . $bucket;
    }
}
