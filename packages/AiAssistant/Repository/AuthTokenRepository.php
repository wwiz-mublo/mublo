<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Support\Time;

final class AuthTokenRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(
        string $tokenId,
        string $companyId,
        string $userId,
        string $accessHash,
        string $refreshHash,
        int $accessExpiresAt,
        int $refreshExpiresAt
    ): void {
        $this->db->insert(
            'INSERT INTO ai_auth_tokens
                (token_id, company_id, user_id, access_hash, refresh_hash,
                 access_expires_at, refresh_expires_at, revoked_at, rotated_to_token_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)',
            [
                $tokenId,
                $companyId,
                $userId,
                $accessHash,
                $refreshHash,
                Time::database($accessExpiresAt),
                Time::database($refreshExpiresAt),
                Time::database(),
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveAccess(string $accessHash): ?array
    {
        return $this->db->selectOne(
            "SELECT t.token_id, t.company_id, t.user_id, u.login_id, u.nickname, u.role
               FROM ai_auth_tokens t
               INNER JOIN ai_company_users u
                       ON u.user_id = t.user_id AND u.company_id = t.company_id
               INNER JOIN ai_companies c ON c.company_id = t.company_id
              WHERE t.access_hash = ?
                AND t.revoked_at IS NULL
                AND t.access_expires_at > ?
                AND u.status = 'ACTIVE'
                AND c.status = 'ACTIVE'
              LIMIT 1",
            [$accessHash, Time::database()]
        );
    }

    /** @return array<string, mixed>|null */
    public function findRefresh(string $refreshHash): ?array
    {
        return $this->db->selectOne(
            "SELECT t.token_id, t.company_id, t.user_id, t.revoked_at, t.refresh_expires_at,
                    u.login_id, u.nickname, u.role, u.status AS user_status, c.status AS company_status
               FROM ai_auth_tokens t
               INNER JOIN ai_company_users u
                       ON u.user_id = t.user_id AND u.company_id = t.company_id
               INNER JOIN ai_companies c ON c.company_id = t.company_id
              WHERE t.refresh_hash = ?
              LIMIT 1",
            [$refreshHash]
        );
    }

    public function consumeForRotation(string $tokenId, string $rotatedToTokenId): bool
    {
        return $this->db->execute(
            'UPDATE ai_auth_tokens
                SET revoked_at = ?, rotated_to_token_id = ?
              WHERE token_id = ? AND revoked_at IS NULL AND refresh_expires_at > ?',
            [Time::database(), $rotatedToTokenId, $tokenId, Time::database()]
        ) === 1;
    }

    public function revokeAccess(string $accessHash): bool
    {
        return $this->db->execute(
            'UPDATE ai_auth_tokens SET revoked_at = ? WHERE access_hash = ? AND revoked_at IS NULL',
            [Time::database(), $accessHash]
        ) === 1;
    }

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }
}
