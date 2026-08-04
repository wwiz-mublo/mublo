<?php
declare(strict_types=1);
namespace Mublo\Plugin\Manual\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * ManualRepository
 *
 * 매뉴얼 책 + 페이지(트리) 데이터베이스 접근.
 * 페이지는 book_id 로 도메인 경계를 간접 보장하며, 삭제/조회 시 책 소유 도메인을 확인한다.
 */
class ManualRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────
    // 책 조회
    // ─────────────────────────────────────────

    /**
     * 책 목록 (활성 필터 가능)
     */
    public function findBooks(int $domainId, bool $activeOnly = false): array
    {
        $query = $this->db->table('manual_books')
            ->where('domain_id', '=', $domainId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('sort_order', 'ASC')
            ->orderBy('book_id', 'ASC')
            ->get();
    }

    /**
     * 책 목록 + 페이지 수 (관리자용)
     */
    public function findBooksWithCount(int $domainId): array
    {
        $sql = "SELECT b.*, COUNT(p.page_id) AS page_count
                FROM manual_books b
                LEFT JOIN manual_pages p ON b.book_id = p.book_id
                WHERE b.domain_id = ?
                GROUP BY b.book_id
                ORDER BY b.sort_order ASC, b.book_id ASC";

        return $this->db->select($sql, [$domainId]);
    }

    /**
     * 책 단건 조회 (domainId 전달 시 도메인 경계 보장)
     */
    public function findBook(int $bookId, ?int $domainId = null): ?array
    {
        $query = $this->db->table('manual_books')
            ->where('book_id', '=', $bookId);

        if ($domainId !== null) {
            $query->where('domain_id', '=', $domainId);
        }

        return $query->first();
    }

    /**
     * 슬러그로 책 조회
     */
    public function findBookBySlug(int $domainId, string $slug, bool $activeOnly = false): ?array
    {
        $query = $this->db->table('manual_books')
            ->where('domain_id', '=', $domainId)
            ->where('slug', '=', $slug);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->first();
    }

    /**
     * 책 슬러그 중복 확인 (수정 시 자기 자신 제외)
     */
    public function existsBookSlug(int $domainId, string $slug, ?int $excludeId = null): bool
    {
        $query = $this->db->table('manual_books')
            ->where('domain_id', '=', $domainId)
            ->where('slug', '=', $slug);

        if ($excludeId !== null) {
            $query->where('book_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    // ─────────────────────────────────────────
    // 책 CUD
    // ─────────────────────────────────────────

    public function insertBook(array $data): ?int
    {
        return $this->db->table('manual_books')->insert($data);
    }

    public function updateBook(int $bookId, int $domainId, array $data): int
    {
        return $this->db->table('manual_books')
            ->where('book_id', '=', $bookId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    public function deleteBook(int $bookId, int $domainId): int
    {
        return $this->db->table('manual_books')
            ->where('book_id', '=', $bookId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /** DB 변경 여러 건을 하나의 원자적 작업으로 실행한다. */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction(static fn () => $callback());
    }

    // ─────────────────────────────────────────
    // 페이지 조회
    // ─────────────────────────────────────────

    /**
     * 책의 페이지 목록 (플랫, sort 정렬) — 트리는 Service에서 구성
     */
    public function findPages(int $bookId, bool $activeOnly = false): array
    {
        $query = $this->db->table('manual_pages')
            ->where('book_id', '=', $bookId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('sort_order', 'ASC')
            ->orderBy('page_id', 'ASC')
            ->get();
    }

    /**
     * 페이지 단건 조회
     */
    public function findPage(int $pageId, ?int $bookId = null): ?array
    {
        $query = $this->db->table('manual_pages')
            ->where('page_id', '=', $pageId);

        if ($bookId !== null) {
            $query->where('book_id', '=', $bookId);
        }

        return $query->first();
    }

    /**
     * 책 안에서 슬러그로 페이지 조회
     */
    public function findPageBySlug(int $bookId, string $slug, bool $activeOnly = false): ?array
    {
        $query = $this->db->table('manual_pages')
            ->where('book_id', '=', $bookId)
            ->where('slug', '=', $slug);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->first();
    }

    /**
     * 도메인의 최근 수정 활성 페이지.
     *
     * @param list<string> $bookSlugs 비어 있으면 모든 활성 책
     */
    public function findRecentPages(int $domainId, array $bookSlugs, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $params = [$domainId];
        $bookFilter = '';

        if ($bookSlugs !== []) {
            $placeholders = implode(', ', array_fill(0, count($bookSlugs), '?'));
            $bookFilter = " AND b.slug IN ({$placeholders})";
            array_push($params, ...$bookSlugs);
        }

        $sql = "SELECT p.page_id, p.title AS page_title, p.slug AS page_slug,
                       p.content, p.updated_at,
                       b.title AS book_title, b.slug AS book_slug
                FROM manual_pages p
                INNER JOIN manual_books b ON b.book_id = p.book_id
                WHERE b.domain_id = ?
                  AND b.is_active = 1
                  AND p.is_active = 1
                  {$bookFilter}
                ORDER BY p.updated_at DESC, p.page_id DESC
                LIMIT {$limit}";

        return $this->db->select($sql, $params);
    }

    /**
     * 페이지 슬러그 중복 확인 (같은 책 안에서, 수정 시 자기 자신 제외)
     */
    public function existsPageSlug(int $bookId, string $slug, ?int $excludeId = null): bool
    {
        $query = $this->db->table('manual_pages')
            ->where('book_id', '=', $bookId)
            ->where('slug', '=', $slug);

        if ($excludeId !== null) {
            $query->where('page_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 직계 자식 페이지 목록
     */
    public function findChildren(int $bookId, ?int $parentId): array
    {
        $query = $this->db->table('manual_pages')
            ->where('book_id', '=', $bookId);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', '=', $parentId);
        }

        return $query->orderBy('sort_order', 'ASC')->get();
    }

    // ─────────────────────────────────────────
    // 페이지 CUD
    // ─────────────────────────────────────────

    public function insertPage(array $data): ?int
    {
        return $this->db->table('manual_pages')->insert($data);
    }

    public function updatePage(int $pageId, int $bookId, array $data): int
    {
        return $this->db->table('manual_pages')
            ->where('page_id', '=', $pageId)
            ->where('book_id', '=', $bookId)
            ->update($data);
    }

    public function deletePage(int $pageId, int $bookId): int
    {
        return $this->db->table('manual_pages')
            ->where('page_id', '=', $pageId)
            ->where('book_id', '=', $bookId)
            ->delete();
    }

    /**
     * 정렬/부모/깊이 갱신 (트리 재구성용)
     */
    public function updatePageTreeNode(int $pageId, int $bookId, ?int $parentId, int $sortOrder, int $depth): int
    {
        return $this->db->table('manual_pages')
            ->where('page_id', '=', $pageId)
            ->where('book_id', '=', $bookId)
            ->update([
                'parent_id'  => $parentId,
                'sort_order' => $sortOrder,
                'depth'      => $depth,
            ]);
    }

    public function updatePageDepth(int $pageId, int $bookId, int $depth): int
    {
        return $this->db->table('manual_pages')
            ->where('page_id', '=', $pageId)
            ->where('book_id', '=', $bookId)
            ->update(['depth' => $depth]);
    }
}
