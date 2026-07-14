<?php

namespace Mublo\Plugin\SendonSms\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonSmsConfigRepository
{
    private string $table = 'plugin_sendon_sms_configs';

    public function __construct(private Database $db)
    {
    }

    public function findByDomainId(int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first() ?: null;
    }

    public function upsert(int $domainId, array $data): bool
    {
        $now = date('Y-m-d H:i:s');

        $payload = [
            'domain_id' => $domainId,
            'api_id' => $data['api_id'] ?? '',
            'api_password' => $data['api_password'] ?? '',
            'default_sender' => $data['default_sender'] ?? '',
            'test_mode' => (int) ($data['test_mode'] ?? 1),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->db->table($this->table)->insertOrUpdate(
            $payload,
            [
                'api_id' => $payload['api_id'],
                'api_password' => $payload['api_password'],
                'default_sender' => $payload['default_sender'],
                'test_mode' => $payload['test_mode'],
                'is_active' => $payload['is_active'],
                'updated_at' => $now,
            ]
        );

        return true;
    }
}
