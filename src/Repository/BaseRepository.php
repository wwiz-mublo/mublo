<?php
namespace Mublo\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * BaseRepository
 *
 * 모든 Repository의 기본 클래스
 *
 * 책임:
 * - 표준화된 CRUD 구현
 * - 공통 쿼리 로직
 * - 페이지네이션
 * - Entity 매핑
 *
 * 사용:
 * class MemberRepository extends BaseRepository {
 *     protected string $table = 'members';
 *     protected string $entityClass = Member::class;
 * }
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected Database $db;
    protected string $table;
    protected string $entityClass;
    protected string $primaryKey = 'id';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * ID로 단일 엔티티 조회
     */
    public function find(int|string $id): ?object
    {
        $row = $this->db->table($this->table)
            ->where($this->primaryKey, '=', $id)
            ->first();

        if (!$row) {
            return null;
        }

        return $this->toEntity($row);
    }

    /**
     * 모든 엔티티 조회 (페이지네이션 지원)
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->db->table($this->table)
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 조건으로 검색
     */
    public function findBy(array $conditions, int $limit = 100, int $offset = 0): array
    {
        $query = $this->db->table($this->table);

        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }

        $rows = $query
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 조건으로 단일 엔티티 조회
     */
    public function findOneBy(array $conditions): ?object
    {
        $query = $this->db->table($this->table);

        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }

        $row = $query->first();

        if (!$row) {
            return null;
        }

        return $this->toEntity($row);
    }

    /**
     * 조건으로 엔티티 존재 여부 확인
     */
    public function existsBy(array $conditions): bool
    {
        $query = $this->db->table($this->table);

        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }

        return $query->exists();
    }

    /**
     * 조건에 맞는 엔티티 개수 조회
     */
    public function countBy(array $conditions = []): int
    {
        $query = $this->db->table($this->table);

        foreach ($conditions as $field => $value) {
            $query->where($field, '=', $value);
        }

        return $query->count();
    }

    /**
     * 엔티티 생성
     */
    public function create(array $data): int|null
    {
        // 생성 타임스탬프 자동 추가
        if (method_exists($this, 'getCreatedAtField')) {
            $createdAtField = $this->getCreatedAtField();
            if ($createdAtField && !isset($data[$createdAtField])) {
                $data[$createdAtField] = date('Y-m-d H:i:s');
            }
        }

        return $this->db->table($this->table)->insert($data);
    }

    /**
     * 엔티티 수정
     */
    public function update(int|string $id, array $data): int
    {
        // 수정 타임스탬프 자동 추가
        if (method_exists($this, 'getUpdatedAtField')) {
            $updatedAtField = $this->getUpdatedAtField();
            if ($updatedAtField) {
                $data[$updatedAtField] = date('Y-m-d H:i:s');
            }
        }

        return $this->db->table($this->table)
            ->where($this->primaryKey, '=', $id)
            ->update($data);
    }

    /**
     * 엔티티 삭제
     */
    public function delete(int|string $id): int
    {
        return $this->db->table($this->table)
            ->where($this->primaryKey, '=', $id)
            ->delete();
    }

    /**
     * 페이지네이션
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $total = $this->countBy();
        $items = $this->all($perPage, $offset);

        return [
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * DB 로우를 Entity로 변환
     *
     * 자식 클래스에서 오버라이드 가능
     */
    protected function toEntity(array $row): object
    {
        if (method_exists($this->entityClass, 'fromArray')) {
            return $this->entityClass::fromArray($row);
        }

        return (object)$row;
    }

    /**
     * DB 로우 배열을 Entity 배열로 변환
     */
    protected function toEntities(array $rows): array
    {
        return array_map(fn($row) => $this->toEntity($row), $rows);
    }

    /**
     * Database 인스턴스 반환 (쿼리 확장용, 트랜잭션 접근용)
     */
    public function getDb(): Database
    {
        return $this->db;
    }

    /**
     * 생성 타임스탬프 필드명 반환 (오버라이드 가능)
     */
    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    /**
     * 수정 타임스탬프 필드명 반환 (오버라이드 가능)
     */
    protected function getUpdatedAtField(): ?string
    {
        return 'updated_at';
    }
}
