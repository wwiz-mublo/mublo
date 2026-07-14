<?php

namespace Mublo\Plugin\PayApp\Repository;

use Mublo\Infrastructure\Database\Database;

class PayAppConfigRepository
{
    private Database $db;
    private string $table = 'plugin_payapp_configs';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $domainId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE domain_id = ?",
            [$domainId]
        ) ?: null;
    }

    public function save(int $domainId, array $configData): void
    {
        $existing = $this->find($domainId);
        $json = json_encode($configData, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->update(['config_data' => $json]);
        } else {
            $this->db->table($this->table)->insert([
                'domain_id' => $domainId,
                'config_data' => $json,
            ]);
        }
    }
}
