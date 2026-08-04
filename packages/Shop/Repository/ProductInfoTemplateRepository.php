<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;

class ProductInfoTemplateRepository
{
    private Database $db;
    private string $table = 'shop_product_info_templates';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findInDomain(int $domainId, int $id): ?array
    {
        return $this->db->table($this->table)
            ->where('template_id', '=', $id)
            ->where('domain_id', '=', $domainId)
            ->first() ?: null;
    }

    public function getListPaginated(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['t.domain_id = ?'];
        $params = [$domainId];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(t.subject LIKE ? OR t.tab_name LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereClause = implode(' AND ', $where);
        $totalItems = (int) $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} t WHERE {$whereClause}",
            $params
        )['cnt'];

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->select(
            "SELECT t.*, ci.name AS category_name
             FROM {$this->table} t
             LEFT JOIN shop_category_items ci ON ci.category_code = t.category_code
             WHERE {$whereClause}
             ORDER BY t.sort_order ASC, t.template_id DESC
             LIMIT ? OFFSET ?",
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

    public function getActive(int $domainId): array
    {
        return $this->db->select(
            "SELECT * FROM {$this->table} WHERE domain_id = ? AND status = 'Y' ORDER BY sort_order ASC, template_id ASC",
            [$domainId]
        );
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

    public function updateInDomain(int $domainId, int $id, array $data): int
    {
        unset($data['domain_id']);
        $sets = [];
        $values = [];
        foreach ($data as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $values[] = $val;
        }
        $values[] = $id;
        $values[] = $domainId;

        return $this->db->execute(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE template_id = ? AND domain_id = ?",
            $values
        );
    }

    public function deleteInDomain(int $domainId, int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE template_id = ? AND domain_id = ?",
            [$id, $domainId]
        );
    }
}
