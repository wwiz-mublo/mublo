<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Support\Time;

final class IdempotencyRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $companyId, string $endpoint, string $keyHash): ?array
    {
        return $this->db->selectOne(
            'SELECT request_hash, response_json, response_status
               FROM ai_idempotency_keys
              WHERE company_id = ? AND endpoint = ? AND key_hash = ? AND expires_at > ?
              LIMIT 1',
            [$companyId, $endpoint, $keyHash, Time::database()]
        );
    }

    public function store(
        string $companyId,
        string $endpoint,
        string $keyHash,
        string $requestHash,
        string $responseJson,
        int $responseStatus,
        int $expiresAt
    ): void {
        $this->db->insert(
            'INSERT INTO ai_idempotency_keys
                (company_id, endpoint, key_hash, request_hash, response_json,
                 response_status, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $companyId,
                $endpoint,
                $keyHash,
                $requestHash,
                $responseJson,
                $responseStatus,
                Time::database($expiresAt),
                Time::database(),
            ]
        );
    }
}
