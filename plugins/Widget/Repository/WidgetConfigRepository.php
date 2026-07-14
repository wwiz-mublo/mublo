<?php

namespace Mublo\Plugin\Widget\Repository;

use Mublo\Infrastructure\Database\Database;

class WidgetConfigRepository
{
    private string $table = 'plugin_widget_configs';

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
            'left_enabled' => (int) ($data['left_enabled'] ?? 0),
            'right_enabled' => (int) ($data['right_enabled'] ?? 0),
            'mobile_enabled' => (int) ($data['mobile_enabled'] ?? 0),
            'left_skin' => $data['left_skin'] ?? 'basic',
            'right_skin' => $data['right_skin'] ?? 'basic',
            'mobile_skin' => $data['mobile_skin'] ?? 'basic',
            'left_width' => (int) ($data['left_width'] ?? 50),
            'right_width' => (int) ($data['right_width'] ?? 50),
            'mobile_width' => (int) ($data['mobile_width'] ?? 40),
            'updated_at' => $now,
        ];

        $existing = $this->findByDomainId($domainId);

        if ($existing) {
            unset($payload['domain_id']);
            $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->update($payload);
        } else {
            $this->db->table($this->table)->insert($payload);
        }

        return true;
    }
}
