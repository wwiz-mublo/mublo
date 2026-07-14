<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class PointLogRepository
{
    private Database $db;
    private string $table = 'shop_point_logs';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function create(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $columnList = implode(', ', $columns);

        return $this->db->insert(
            "INSERT INTO {$this->table} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function getBalance(int $domainId, int $memberId): int
    {
        $row = $this->db->selectOne(
            "SELECT balance FROM {$this->table}
             WHERE domain_id = ? AND member_id = ?
             ORDER BY log_id DESC
             LIMIT 1",
            [$domainId, $memberId]
        );
        return (int) ($row['balance'] ?? 0);
    }

    public function getMemberLogs(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        $totalItems = (int) $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE domain_id = ? AND member_id = ?",
            [$domainId, $memberId]
        )['cnt'];

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->select(
            "SELECT * FROM {$this->table}
             WHERE domain_id = ? AND member_id = ?
             ORDER BY log_id DESC
             LIMIT ? OFFSET ?",
            [$domainId, $memberId, $perPage, $offset]
        );

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['domain_id = ?'];
        $params = [$domainId];

        if (!empty($filters['member_id'])) {
            $where[] = 'member_id = ?';
            $params[] = (int) $filters['member_id'];
        }
        if (!empty($filters['point_type'])) {
            $where[] = 'point_type = ?';
            $params[] = $filters['point_type'];
        }
        if (!empty($filters['reason_type'])) {
            $where[] = 'reason_type = ?';
            $params[] = $filters['reason_type'];
        }

        $whereClause = implode(' AND ', $where);

        $totalItems = (int) $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE {$whereClause}",
            $params
        )['cnt'];

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->select(
            "SELECT * FROM {$this->table} WHERE {$whereClause} ORDER BY log_id DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public function expirePoints(int $domainId): int
    {
        $today = date('Y-m-d');
        return $this->db->execute(
            "UPDATE {$this->table} SET is_expired = 1
             WHERE domain_id = ? AND expire_date <= ? AND is_expired = 0 AND point_type = 'EARN'",
            [$domainId, $today]
        );
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        // shop_point_logs doesn't have idempotency_key — check by order_no + reason_type
        return null;
    }
}
