<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;

class WishlistRepository
{
    private Database $db;
    private string $table = 'shop_wishlist';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findInDomain(int $domainId, int $memberId, int $goodsId): ?array
    {
        return $this->db->selectOne(
            "SELECT w.* FROM {$this->table} w
             INNER JOIN shop_products p ON p.goods_id = w.goods_id
             WHERE w.member_id = ? AND w.goods_id = ? AND p.domain_id = ?",
            [$memberId, $goodsId, $domainId]
        ) ?: null;
    }

    public function create(int $memberId, int $goodsId): int
    {
        return $this->db->insert(
            "INSERT IGNORE INTO {$this->table} (member_id, goods_id) VALUES (?, ?)",
            [$memberId, $goodsId]
        );
    }

    public function deleteInDomain(int $domainId, int $memberId, int $goodsId): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table}
             WHERE member_id = ? AND goods_id = ?
               AND goods_id IN (SELECT goods_id FROM shop_products WHERE domain_id = ?)",
            [$memberId, $goodsId, $domainId]
        );
    }

    public function getMemberWishlist(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        $totalItems = (int) $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} w
             INNER JOIN shop_products p ON p.goods_id = w.goods_id
             WHERE w.member_id = ? AND p.domain_id = ?",
            [$memberId, $domainId]
        )['cnt'];

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->select(
            "SELECT w.*, p.goods_name, p.display_price, p.discount_type, p.discount_value,
                    p.is_active, p.stock_quantity, p.option_mode,
                    (SELECT image_url FROM shop_product_images pi WHERE pi.goods_id = w.goods_id ORDER BY sort_order LIMIT 1) AS main_image
             FROM {$this->table} w
             LEFT JOIN shop_products p ON p.goods_id = w.goods_id
             WHERE w.member_id = ? AND p.domain_id = ?
             ORDER BY w.wishlist_id DESC
             LIMIT ? OFFSET ?",
            [$memberId, $domainId, $perPage, $offset]
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

    public function countByGoodsId(int $goodsId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE goods_id = ?",
            [$goodsId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    public function countByGoodsIds(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $rows = $this->db->select(
            "SELECT goods_id, COUNT(*) AS cnt FROM {$this->table}
             WHERE goods_id IN ({$placeholders})
             GROUP BY goods_id",
            array_map('intval', $goodsIds)
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['goods_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    public function getMemberGoodsIds(int $domainId, int $memberId): array
    {
        $rows = $this->db->select(
            "SELECT w.goods_id FROM {$this->table} w
             INNER JOIN shop_products p ON p.goods_id = w.goods_id
             WHERE w.member_id = ? AND p.domain_id = ?",
            [$memberId, $domainId]
        );
        return array_column($rows, 'goods_id');
    }

    /**
     * 관리자 전체 찜 목록 (회원·상품 join + 페이지네이션)
     *
     * @param array $filters keyword (회원 user_id 부분일치)
     */
    public function getAdminList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = ['p.domain_id = ?'];
        $params = [$domainId];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(m.user_id LIKE ? OR p.goods_name LIKE ?)';
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $totalRow = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt
             FROM {$this->table} w
             LEFT JOIN shop_products p ON p.goods_id = w.goods_id
             LEFT JOIN members m ON m.member_id = w.member_id
             {$whereSql}",
            $params
        );
        $totalItems = (int) ($totalRow['cnt'] ?? 0);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));

        $items = $this->db->select(
            "SELECT w.wishlist_id, w.member_id, w.goods_id, w.created_at,
                    m.user_id, m.nickname,
                    p.goods_name, p.goods_slug, p.is_active, p.display_price
             FROM {$this->table} w
             LEFT JOIN shop_products p ON p.goods_id = w.goods_id
             LEFT JOIN members m ON m.member_id = w.member_id
             {$whereSql}
             ORDER BY w.wishlist_id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
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
}
