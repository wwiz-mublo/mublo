<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * BoardPointConfigRepository
 *
 * 게시판 포인트 설정 DB 접근
 */
class BoardPointConfigRepository
{
    private string $table = 'board_point_configs';
    private string $scopeTable = 'board_point_scope_configs';

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
        $now = date('Y-m-d H:i:s');

        $this->db->table($this->table)->insertOrUpdate(
            ['domain_id' => $domainId, 'config_data' => $json, 'updated_at' => $now],
            ['config_data' => $json, 'updated_at' => $now]
        );
    }

    // =========================================================================
    // 스코프별 설정 (그룹/게시판)
    // =========================================================================

    public function findScopeConfig(int $domainId, string $scopeType, int $scopeId): ?array
    {
        $row = $this->db->table($this->scopeTable)
            ->where('domain_id', '=', $domainId)
            ->where('scope_type', '=', $scopeType)
            ->where('scope_id', '=', $scopeId)
            ->first();

        if (!$row) return null;

        $config = json_decode($row['config_data'], true);
        return is_array($config) ? $config : null;
    }

    public function saveScopeConfig(int $domainId, string $scopeType, int $scopeId, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');

        $this->db->table($this->scopeTable)->insertOrUpdate(
            [
                'domain_id' => $domainId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'config_data' => $json,
                'updated_at' => $now,
            ],
            ['config_data' => $json, 'updated_at' => $now]
        );
    }

    public function deleteScopeConfig(int $domainId, string $scopeType, int $scopeId): void
    {
        $this->db->table($this->scopeTable)
            ->where('domain_id', '=', $domainId)
            ->where('scope_type', '=', $scopeType)
            ->where('scope_id', '=', $scopeId)
            ->delete();
    }

    /**
     * @return array [scope_id => config_data, ...]
     */
    public function findAllScopeConfigs(int $domainId, string $scopeType): array
    {
        $rows = $this->db->table($this->scopeTable)
            ->where('domain_id', '=', $domainId)
            ->where('scope_type', '=', $scopeType)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $config = json_decode($row['config_data'], true);
            if (is_array($config)) {
                $result[(int) $row['scope_id']] = $config;
            }
        }
        return $result;
    }
}
