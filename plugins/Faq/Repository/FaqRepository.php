<?php
namespace Mublo\Plugin\Faq\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * FaqRepository
 *
 * FAQ 카테고리 + 항목 데이터베이스 접근
 */
class FaqRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────
    // 카테고리 조회
    // ─────────────────────────────────────────

    /**
     * 카테고리 목록 (활성 필터 가능)
     */
    public function findCategories(int $domainId, bool $activeOnly = false): array
    {
        $query = $this->db->table('faq_categories')
            ->where('domain_id', '=', $domainId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('sort_order', 'ASC')
            ->orderBy('category_id', 'ASC')
            ->get();
    }

    /**
     * 카테고리 + 항목 수 (활성 카테고리만)
     */
    public function findCategoriesWithCount(int $domainId): array
    {
        $sql = "SELECT c.*, COUNT(i.faq_id) AS item_count
                FROM faq_categories c
                LEFT JOIN faq_items i ON c.category_id = i.category_id AND i.is_active = 1
                WHERE c.domain_id = ? AND c.is_active = 1
                GROUP BY c.category_id
                ORDER BY c.sort_order ASC, c.category_id ASC";

        return $this->db->select($sql, [$domainId]);
    }

    /**
     * 사이트맵용 카테고리 목록
     *
     * 활성 카테고리 중 활성 항목이 1개 이상인 것만 반환한다(빈 목록 페이지 제외).
     * lastmod 는 카테고리와 그에 속한 활성 항목 중 가장 늦은 수정 시각.
     *
     * @return array [{category_slug, item_count, lastmod}, ...]
     */
    public function findSitemapCategories(int $domainId): array
    {
        $sql = "SELECT c.category_slug,
                       COUNT(i.faq_id) AS item_count,
                       GREATEST(c.updated_at, COALESCE(MAX(i.updated_at), c.updated_at)) AS lastmod
                FROM faq_categories c
                LEFT JOIN faq_items i
                       ON i.category_id = c.category_id
                      AND i.domain_id = c.domain_id
                      AND i.is_active = 1
                WHERE c.domain_id = ? AND c.is_active = 1 AND c.category_slug <> ''
                GROUP BY c.category_id, c.category_slug, c.updated_at
                HAVING item_count > 0
                ORDER BY c.sort_order ASC, c.category_id ASC";

        return $this->db->select($sql, [$domainId]);
    }

    /**
     * 카테고리 단건 조회 (domainId 전달 시 도메인 경계 보장)
     */
    public function findCategory(int $categoryId, ?int $domainId = null): ?array
    {
        $query = $this->db->table('faq_categories')
            ->where('category_id', '=', $categoryId);

        if ($domainId !== null) {
            $query->where('domain_id', '=', $domainId);
        }

        return $query->first();
    }

    /**
     * 슬러그로 카테고리 조회
     */
    public function findCategoryBySlug(int $domainId, string $slug): ?array
    {
        return $this->db->table('faq_categories')
            ->where('domain_id', '=', $domainId)
            ->where('category_slug', '=', $slug)
            ->first();
    }

    /**
     * 슬러그 중복 확인 (수정 시 자기 자신 제외)
     */
    public function existsSlug(int $domainId, string $slug, ?int $excludeId = null): bool
    {
        $query = $this->db->table('faq_categories')
            ->where('domain_id', '=', $domainId)
            ->where('category_slug', '=', $slug);

        if ($excludeId !== null) {
            $query->where('category_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    // ─────────────────────────────────────────
    // 카테고리 CUD
    // ─────────────────────────────────────────

    public function insertCategory(array $data): ?int
    {
        return $this->db->table('faq_categories')->insert($data);
    }

    public function updateCategory(int $categoryId, int $domainId, array $data): int
    {
        return $this->db->table('faq_categories')
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    public function deleteCategory(int $categoryId, int $domainId): int
    {
        return $this->db->table('faq_categories')
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    // ─────────────────────────────────────────
    // FAQ 항목 조회
    // ─────────────────────────────────────────

    /**
     * 카테고리별 FAQ 항목 목록
     */
    public function findItems(int $domainId, ?int $categoryId = null, bool $activeOnly = false): array
    {
        $query = $this->db->table('faq_items')
            ->where('domain_id', '=', $domainId);

        if ($categoryId !== null) {
            $query->where('category_id', '=', $categoryId);
        }

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('sort_order', 'ASC')
            ->orderBy('faq_id', 'ASC')
            ->get();
    }

    /**
     * FAQ 단건 조회 (domainId 전달 시 도메인 경계 보장)
     */
    public function findItem(int $faqId, ?int $domainId = null): ?array
    {
        $query = $this->db->table('faq_items')
            ->where('faq_id', '=', $faqId);

        if ($domainId !== null) {
            $query->where('domain_id', '=', $domainId);
        }

        return $query->first();
    }

    /**
     * 전체 FAQ (카테고리별 그룹핑)
     */
    public function findGroupedAll(int $domainId): array
    {
        $sql = "SELECT c.category_id, c.category_name, c.category_slug,
                       i.faq_id, i.question, i.answer
                FROM faq_categories c
                INNER JOIN faq_items i ON c.category_id = i.category_id AND i.is_active = 1
                WHERE c.domain_id = ? AND c.is_active = 1
                ORDER BY c.sort_order ASC, c.category_id ASC, i.sort_order ASC, i.faq_id ASC";

        return $this->db->select($sql, [$domainId]);
    }

    /**
     * 활성 FAQ 총 개수
     */
    public function countActiveItems(int $domainId): int
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM faq_items i
                INNER JOIN faq_categories c ON c.category_id = i.category_id
                WHERE i.domain_id = ? AND i.is_active = 1 AND c.is_active = 1";

        $row = $this->db->selectOne($sql, [$domainId]);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * 페이지 처리된 활성 FAQ 조회 (카테고리 정보 포함)
     *
     * @return array [{faq_id, question, answer, category_name, category_slug}, ...]
     */
    public function findActiveItemsPaginated(int $domainId, int $offset, int $limit): array
    {
        $sql = "SELECT c.category_name, c.category_slug,
                       i.faq_id, i.question, i.answer
                FROM faq_categories c
                INNER JOIN faq_items i ON c.category_id = i.category_id AND i.is_active = 1
                WHERE c.domain_id = ? AND c.is_active = 1
                ORDER BY c.sort_order ASC, c.category_id ASC, i.sort_order ASC, i.faq_id ASC
                LIMIT ? OFFSET ?";

        return $this->db->select($sql, [$domainId, $limit, $offset]);
    }

    /**
     * 슬러그 배열로 FAQ 항목 조회
     */
    public function findByCategorySlugs(int $domainId, array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $params = array_merge([$domainId], $slugs);

        $sql = "SELECT c.category_slug, i.faq_id, i.question, i.answer
                FROM faq_categories c
                INNER JOIN faq_items i ON c.category_id = i.category_id AND i.is_active = 1
                WHERE c.domain_id = ? AND c.is_active = 1
                  AND c.category_slug IN ({$placeholders})
                ORDER BY c.sort_order ASC, i.sort_order ASC, i.faq_id ASC";

        return $this->db->select($sql, $params);
    }

    /**
     * 카테고리에 속한 항목 수
     */
    public function countItemsByCategory(int $categoryId): int
    {
        return $this->db->table('faq_items')
            ->where('category_id', '=', $categoryId)
            ->count();
    }

    // ─────────────────────────────────────────
    // FAQ 항목 CUD
    // ─────────────────────────────────────────

    public function insertItem(array $data): ?int
    {
        return $this->db->table('faq_items')->insert($data);
    }

    public function updateItem(int $faqId, int $domainId, array $data): int
    {
        return $this->db->table('faq_items')
            ->where('faq_id', '=', $faqId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    public function deleteItem(int $faqId, int $domainId): int
    {
        return $this->db->table('faq_items')
            ->where('faq_id', '=', $faqId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 정렬 순서 변경 (도메인 경계 보장)
     */
    public function updateItemSortOrder(int $faqId, int $domainId, int $sortOrder): int
    {
        return $this->db->table('faq_items')
            ->where('faq_id', '=', $faqId)
            ->where('domain_id', '=', $domainId)
            ->update(['sort_order' => $sortOrder]);
    }
}
