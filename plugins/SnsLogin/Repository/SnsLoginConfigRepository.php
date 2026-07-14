<?php
namespace Mublo\Plugin\SnsLogin\Repository;

use Mublo\Infrastructure\Database\Database;

class SnsLoginConfigRepository
{
    private string $table = 'plugin_sns_login_configs';

    public function __construct(private Database $db) {}

    public function findByDomain(int $domainId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first();

        if (!$row) return null;

        $config = json_decode($row['config'], true);
        return is_array($config) ? $config : null;
    }

    public function save(int $domainId, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $now  = date('Y-m-d H:i:s');

        $this->db->table($this->table)->insertOrUpdate(
            ['domain_id' => $domainId, 'config' => $json, 'updated_at' => $now],
            ['config' => $json, 'updated_at' => $now]
        );
    }
}
