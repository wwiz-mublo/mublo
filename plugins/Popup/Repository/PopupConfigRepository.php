<?php

namespace Mublo\Plugin\Popup\Repository;

use Mublo\Infrastructure\Database\Database;

class PopupConfigRepository
{
    private string $table = 'plugin_popup_configs';

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
        $existing = $this->findByDomainId($domainId);

        $payload = [
            'popup_skin' => $data['popup_skin'] ?? 'basic',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->update($payload);
        } else {
            $payload['domain_id'] = $domainId;
            $this->db->table($this->table)->insert($payload);
        }

        return true;
    }
}
