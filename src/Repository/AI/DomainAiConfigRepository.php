<?php
namespace Mublo\Repository\AI;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class DomainAiConfigRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function findByDomainId(int $domainId): ?array
    {
        return $this->db->selectOne(
            'SELECT domain_id, provider, model, encrypted_api_key, is_enabled, daily_request_limit, created_at, updated_at
               FROM domain_ai_configs WHERE domain_id = ?',
            [$domainId]
        );
    }

    public function save(int $domainId, array $data): void
    {
        $this->db->execute(
            'INSERT INTO domain_ai_configs
                (domain_id, provider, model, encrypted_api_key, is_enabled, daily_request_limit)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider), model = VALUES(model),
                encrypted_api_key = VALUES(encrypted_api_key), is_enabled = VALUES(is_enabled),
                daily_request_limit = VALUES(daily_request_limit)',
            [
                $domainId, $data['provider'], $data['model'], $data['encrypted_api_key'],
                $data['is_enabled'] ? 1 : 0, $data['daily_request_limit'],
            ]
        );
    }

    public function removeKey(int $domainId): void
    {
        $this->db->execute(
            'UPDATE domain_ai_configs SET encrypted_api_key = NULL, is_enabled = 0 WHERE domain_id = ?',
            [$domainId]
        );
    }
}
