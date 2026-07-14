<?php
namespace Mublo\Service\Auth;

use Mublo\Infrastructure\Database\Database;

/**
 * LoginAttemptService
 *
 * 로그인 시도 Rate Limiting
 * - 계정+IP 기반 제한: (계정, IP) 단위로 무차별 대입 차단 — 계정만으로 집계하면
 *   공격자가 임의 IP로 피해자 계정을 잠그는 DoS 가 되므로 IP 를 결합한다.
 * - IP 기반 제한: 동일 IP에서 계정을 바꿔가며 시도하는 남용 차단
 * - 자동 잠금 해제: 잠금 시간 경과 후 자동 해제
 */
class LoginAttemptService
{
    private Database $db;
    private array $config;

    public function __construct(Database $db, array $config = [])
    {
        $this->db = $db;
        $this->config = array_merge([
            'enabled' => true,
            'max_attempts_per_user' => 5,
            'max_attempts_per_ip' => 20,
            'decay_seconds' => 900,       // 15분 — 실패 집계 창(window). 잠금은 가장 오래된
                                          // 실패가 이 창을 벗어나면(oldest + decay_seconds) 자동 해제된다.
            'cleanup_probability' => 5,   // 5% 확률로 오래된 기록 삭제
        ], $config);
    }

    /**
     * 로그인 시도 전 rate limit 확인
     *
     * @return array{allowed: bool, message: string, remaining: int, retry_after: int}
     */
    public function check(int $domainId, string $userId, string $ipAddress): array
    {
        if (!$this->config['enabled']) {
            return ['allowed' => true, 'message' => '', 'remaining' => 999, 'retry_after' => 0];
        }

        try {
            return $this->doCheck($domainId, $userId, $ipAddress);
        } catch (\Throwable $e) {
            // DB 오류(테이블 미존재/연결 실패 등)로 rate limit을 확인할 수 없는 경우.
            // 로그인 자체는 막지 않되(가용성 — 한 번의 DB 장애로 전체 로그인이 잠기지 않도록),
            // 브루트포스 보호가 '조용히' 꺼지지 않게 반드시 로깅한다.
            error_log('[SECURITY] LoginAttemptService::check 실패 — 브루트포스 보호 일시 비활성: ' . $e->getMessage());
            return ['allowed' => true, 'message' => '', 'remaining' => 999, 'retry_after' => 0];
        }
    }

    private function doCheck(int $domainId, string $userId, string $ipAddress): array
    {
        $this->maybeCleanup();

        $window = $this->config['decay_seconds'];
        $since = date('Y-m-d H:i:s', time() - $window);

        // 계정+IP 기반 확인 — 잠금을 (계정, IP) 단위로 좁힌다.
        // 계정만으로 집계하면 공격자가 임의 IP로 피해자 계정에 실패를 쌓아 '정상 사용자까지' 잠그는
        // DoS 가 된다. IP 를 결합하면 공격자 IP 만 잠기고 정상 사용자(다른 IP)는 영향받지 않는다.
        // IP 전역 남용은 아래 max_attempts_per_ip 가, 분산 브루트포스는 비밀번호 정책이 담당한다.
        $userAttempts = (int) $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM login_attempts
             WHERE domain_id = ? AND user_id = ? AND ip_address = ? AND attempted_at >= ? AND is_successful = 0",
            [$domainId, $userId, $ipAddress, $since]
        )['cnt'];

        $maxUser = $this->config['max_attempts_per_user'];
        if ($userAttempts >= $maxUser) {
            $retryAfter = $this->getRetryAfter($domainId, $userId, $ipAddress, $window);
            return [
                'allowed' => false,
                'message' => "로그인 시도가 너무 많습니다. {$this->formatSeconds($retryAfter)} 후 다시 시도해주세요.",
                'remaining' => 0,
                'retry_after' => $retryAfter,
            ];
        }

        // IP 기반 확인
        $ipAttempts = (int) $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM login_attempts
             WHERE ip_address = ? AND attempted_at >= ? AND is_successful = 0",
            [$ipAddress, $since]
        )['cnt'];

        $maxIp = $this->config['max_attempts_per_ip'];
        if ($ipAttempts >= $maxIp) {
            $retryAfter = $this->getRetryAfter(null, null, $ipAddress, $window);
            return [
                'allowed' => false,
                'message' => "요청이 너무 많습니다. {$this->formatSeconds($retryAfter)} 후 다시 시도해주세요.",
                'remaining' => 0,
                'retry_after' => $retryAfter,
            ];
        }

        return [
            'allowed' => true,
            'message' => '',
            'remaining' => $maxUser - $userAttempts,
            'retry_after' => 0,
        ];
    }

    /**
     * 로그인 시도 기록
     */
    public function record(int $domainId, string $userId, string $ipAddress, bool $success): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        try {
            $this->doRecord($domainId, $userId, $ipAddress, $success);
        } catch (\Throwable $e) {
            // 기록 실패 시 해당 시도가 카운트되지 않아 보호가 약화되므로 로깅한다.
            error_log('[SECURITY] LoginAttemptService::record 실패 — 시도 미집계: ' . $e->getMessage());
        }
    }

    private function doRecord(int $domainId, string $userId, string $ipAddress, bool $success): void
    {
        $this->db->insert(
            "INSERT INTO login_attempts (domain_id, user_id, ip_address, is_successful, attempted_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$domainId, $userId, $ipAddress, $success ? 1 : 0]
        );

        // 성공 시 해당 계정의 실패 기록 초기화
        if ($success) {
            $this->clearFailedAttempts($domainId, $userId);
        }
    }

    /**
     * 특정 계정의 실패 기록 초기화 (로그인 성공 시)
     */
    public function clearFailedAttempts(int $domainId, string $userId): void
    {
        try {
            $this->db->execute(
                "DELETE FROM login_attempts WHERE domain_id = ? AND user_id = ? AND is_successful = 0",
                [$domainId, $userId]
            );
        } catch (\Throwable $e) {
            error_log('LoginAttemptService::clearFailedAttempts 실패: ' . $e->getMessage());
        }
    }

    /**
     * 가장 오래된 시도 기준 남은 대기 시간 계산
     */
    private function getRetryAfter(?int $domainId, ?string $userId, ?string $ipAddress, int $window): int
    {
        if ($userId !== null && $domainId !== null && $ipAddress !== null) {
            $oldest = $this->db->selectOne(
                "SELECT MIN(attempted_at) as oldest FROM login_attempts
                 WHERE domain_id = ? AND user_id = ? AND ip_address = ? AND attempted_at >= ? AND is_successful = 0",
                [$domainId, $userId, $ipAddress, date('Y-m-d H:i:s', time() - $window)]
            );
        } else {
            $oldest = $this->db->selectOne(
                "SELECT MIN(attempted_at) as oldest FROM login_attempts
                 WHERE ip_address = ? AND attempted_at >= ? AND is_successful = 0",
                [$ipAddress, date('Y-m-d H:i:s', time() - $window)]
            );
        }

        // 잠금됐는데 가장 오래된 실패행이 없는 퇴화 케이스(정상적으론 발생 불가) —
        // 실제 해제 기준인 집계 창(decay_seconds)을 폴백으로 돌려 안내 시간을 일관되게 한다.
        if (!$oldest || !$oldest['oldest']) {
            return $window;
        }

        $oldestTime = strtotime($oldest['oldest']);
        $unlockAt = $oldestTime + $window;
        $remaining = $unlockAt - time();

        return max(1, $remaining);
    }

    /**
     * 초를 사람이 읽기 쉬운 형식으로 변환
     */
    private function formatSeconds(int $seconds): string
    {
        if ($seconds >= 60) {
            $minutes = (int) ceil($seconds / 60);
            return "{$minutes}분";
        }
        return "{$seconds}초";
    }

    /**
     * 확률적으로 오래된 기록 삭제 (GC)
     */
    private function maybeCleanup(): void
    {
        if (random_int(1, 100) <= $this->config['cleanup_probability']) {
            $cutoff = date('Y-m-d H:i:s', time() - 86400); // 24시간 이전
            $this->db->execute(
                "DELETE FROM login_attempts WHERE attempted_at < ?",
                [$cutoff]
            );
        }
    }
}
