<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\Shop\Entity\Exhibition;

class ExhibitionRepository
{
    private Database $db;
    private string $table      = 'shop_exhibitions';
    private string $itemTable  = 'shop_exhibition_items';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function findInDomain(int $domainId, int $id): ?Exhibition
    {
        $row = $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE exhibition_id = ? AND domain_id = ?",
            [$id, $domainId]
        );
        return $row ? Exhibition::fromArray($row) : null;
    }

    public function findBySlug(int $domainId, string $slug): ?Exhibition
    {
        $row = $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE domain_id = ? AND slug = ?",
            [$domainId, $slug]
        );
        return $row ? Exhibition::fromArray($row) : null;
    }

    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['domain_id = ?'];
        $params = [$domainId];

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['keyword'])) {
            $where[]  = 'title LIKE ?';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $totalItems = (int) $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE {$whereClause}",
            $params
        )['cnt'];

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $offset     = ($page - 1) * $perPage;

        $items = $this->db->select(
            "SELECT * FROM {$this->table}
             WHERE {$whereClause}
             ORDER BY sort_order ASC, exhibition_id DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        return [
            'items'      => array_map(fn($r) => Exhibition::fromArray($r), $items),
            'pagination' => [
                'totalItems'  => $totalItems,
                'perPage'     => $perPage,
                'currentPage' => $page,
                'totalPages'  => $totalPages,
            ],
        ];
    }

    /** 진행 중인 기획전 목록 (프론트용) */
    public function getActiveList(int $domainId): array
    {
        $now  = date('Y-m-d H:i:s');
        $rows = $this->db->select(
            "SELECT * FROM {$this->table}
             WHERE domain_id = ?
               AND is_active = 1
               AND (start_date IS NULL OR start_date <= ?)
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY sort_order ASC, exhibition_id DESC",
            [$domainId, $now, $now]
        );
        return array_map(fn($r) => Exhibition::fromArray($r), $rows);
    }

    public function create(array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $columnList   = implode(', ', $columns);

        return $this->db->insert(
            "INSERT INTO {$this->table} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function updateInDomain(int $domainId, int $id, array $data): int
    {
        unset($data['domain_id']);
        $sets   = [];
        $values = [];
        foreach ($data as $col => $val) {
            $sets[]   = "`{$col}` = ?";
            $values[] = $val;
        }
        $values[] = $id;
        $values[] = $domainId;

        return $this->db->execute(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE exhibition_id = ? AND domain_id = ?",
            $values
        );
    }

    public function deleteInDomain(int $domainId, int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE exhibition_id = ? AND domain_id = ?",
            [$id, $domainId]
        );
    }

    // -------------------------------------------------------------------------
    // 기획전 아이템
    // -------------------------------------------------------------------------

    public function getItems(int $exhibitionId): array
    {
        return $this->db->select(
            "SELECT ei.*, p.goods_name, p.display_price,
                    (SELECT image_url FROM shop_product_images pi
                     WHERE pi.goods_id = ei.goods_id ORDER BY sort_order LIMIT 1) AS product_image
             FROM {$this->itemTable} ei
             LEFT JOIN shop_products p ON p.goods_id = ei.goods_id AND ei.target_type = 'goods'
             WHERE ei.exhibition_id = ?
             ORDER BY ei.sort_order ASC, ei.item_id ASC",
            [$exhibitionId]
        );
    }

    /**
     * 여러 기획전의 연결 아이템 개수를 타입별(goods/category)로 배치 집계.
     *
     * @param int[] $exhibitionIds
     * @return array<int, array{goods:int, category:int}>
     */
    public function getItemCountsByExhibitionIds(array $exhibitionIds): array
    {
        if (empty($exhibitionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($exhibitionIds), '?'));
        $rows = $this->db->select(
            "SELECT exhibition_id, target_type, COUNT(*) AS cnt
             FROM {$this->itemTable}
             WHERE exhibition_id IN ({$placeholders})
             GROUP BY exhibition_id, target_type",
            array_values($exhibitionIds)
        );

        $counts = [];
        foreach ($rows as $r) {
            $eid = (int) $r['exhibition_id'];
            if (!isset($counts[$eid])) {
                $counts[$eid] = ['goods' => 0, 'category' => 0];
            }
            $type = ($r['target_type'] === 'category') ? 'category' : 'goods';
            $counts[$eid][$type] = (int) $r['cnt'];
        }

        return $counts;
    }

    public function addItem(array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $columnList   = implode(', ', $columns);

        return $this->db->insert(
            "INSERT INTO {$this->itemTable} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function deleteItemInDomain(int $domainId, int $itemId): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->itemTable}
             WHERE item_id = ? AND exhibition_id IN (
                 SELECT exhibition_id FROM {$this->table} WHERE domain_id = ?
             )",
            [$itemId, $domainId]
        );
    }

    public function deleteItemsByExhibition(int $exhibitionId): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->itemTable} WHERE exhibition_id = ?",
            [$exhibitionId]
        );
    }

    /** slug 중복 확인 */
    public function slugExists(int $domainId, string $slug, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE domain_id = ? AND slug = ?";
        $params = [$domainId, $slug];
        if ($excludeId !== null) {
            $sql    .= ' AND exhibition_id != ?';
            $params[] = $excludeId;
        }
        return (int) $this->db->selectOne($sql, $params)['cnt'] > 0;
    }
}
