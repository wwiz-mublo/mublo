<?php

namespace Mublo\Repository\Member;

use Mublo\Infrastructure\Database\Database;

/**
 * PasswordResetTokenRepository
 *
 * 비밀번호 재설정 토큰 데이터 접근
 *
 * 책임:
 * - password_reset_tokens 테이블 CRUD
 * - Rate limiting 카운트 쿼리
 * - 만료 토큰 정리
 */
class PasswordResetTokenRepository
{
    private Database $db;
    private string $table = 'password_reset_tokens';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 토큰 생성
     */
    public function create(
        int $domainId,
        int $memberId,
        string $tokenHash,
        string $email,
        ?string $ipAddress,
        string $expiresAt
    ): int {
        return $this->db->insert(
            "INSERT INTO {$this->table} (domain_id, member_id, token_hash, email, ip_address, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$domainId, $memberId, $tokenHash, $email, $ipAddress, $expiresAt]
        );
    }

    /**
     * 토큰 해시로 조회 (미사용 + 미만료)
     */
    public function findValidByTokenHash(string $tokenHash): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM {$this->table}
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()",
            [$tokenHash]
        );
    }

    /**
     * 토큰 사용 처리
     */
    public function markUsed(int $tokenId): bool
    {
        return $this->db->execute(
            "UPDATE {$this->table} SET used_at = NOW() WHERE token_id = ?",
            [$tokenId]
        ) > 0;
    }

    /**
     * 회원의 미사용 토큰 전체 무효화
     */
    public function invalidateByMember(int $memberId): int
    {
        return $this->db->execute(
            "UPDATE {$this->table} SET used_at = NOW() WHERE member_id = ? AND used_at IS NULL",
            [$memberId]
        );
    }

    /**
     * 만료된 토큰 삭제 (가비지 컬렉션)
     */
    public function deleteExpired(): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE expires_at < NOW()"
        );
    }

    /**
     * 최근 N초 내 해당 이메일의 토큰 생성 횟수
     */
    public function countRecentByEmail(int $domainId, string $email, int $seconds): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM {$this->table}
             WHERE domain_id = ? AND email = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$domainId, $email, $seconds]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * 최근 N초 내 해당 IP의 토큰 생성 횟수
     */
    public function countRecentByIp(string $ipAddress, int $seconds): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM {$this->table}
             WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ipAddress, $seconds]
        );

        return (int) ($row['cnt'] ?? 0);
    }
}
