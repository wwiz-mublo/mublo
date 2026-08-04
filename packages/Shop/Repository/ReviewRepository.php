<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;

class ReviewRepository
{
    private Database $db;
    private string $table = 'shop_reviews';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findInDomain(int $domainId, int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT r.*, p.goods_name, p.goods_slug, m.user_id, m.nickname
             FROM {$this->table} r
             LEFT JOIN shop_products p ON p.goods_id = r.goods_id
             LEFT JOIN members m ON m.member_id = r.member_id
             WHERE r.review_id = ? AND r.domain_id = ?",
            [$id, $domainId]
        ) ?: null;
    }

    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['r.domain_id = ?'];
        $params = [$domainId];

        if (isset($filters['is_visible']) && $filters['is_visible'] !== '') {
            $where[] = 'r.is_visible = ?';
            $params[] = (int) $filters['is_visible'];
        }
        if (!empty($filters['goods_id'])) {
            $where[] = 'r.goods_id = ?';
            $params[] = (int) $filters['goods_id'];
        }
        if (isset($filters['is_best']) && $filters['is_best'] !== '') {
            $where[] = 'r.is_best = ?';
            $params[] = (int) $filters['is_best'];
        }
        if (!empty($filters['member_id'])) {
            $where[] = 'r.member_id = ?';
            $params[] = (int) $filters['member_id'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(r.content LIKE ? OR p.goods_name LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        if (!empty($filters['has_photo'])) {
            $where[] = "r.review_type = 'PHOTO'";
        }

        // 정렬 (화이트리스트 — SQL 인젝션 불가, 기본 최신순)
        $orderBy = match ($filters['sort'] ?? 'newest') {
            'rating_high' => 'r.rating DESC, r.review_id DESC',
            'rating_low'  => 'r.rating ASC, r.review_id DESC',
            'best'        => 'r.is_best DESC, r.review_id DESC',
            default       => 'r.review_id DESC',
        };

        $whereClause = implode(' AND ', $where);

        $totalItems = (int) $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} r
             LEFT JOIN shop_products p ON p.goods_id = r.goods_id
             WHERE {$whereClause}",
            $params
        )['cnt'];

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->select(
            "SELECT r.*, p.goods_name, p.goods_slug, p.display_price, m.user_id, m.nickname,
                    (SELECT image_url FROM shop_product_images pi WHERE pi.goods_id = r.goods_id ORDER BY sort_order LIMIT 1) AS product_thumbnail
             FROM {$this->table} r
             LEFT JOIN shop_products p ON p.goods_id = r.goods_id
             LEFT JOIN members m ON m.member_id = r.member_id
             WHERE {$whereClause}
             ORDER BY {$orderBy}
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

    /**
     * 상품 ID 배열에 대한 구매후기 통계 배치 조회
     *
     * @return array [goods_id => ['count' => N, 'avg_rating' => N.N]]
     */
    public function getStatsByGoodsIds(int $domainId, array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $rows = $this->db->select(
            "SELECT goods_id, COUNT(*) AS cnt, AVG(rating) AS avg_rating
             FROM {$this->table}
             WHERE domain_id = ? AND goods_id IN ({$placeholders}) AND is_visible = 1
             GROUP BY goods_id",
            [$domainId, ...array_map('intval', $goodsIds)]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['goods_id']] = [
                'count' => (int) $row['cnt'],
                'avg_rating' => round((float) $row['avg_rating'], 1),
            ];
        }

        return $result;
    }

    public function getByGoodsId(int $domainId, int $goodsId, int $page = 1, int $perPage = 10): array
    {
        return $this->getList($domainId, ['goods_id' => $goodsId, 'is_visible' => 1], $page, $perPage);
    }

    public function getAverageRating(int $domainId, int $goodsId): float
    {
        // goodsId <= 0 이면 상품 무관 전체 평균 (getList 의 goods_id 빈값 처리와 동일 의미)
        $where = ['domain_id = ?', 'is_visible = 1'];
        $params = [$domainId];
        if ($goodsId > 0) {
            $where[] = 'goods_id = ?';
            $params[] = $goodsId;
        }

        $row = $this->db->selectOne(
            "SELECT AVG(rating) AS avg_rating FROM {$this->table} WHERE " . implode(' AND ', $where),
            $params
        );
        return round((float) ($row['avg_rating'] ?? 0), 1);
    }

    /**
     * 평점별 후기 개수 분포
     *
     * @return array{1:int,2:int,3:int,4:int,5:int}
     */
    public function getRatingDistribution(int $domainId, int $goodsId): array
    {
        $rows = $this->db->select(
            "SELECT rating, COUNT(*) AS cnt FROM {$this->table}
             WHERE domain_id = ? AND goods_id = ? AND is_visible = 1
             GROUP BY rating",
            [$domainId, $goodsId]
        );

        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $row) {
            $r = (int) $row['rating'];
            if (isset($dist[$r])) {
                $dist[$r] = (int) $row['cnt'];
            }
        }

        return $dist;
    }

    public function findByOrderDetailId(int $domainId, int $orderDetailId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE domain_id = ? AND order_detail_id = ?",
            [$domainId, $orderDetailId]
        ) ?: null;
    }

    /**
     * 주어진 주문상세 ID 중 이미 후기가 작성된 것의 맵
     *
     * @param int[] $orderDetailIds
     * @return array<int,int> [order_detail_id => review_id]
     */
    public function getReviewedDetailIds(int $domainId, array $orderDetailIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderDetailIds)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->select(
            "SELECT order_detail_id, review_id FROM {$this->table}
             WHERE domain_id = ? AND order_detail_id IN ({$placeholders})",
            [$domainId, ...$ids]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['order_detail_id']] = (int) $row['review_id'];
        }

        return $map;
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
        $sets = [];
        $values = [];
        foreach ($data as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $values[] = $val;
        }
        $values[] = $id;
        $values[] = $domainId;

        return $this->db->execute(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE review_id = ? AND domain_id = ?",
            $values
        );
    }

    public function deleteInDomain(int $domainId, int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE review_id = ? AND domain_id = ?",
            [$id, $domainId]
        );
    }

    public function batchUpdateFields(int $domainId, array $items): int
    {
        $updated = 0;
        foreach ($items as $reviewId => $fields) {
            $allowed = array_intersect_key($fields, array_flip(['is_visible', 'is_best']));
            if (empty($allowed)) {
                continue;
            }
            if ($this->updateInDomain($domainId, (int) $reviewId, $allowed) > 0) {
                $updated++;
            }
        }
        return $updated;
    }

    public function deleteByIds(int $domainId, array $reviewIds): int
    {
        if (empty($reviewIds)) {
            return 0;
        }
        $ids = array_map('intval', $reviewIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE domain_id = ? AND review_id IN ({$placeholders})",
            [$domainId, ...$ids]
        );
    }
}
