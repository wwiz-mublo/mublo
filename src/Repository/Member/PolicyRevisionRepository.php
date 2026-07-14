<?php

namespace Mublo\Repository\Member;

use Mublo\Infrastructure\Database\Database;

class PolicyRevisionRepository
{
    private const TABLE = 'policy_revisions';

    public function __construct(private readonly Database $db)
    {
    }

    public function create(array $data): int
    {
        return (int) $this->db->table(self::TABLE)->insert($data);
    }

    public function findById(int $revisionId): ?array
    {
        $row = $this->db->table(self::TABLE)
            ->where('revision_id', '=', $revisionId)
            ->first();

        return $row ?: null;
    }

    public function findCurrentForPolicy(int $policyId): ?array
    {
        return $this->db->selectOne(
            'SELECT r.* FROM policies p'
            . ' INNER JOIN ' . self::TABLE . ' r ON r.revision_id = p.current_revision_id'
            . ' WHERE p.policy_id = ?',
            [$policyId]
        );
    }

    /** @return array<int, array<string, mixed>> policy_id keyed */
    public function findCurrentForPolicies(array $policyIds): array
    {
        $policyIds = array_values(array_unique(array_filter(array_map('intval', $policyIds))));
        if ($policyIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($policyIds), '?'));
        $rows = $this->db->select(
            'SELECT r.* FROM policies p'
            . ' INNER JOIN ' . self::TABLE . ' r ON r.revision_id = p.current_revision_id'
            . " WHERE p.policy_id IN ({$placeholders})",
            $policyIds
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['policy_id']] = $row;
        }

        return $result;
    }

    public function getDb(): Database
    {
        return $this->db;
    }
}
