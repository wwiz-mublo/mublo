<?php

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorCampaignKeyRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_campaign_keys';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 도메인별 전체 키 목록
     */
    public function getAll(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * 도메인별 활성 키 목록
     */
    public function getActiveKeys(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->get();
    }

    /**
     * 키로 조회
     */
    public function findByKey(int $domainId, string $campaignKey): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('campaign_key', '=', $campaignKey)
            ->first();
    }

    /**
     * ID로 조회 (domainId 전달 시 도메인 경계 보장)
     */
    public function find(int $keyId, ?int $domainId = null): ?array
    {
        $query = $this->db->table($this->table)
            ->where('key_id', '=', $keyId);

        if ($domainId !== null) {
            $query->where('domain_id', '=', $domainId);
        }

        return $query->first();
    }

    /**
     * 키 추가
     */
    public function create(array $data): int
    {
        return $this->db->table($this->table)->insert($data);
    }

    /**
     * 키 수정 (도메인 경계 보장)
     */
    public function update(int $keyId, int $domainId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('key_id', '=', $keyId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    /**
     * 키 삭제 (도메인 경계 보장)
     */
    public function delete(int $keyId, int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('key_id', '=', $keyId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 그룹 목록 (distinct)
     */
    public function getGroups(int $domainId): array
    {
        $table = $this->table;
        $sql = "SELECT DISTINCT `group_name` FROM `{$table}`
                WHERE `domain_id` = ? AND `group_name` != ''
                ORDER BY `group_name` ASC";

        return $this->db->select($sql, [$domainId]);
    }
}
