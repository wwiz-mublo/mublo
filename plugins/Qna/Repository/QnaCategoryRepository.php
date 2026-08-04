<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * QnA 문의 유형(카테고리) 데이터 접근.
 * 모든 조회/변경은 domain_id 로 스코프를 강제한다.
 */
class QnaCategoryRepository
{
    private string $table = 'qna_categories';

    public function __construct(private Database $db)
    {
    }

    /** 유형 목록 (activeOnly 로 활성만 필터) */
    public function findAll(int $domainId, bool $activeOnly = false): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('sort_order', 'ASC')
            ->orderBy('category_id', 'ASC')
            ->get();
    }

    public function find(int $categoryId, int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->first() ?: null;
    }

    public function existsSlug(int $domainId, string $slug, ?int $excludeId = null): bool
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('category_slug', '=', $slug);

        if ($excludeId !== null) {
            $query->where('category_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function insert(array $data): ?int
    {
        return $this->db->table($this->table)->insert($data);
    }

    public function update(int $categoryId, int $domainId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    public function delete(int $categoryId, int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }
}
