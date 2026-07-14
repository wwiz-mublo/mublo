<?php
namespace Mublo\Plugin\MemberPoint\Repository;

use Mublo\Infrastructure\Database\Database;

class MemberPointConfigRepository
{
    private string $table = 'plugin_member_point_configs';

    public function __construct(private Database $db) {}

    // =========================================================================
    // 도메인 기본 설정
    // =========================================================================

    public function findByDomain(int $domainId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first();

        if (!$row) return null;

        $config = json_decode($row['config_data'], true);
        return is_array($config) ? $config : null;
    }

    public function save(int $domainId, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $now  = date('Y-m-d H:i:s');

        $this->db->table($this->table)->insertOrUpdate(
            ['domain_id' => $domainId, 'config_data' => $json, 'updated_at' => $now],
            ['config_data' => $json, 'updated_at' => $now]
        );
    }
}
