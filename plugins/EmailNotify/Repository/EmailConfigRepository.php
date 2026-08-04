<?php
declare(strict_types=1);

namespace Mublo\Plugin\EmailNotify\Repository;

use Mublo\Infrastructure\Database\Database;

class EmailConfigRepository
{
    private string $table = 'plugin_email_notify_configs';

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
            'from_name' => $data['from_name'] ?? '',
            'from_email' => $data['from_email'] ?? '',
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->db->table($this->table)->insertOrUpdate(
            $payload,
            [
                'from_name' => $payload['from_name'],
                'from_email' => $payload['from_email'],
                'is_active' => $payload['is_active'],
                'updated_at' => $now,
            ]
        );

        return true;
    }
}
