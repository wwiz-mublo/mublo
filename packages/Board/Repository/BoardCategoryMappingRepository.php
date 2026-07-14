<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardCategoryMapping;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardCategoryMapping Repository
 *
 * 게시판-카테고리 매핑 데이터베이스 접근 담당
 *
 * 책임:
 * - board_category_mapping 테이블 CRUD
 * - BoardCategoryMapping Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardCategoryMappingRepository extends BaseRepository
{
    protected string $table = 'board_category_mapping';
    protected string $entityClass = BoardCategoryMapping::class;
    protected string $primaryKey = 'mapping_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 게시판별 카테고리 매핑 목록 조회
     *
     * @param int $boardId 게시판 ID
     * @param bool $activeOnly 활성 매핑만
     * @return BoardCategoryMapping[]
     */
    public function findByBoard(int $boardId, bool $activeOnly = true): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        $rows = $query
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 게시판별 카테고리 목록 조회 (카테고리 정보 포함)
     *
     * @param int $boardId 게시판 ID
     * @return array
     */
    public function findByBoardWithCategory(int $boardId): array
    {
        $rows = $this->getDb()->table($this->table . ' AS m')
            ->select([
                'm.*',
                'c.category_name',
                'c.category_slug',
            ])
            ->leftJoin('board_categories AS c', 'm.category_id', '=', 'c.category_id')
            ->where('m.board_id', '=', $boardId)
            ->where('m.is_active', '=', 1)
            ->where('c.is_active', '=', 1)
            ->orderBy('m.sort_order', 'ASC')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'mapping' => BoardCategoryMapping::fromArray($row),
                'category_name' => $row['category_name'] ?? '',
                'category_slug' => $row['category_slug'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * 카테고리별 게시판 매핑 목록 조회
     *
     * @param int $categoryId 카테고리 ID
     * @return BoardCategoryMapping[]
     */
    public function findByCategory(int $categoryId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 특정 매핑 조회
     */
    public function findByBoardAndCategory(int $boardId, int $categoryId): ?BoardCategoryMapping
    {
        return $this->findOneBy([
            'board_id' => $boardId,
            'category_id' => $categoryId,
        ]);
    }

    /**
     * 매핑 존재 여부 확인
     */
    public function existsByBoardAndCategory(int $boardId, int $categoryId): bool
    {
        return $this->existsBy([
            'board_id' => $boardId,
            'category_id' => $categoryId,
        ]);
    }

    /**
     * 게시판별 카테고리 수 조회
     */
    public function countByBoard(int $boardId, bool $activeOnly = true): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->count();
    }

    /**
     * 카테고리별 게시판 수 조회
     */
    public function countByCategory(int $categoryId, bool $activeOnly = true): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('category_id', '=', $categoryId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->count();
    }

    /**
     * 게시판별 매핑 삭제
     */
    public function deleteByBoard(int $boardId): int
    {
        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->delete();
    }

    /**
     * 카테고리별 매핑 삭제
     */
    public function deleteByCategory(int $categoryId): int
    {
        return $this->getDb()->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->delete();
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int $boardId 게시판 ID
     * @param int[] $categoryIds 정렬된 카테고리 ID 배열
     * @return bool
     */
    public function updateOrder(int $boardId, array $categoryIds): bool
    {
        foreach ($categoryIds as $index => $categoryId) {
            $this->getDb()->table($this->table)
                ->where('board_id', '=', $boardId)
                ->where('category_id', '=', $categoryId)
                ->update(['sort_order' => $index]);
        }

        return true;
    }

    /**
     * 다음 정렬 순서 반환
     */
    public function getNextSortOrder(int $boardId): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * 권한 오버라이드 업데이트
     */
    public function updatePermissions(int $mappingId, array $permissions): bool
    {
        $allowedFields = ['list_level', 'read_level', 'write_level', 'comment_level', 'download_level'];

        $data = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $permissions)) {
                $data[$field] = $permissions[$field] !== '' ? (int) $permissions[$field] : null;
            }
        }

        if (empty($data)) {
            return false;
        }

        $affected = $this->getDb()->table($this->table)
            ->where('mapping_id', '=', $mappingId)
            ->update($data);

        return $affected >= 0;
    }

    /**
     * 게시판의 카테고리 일괄 설정
     *
     * @param int $boardId 게시판 ID
     * @param int[] $categoryIds 카테고리 ID 배열
     * @return bool
     */
    public function syncCategories(int $boardId, array $categoryIds): bool
    {
        // 기존 매핑 삭제
        $this->deleteByBoard($boardId);

        // 새 매핑 추가
        foreach ($categoryIds as $index => $categoryId) {
            $this->create([
                'board_id' => $boardId,
                'category_id' => (int) $categoryId,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        return true;
    }

    /**
     * 게시판용 카테고리 선택 옵션 조회
     *
     * @param int $boardId 게시판 ID
     * @return array [['value' => id, 'label' => name], ...]
     */
    public function getSelectOptions(int $boardId): array
    {
        $mappings = $this->findByBoardWithCategory($boardId);

        return array_map(fn($item) => [
            'value' => $item['mapping']->getCategoryId(),
            'label' => $item['category_name'],
        ], $mappings);
    }
}
