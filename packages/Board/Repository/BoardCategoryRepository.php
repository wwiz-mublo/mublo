<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardCategory;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardCategory Repository
 *
 * 게시판 카테고리 데이터베이스 접근 담당
 *
 * 책임:
 * - board_categories 테이블 CRUD
 * - BoardCategory Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardCategoryRepository extends BaseRepository
{
    protected string $table = 'board_categories';
    protected string $entityClass = BoardCategory::class;
    protected string $primaryKey = 'category_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 카테고리 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BoardCategory[]
     */
    public function findByDomain(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('sort_order', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인+슬러그로 카테고리 조회
     */
    public function findBySlug(int $domainId, string $slug): ?BoardCategory
    {
        return $this->findOneBy([
            'domain_id' => $domainId,
            'category_slug' => $slug,
        ]);
    }

    /**
     * 슬러그 중복 검사
     */
    public function existsBySlug(int $domainId, string $slug): bool
    {
        return $this->existsBy([
            'domain_id' => $domainId,
            'category_slug' => $slug,
        ]);
    }

    /**
     * 슬러그 중복 검사 (자기 자신 제외)
     */
    public function existsBySlugExceptSelf(int $domainId, string $slug, int $categoryId): bool
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('category_slug', '=', $slug)
            ->where('category_id', '!=', $categoryId);

        return $query->exists();
    }

    /**
     * 도메인별 카테고리 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->countBy(['domain_id' => $domainId]);
    }

    /**
     * 도메인별 활성 카테고리 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardCategory[]
     */
    public function findActiveByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int[] $categoryIds 정렬된 카테고리 ID 배열
     * @return bool
     */
    public function updateOrder(array $categoryIds): bool
    {
        foreach ($categoryIds as $index => $categoryId) {
            $this->getDb()->table($this->table)
                ->where('category_id', '=', $categoryId)
                ->update(['sort_order' => $index]);
        }

        return true;
    }

    /**
     * 다음 정렬 순서 반환
     */
    public function getNextSortOrder(int $domainId): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * 카테고리를 사용하는 게시판 수 조회
     */
    public function getBoardCount(int $categoryId): int
    {
        return $this->getDb()->table('board_category_mapping')
            ->where('category_id', '=', $categoryId)
            ->count();
    }

    /**
     * 선택 옵션용 카테고리 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => name], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        $categories = $this->findActiveByDomain($domainId);

        return array_map(fn(BoardCategory $category) => [
            'value' => $category->getCategoryId(),
            'label' => $category->getCategoryName(),
        ], $categories);
    }

    /**
     * 여러 ID로 카테고리 조회
     *
     * @param int[] $categoryIds
     * @return BoardCategory[]
     */
    public function findByIds(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->whereIn('category_id', $categoryIds)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }
}
