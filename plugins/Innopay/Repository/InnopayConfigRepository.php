<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Repository;

use Mublo\Infrastructure\Database\Database;

class InnopayConfigRepository
{
    private string $table = 'plugin_innopay_configs';

    public function __construct(private Database $db)
    {
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
        $json = json_encode($configData, JSON_UNESCAPED_UNICODE);

        if ($this->find($domainId)) {
            $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->update(['config_data' => $json]);
            return;
        }

        $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'config_data' => $json,
        ]);
    }
}
