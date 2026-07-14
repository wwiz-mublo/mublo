<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class LevelPricingRepository
{
    private Database $db;
    private string $table = 'shop_level_pricing';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function getByDomain(int $domainId): array
    {
        return $this->db->select(
            "SELECT lp.*, ml.level_name
             FROM {$this->table} lp
             LEFT JOIN member_levels ml ON ml.level_value = lp.level_value
             WHERE lp.domain_id = ?
             ORDER BY lp.level_value ASC",
            [$domainId]
        );
    }

    public function getByLevel(int $domainId, int $levelValue): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE domain_id = ? AND level_value = ?",
            [$domainId, $levelValue]
        ) ?: null;
    }

    public function upsert(int $domainId, int $levelValue, array $data): bool
    {
        $existing = $this->getByLevel($domainId, $levelValue);

        if ($existing) {
            $sets = [];
            $values = [];
            foreach ($data as $col => $val) {
                $sets[] = "`{$col}` = ?";
                $values[] = $val;
            }
            $values[] = $domainId;
            $values[] = $levelValue;

            return $this->db->execute(
                "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE domain_id = ? AND level_value = ?",
                $values
            ) >= 0;
        }

        $data['domain_id'] = $domainId;
        $data['level_value'] = $levelValue;
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $columnList = implode(', ', $columns);

        return $this->db->insert(
            "INSERT INTO {$this->table} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        ) > 0;
    }

    public function delete(int $domainId, int $levelValue): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE domain_id = ? AND level_value = ?",
            [$domainId, $levelValue]
        );
    }
}
