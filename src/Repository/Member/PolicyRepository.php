<?php
namespace Mublo\Repository\Member;

use Mublo\Entity\Member\Policy;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * Policy Repository
 *
 * 정책/약관 데이터베이스 접근 담당
 *
 * 책임:
 * - policies 테이블 CRUD
 * - Policy Entity 반환
 *
 * Note: 도메인별로 독립된 정책을 관리합니다.
 */
class PolicyRepository extends BaseRepository
{
    protected string $table = 'policies';
    protected string $entityClass = Policy::class;
    protected string $primaryKey = 'policy_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    // ========================================
    // 조회
    // ========================================

    /**
     * 도메인별 전체 정책 목록 조회 (sort_order 오름차순)
     *
     * @return Policy[]
     */
    public function getAllByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('policy_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인별 활성 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getActiveByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('policy_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 회원가입 시 출력할 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getRegisterPolicies(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->where('show_in_register', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('policy_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 정책 ID로 조회 (타입 힌트 명시)
     */
    public function findById(int $policyId): ?Policy
    {
        return $this->find($policyId);
    }

    /**
     * 도메인 + 슬러그로 정책 조회
     */
    public function findBySlug(int $domainId, string $slug): ?Policy
    {
        return $this->findOneBy([
            'domain_id' => $domainId,
            'slug' => $slug,
        ]);
    }

    /**
     * 도메인 + 정책타입으로 정책 조회
     */
    public function findByType(int $domainId, string $policyType): ?Policy
    {
        return $this->findOneBy([
            'domain_id' => $domainId,
            'policy_type' => $policyType,
        ]);
    }

    /**
     * 회원가입 필수 정책 목록 조회
     *
     * @return Policy[]
     */
    public function getRequiredForSignup(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->where('is_required', '=', 1)
            ->where('show_in_register', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('policy_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    // ========================================
    // 검증
    // ========================================

    /**
     * 도메인 내 슬러그 존재 확인
     */
    public function existsBySlug(int $domainId, string $slug): bool
    {
        return $this->existsBy([
            'domain_id' => $domainId,
            'slug' => $slug,
        ]);
    }

    /**
     * 특정 ID 제외하고 슬러그 중복 확인 (수정 시)
     */
    public function existsBySlugExcept(int $domainId, string $slug, int $excludeId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('slug', '=', $slug)
            ->where($this->primaryKey, '!=', $excludeId)
            ->exists();
    }

    /**
     * 도메인 내 정책타입 존재 확인
     */
    public function existsByType(int $domainId, string $policyType): bool
    {
        return $this->existsBy([
            'domain_id' => $domainId,
            'policy_type' => $policyType,
        ]);
    }

    /**
     * 특정 ID 제외하고 정책타입 중복 확인 (수정 시)
     */
    public function existsByTypeExcept(int $domainId, string $policyType, int $excludeId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('policy_type', '=', $policyType)
            ->where($this->primaryKey, '!=', $excludeId)
            ->exists();
    }

    // ========================================
    // 페이지네이션 (관리자용)
    // ========================================

    /**
     * 페이지네이션 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $filters 필터 조건 ['policy_type' => 'terms', 'is_active' => 1, ...]
     * @return array ['data' => Policy[], 'total' => int, 'page' => int, ...]
     */
    public function getPaginatedList(int $domainId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        // 필터 적용
        if (!empty($filters['policy_type'])) {
            $query->where('policy_type', '=', $filters['policy_type']);
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', '=', (int) $filters['is_active']);
        }
        if (isset($filters['is_required'])) {
            $query->where('is_required', '=', (int) $filters['is_required']);
        }
        if (isset($filters['show_in_register'])) {
            $query->where('show_in_register', '=', (int) $filters['show_in_register']);
        }
        if (!empty($filters['keyword'])) {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'LIKE', $keyword)
                  ->orWhere('slug', 'LIKE', $keyword);
            });
        }

        // 카운트
        $total = (clone $query)->count();

        // 데이터 조회
        $rows = $query
            ->orderBy('sort_order', 'ASC')
            ->orderBy('policy_id', 'ASC')
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
    // 정렬 관련
    // ========================================

    /**
     * 다음 정렬 순서 값 조회
     */
    public function getNextSortOrder(int $domainId): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->max('sort_order');

        return ($maxOrder ?? 0) + 1;
    }

    /**
     * 정렬 순서 일괄 업데이트
     *
     * @param array $orderData [policy_id => sort_order, ...]
     */
    public function updateSortOrders(array $orderData): void
    {
        foreach ($orderData as $policyId => $sortOrder) {
            $this->update((int) $policyId, ['sort_order' => (int) $sortOrder]);
        }
    }

    // ========================================
    // Entity 변환
    // ========================================

    /**
     * DB 로우를 Policy Entity로 변환
     */
    protected function toEntity(array $row): Policy
    {
        return Policy::fromArray($row);
    }
}
