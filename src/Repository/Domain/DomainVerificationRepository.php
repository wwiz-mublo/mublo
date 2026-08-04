<?php
declare(strict_types=1);
namespace Mublo\Repository\Domain;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * Class DomainVerificationRepository
 *
 * domain_verifications 테이블 접근 Repository
 *
 * 책임:
 * - 호스트명 검증 요청(nonce) 생성/조회
 * - 검증 결과 기록
 * - 변경에 사용된 검증 소진(consume)
 *
 * Entity를 두지 않고 배열을 반환한다 — 검증 기록은 도메인 모델이 아니라
 * 감사/게이트용 로그성 데이터이며, 소비처가 Service 한 곳이다.
 */
class DomainVerificationRepository extends BaseRepository
{
    protected string $table = 'domain_verifications';
    protected string $primaryKey = 'verification_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 검증 요청 생성 (pending)
     *
     * @return int 생성된 verification_id
     */
    public function createPending(
        string $host,
        ?int $domainId,
        string $nonce,
        ?int $requestedBy,
        int $ttlSeconds
    ): int {
        return (int) $this->getDb()->insert(
            "INSERT INTO domain_verifications
                (domain_id, host, nonce, status, requested_by, expires_at)
             VALUES (?, ?, ?, 'pending', ?, ?)",
            [
                $domainId ?: null,
                $host,
                $nonce,
                $requestedBy ?: null,
                date('Y-m-d H:i:s', time() + $ttlSeconds),
            ]
        );
    }

    /**
     * 검증 결과 기록
     */
    public function saveResult(
        int $verificationId,
        string $status,
        string $verdict,
        string $message,
        array $dnsResult,
        array $probeResult
    ): void {
        $this->getDb()->execute(
            "UPDATE domain_verifications
                SET status = ?, verdict = ?, message = ?, dns_result = ?, probe_result = ?, checked_at = NOW()
              WHERE verification_id = ?",
            [
                $status,
                $verdict,
                mb_substr($message, 0, 255),
                json_encode($dnsResult, JSON_UNESCAPED_UNICODE),
                json_encode($probeResult, JSON_UNESCAPED_UNICODE),
                $verificationId,
            ]
        );
    }

    /**
     * 프로브 검증용: 해당 호스트로 발급된 유효한 pending nonce 존재 여부
     *
     * 프로브는 아직 등록되지 않은 호스트로도 들어오므로 domain_id는 보지 않는다.
     * nonce는 단일 UNIQUE 값이므로 호스트 일치까지 확인해야
     * 다른 호스트로 발급된 nonce를 재사용한 위조 응답을 막을 수 있다.
     */
    public function findLiveNonce(string $host, string $nonce): ?array
    {
        return $this->getDb()->selectOne(
            "SELECT * FROM domain_verifications
              WHERE nonce = ? AND host = ? AND status = 'pending' AND expires_at >= NOW()
              LIMIT 1",
            [$nonce, $host]
        );
    }

    /**
     * 변경 게이트용: 해당 호스트/도메인의 가장 최근 합격 검증 (미소진, 유효기간 내)
     */
    public function findUsablePassed(string $host, ?int $domainId): ?array
    {
        $sql = "SELECT * FROM domain_verifications
                 WHERE host = ? AND status = 'passed' AND expires_at >= NOW()";
        $params = [$host];

        if ($domainId !== null) {
            $sql .= " AND domain_id = ?";
            $params[] = $domainId;
        } else {
            $sql .= " AND domain_id IS NULL";
        }

        $sql .= " ORDER BY verification_id DESC LIMIT 1";

        return $this->getDb()->selectOne($sql, $params);
    }

    /**
     * 검증 소진 처리 (1회성 — 같은 검증으로 두 번 변경하지 못한다)
     *
     * 소진 시점에 감사 정보(직전 호스트명, 실행자)를 같은 UPDATE로 함께 남긴다.
     * 이 행만 보고 "언제, 누가, 무엇에서 무엇으로 바꿨나"를 읽을 수 있게 하기 위함이다.
     *
     * @param string|null $previousHost 변경 직전 호스트명
     * @param int|null $consumedBy 변경을 실행한 관리자 회원 ID (requested_by와 다를 수 있음)
     * @return bool 이 호출이 실제로 소진시켰으면 true (경합 시 한 번만 true)
     */
    public function consume(int $verificationId, ?string $previousHost = null, ?int $consumedBy = null): bool
    {
        $affected = $this->getDb()->execute(
            "UPDATE domain_verifications
                SET status = 'consumed', consumed_at = NOW(), previous_host = ?, consumed_by = ?
              WHERE verification_id = ? AND status = 'passed'",
            [$previousHost ?: null, $consumedBy ?: null, $verificationId]
        );

        return $affected > 0;
    }

    /**
     * 실제 변경 이력 조회 (확인에서 끝난 기록은 제외)
     *
     * status='consumed'만 본다 — passed/failed/pending은 "확인만 해본 것"이라
     * 변경 이력이 아니다. 이 구분이 흐려지면 감사 목적이 사라진다.
     *
     * @return array<int,array<string,mixed>> 최신순
     */
    public function findChangeHistory(int $domainId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return $this->getDb()->select(
            "SELECT verification_id, host, previous_host, verdict, requested_by, consumed_by, consumed_at
               FROM domain_verifications
              WHERE domain_id = ? AND status = 'consumed'
              ORDER BY consumed_at DESC, verification_id DESC
              LIMIT {$limit}",
            [$domainId]
        );
    }

    /**
     * 만료된 pending 정리 (검증 요청 시 호출 — 테이블 무한 증식 방지)
     *
     * 합격/실패/소진 기록은 감사 목적으로 남기고, 응답 없이 만료된
     * pending 행만 지운다.
     */
    public function purgeExpiredPending(): int
    {
        return $this->getDb()->execute(
            "DELETE FROM domain_verifications WHERE status = 'pending' AND expires_at < NOW()"
        );
    }
}
