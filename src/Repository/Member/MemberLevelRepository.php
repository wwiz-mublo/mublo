<?php
namespace Mublo\Repository\Member;

use Mublo\Entity\Member\MemberLevel;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * MemberLevel Repository
 *
 * 회원 등급 데이터베이스 접근 담당 (전역)
 *
 * 책임:
 * - member_levels 테이블 CRUD
 * - MemberLevel Entity 반환
 *
 * Note: 이 테이블은 전역 테이블로, 모든 도메인이 동일한 레벨 체계를 공유합니다.
 */
class MemberLevelRepository extends BaseRepository
{
    protected string $table = 'member_levels';
    protected string $entityClass = MemberLevel::class;
    protected string $primaryKey = 'level_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    // ========================================
    // 조회
    // ========================================

    /**
     * 전체 등급 목록 조회 (level_value 오름차순)
     *
     * @return MemberLevel[]
     */
    public function getAll(): array
    {
        $rows = $this->getDb()->table($this->table)
            ->orderBy('level_value', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 등급 옵션 조회 (select box용)
     *
     * @return array [level_value => level_name, ...]
     */
    public function getOptionsForSelect(): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['level_value', 'level_name'])
            ->orderBy('level_value', 'ASC')
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $options[$row['level_value']] = $row['level_name'];
        }

        return $options;
    }

    /**
     * 레벨값으로 등급 조회
     */
    public function findByValue(int $levelValue): ?MemberLevel
    {
        return $this->findOneBy(['level_value' => $levelValue]);
    }

    /**
     * 레벨 ID로 등급 조회 (타입 힌트 명시)
     */
    public function findById(int $levelId): ?MemberLevel
    {
        return $this->find($levelId);
    }

    // ========================================
    // 역할별 조회
    // ========================================

    /**
     * 도메인 운영 가능 레벨 목록 조회
     *
     * @return MemberLevel[]
     */
    public function getOperatorLevels(): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('can_operate_domain', '=', 1)
            ->orderBy('level_value', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 관리자 모드 접근 가능 레벨 목록 조회
     *
     * @return MemberLevel[]
     */
    public function getAdminLevels(): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where(function ($q) {
                $q->where('is_admin', '=', 1)
                  ->orWhere('is_super', '=', 1);
            })
            ->orderBy('level_value', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 일반 회원 레벨 목록 조회 (관리자 아님)
     *
     * @return MemberLevel[]
     */
    public function getMemberLevels(): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('is_admin', '=', 0)
            ->where('is_super', '=', 0)
            ->orderBy('level_value', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    // ========================================
    // 검증
    // ========================================

    /**
     * 레벨값 존재 확인
     */
    public function existsByValue(int $levelValue): bool
    {
        return $this->existsBy(['level_value' => $levelValue]);
    }

    /**
     * 특정 ID 제외하고 레벨값 중복 확인 (수정 시)
     */
    public function existsByValueExcept(int $levelValue, int $excludeId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('level_value', '=', $levelValue)
            ->where($this->primaryKey, '!=', $excludeId)
            ->exists();
    }

    /**
     * 특정 레벨값을 사용하는 회원 수 조회
     */
    public function countMembersUsingLevel(int $levelValue): int
    {
        return $this->getDb()->table('members')
            ->where('level_value', '=', $levelValue)
            ->count();
    }

    // ========================================
    // 페이지네이션 (관리자용)
    // ========================================

    /**
     * 페이지네이션 목록 조회
     *
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $filters 필터 조건 ['level_type' => 'STAFF', ...]
     * @return array ['data' => MemberLevel[], 'total' => int, 'page' => int, ...]
     */
    public function getPaginatedList(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->getDb()->table($this->table);

        // 필터 적용
        if (!empty($filters['level_type'])) {
            $query->where('level_type', '=', $filters['level_type']);
        }
        if (isset($filters['is_admin'])) {
            $query->where('is_admin', '=', (int) $filters['is_admin']);
        }
        if (isset($filters['can_operate_domain'])) {
            $query->where('can_operate_domain', '=', (int) $filters['can_operate_domain']);
        }

        // 카운트
        $total = (clone $query)->count();

        // 데이터 조회
        $rows = $query
            ->orderBy('level_value', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $this->toEntities($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    // ========================================
    // Entity 변환
    // ========================================

    /**
     * DB 로우를 MemberLevel Entity로 변환
     */
    protected function toEntity(array $row): MemberLevel
    {
        return MemberLevel::fromArray($row);
    }
}
