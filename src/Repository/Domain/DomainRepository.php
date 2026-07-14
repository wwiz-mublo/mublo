<?php
namespace Mublo\Repository\Domain;

use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * Class DomainRepository
 *
 * domain_configs 테이블 접근 Repository
 *
 * 책임:
 * - 도메인 기반 조회
 * - 도메인 CRUD 작업
 * - 페이지네이션 및 검색
 */
class DomainRepository extends BaseRepository
{
    protected string $table = 'domain_configs';
    protected string $entityClass = Domain::class;
    protected string $primaryKey = 'domain_id';

    public function __construct(?Database $db = null)
    {
        // getDatabase()는 null일 수 있으므로 connect() 사용
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    // =========================================================================
    // 기본 조회 메서드
    // =========================================================================

    /**
     * 도메인명으로 조회
     *
     * @param string $domainName 도메인명
     * @return Domain|null
     */
    public function findByDomain(string $domainName): ?Domain
    {
        return $this->findOneBy(['domain' => strtolower($domainName)]);
    }

    /**
     * 도메인 ID로 조회
     *
     * @param int $domainId 도메인 ID
     * @return Domain|null
     */
    public function findById(int $domainId): ?Domain
    {
        return $this->findOneBy(['domain_id' => $domainId]);
    }

    /**
     * 활성 상태 도메인만 조회
     *
     * @param string $domainName 도메인명
     * @return Domain|null
     */
    public function findActiveDomain(string $domainName): ?Domain
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain', '=', strtolower($domainName))
            ->where('status', '=', 'active')
            ->first();

        if (!$query) {
            return null;
        }

        return $this->toEntity($query);
    }

    /**
     * domain_group으로 목록 조회
     *
     * @param string $domainGroup 도메인 그룹 경로
     * @return Domain[]
     */
    public function findByDomainGroup(string $domainGroup): array
    {
        return $this->findBy(['domain_group' => $domainGroup]);
    }

    /**
     * 하위 도메인 목록 조회 (계층 구조)
     *
     * @param string $parentGroup 부모 도메인 그룹
     * @return Domain[]
     */
    public function findChildren(string $parentGroup): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_group', 'LIKE', $parentGroup . '/%')
            ->orderBy('domain_group', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 회원 소유 도메인 목록 조회
     *
     * @param int $memberId 회원 ID
     * @return Domain[]
     */
    public function findByMemberId(int $memberId): array
    {
        return $this->findBy(['member_id' => $memberId]);
    }

    // =========================================================================
    // 페이지네이션 및 검색 메서드
    // =========================================================================

    /**
     * 페이지네이션된 목록 조회 (검색/필터 지원)
     *
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $search 검색 조건 ['field' => 'domain', 'keyword' => '...']
     * @param array $filters 필터 조건 ['status' => 'active', 'member_id' => 3]
     * @param string $orderBy 정렬 필드
     * @param string $orderDir 정렬 방향 (ASC/DESC)
     * @return array ['data' => Domain[], 'total' => int, 'page' => int, ...]
     */
    public function getPaginatedList(
        int $page = 1,
        int $perPage = 20,
        array $search = [],
        array $filters = [],
        string $orderBy = 'domain_id',
        string $orderDir = 'DESC'
    ): array {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->getDb()->table($this->table);

        // 검색 조건 적용
        $this->applySearchConditions($query, $search);

        // 필터 조건 적용
        $this->applyFilterConditions($query, $filters);

        // 전체 개수 조회 (필터 적용 후)
        $total = $this->countByConditions($search, $filters);

        // 정렬 및 페이지네이션
        $rows = $query
            ->orderBy($orderBy, $orderDir)
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $this->toEntities($rows),
            'totalItems' => $total,
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalPages' => $perPage > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * 조건별 도메인 수 카운트
     *
     * @param array $search 검색 조건
     * @param array $filters 필터 조건
     * @return int
     */
    public function countByConditions(array $search = [], array $filters = []): int
    {
        $query = $this->getDb()->table($this->table);

        $this->applySearchConditions($query, $search);
        $this->applyFilterConditions($query, $filters);

        return $query->count();
    }

    /**
     * 검색 조건 적용
     */
    private function applySearchConditions(object $query, array $search): void
    {
        if (empty($search['keyword'])) {
            return;
        }

        $keyword = '%' . trim($search['keyword']) . '%';
        $field = $search['field'] ?? '';

        // 허용된 검색 필드
        $allowedFields = ['domain', 'domain_group'];

        if (in_array($field, $allowedFields, true)) {
            $query->where($field, 'LIKE', $keyword);
        } elseif ($field === 'site_title') {
            // JSON 필드 검색 (MySQL JSON_EXTRACT)
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(site_config, '$.site_title')) LIKE ?", [$keyword]);
        } else {
            // 필드 미선택 → 검색 가능한 전체 필드 통합검색 (OR)
            $query->whereRaw(
                "(domain LIKE ? OR domain_group LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(site_config, '$.site_title')) LIKE ?)",
                [$keyword, $keyword, $keyword]
            );
        }
    }

    /**
     * 필터 조건 적용
     */
    private function applyFilterConditions(object $query, array $filters): void
    {
        // 특정 도메인 제외 (자신의 도메인 제외용)
        if (!empty($filters['exclude_domain_id'])) {
            $query->where('domain_id', '!=', (int)$filters['exclude_domain_id']);
        }

        // 하위 도메인 필터 (domain_group이 현재 그룹 경로로 시작하는 것만)
        if (!empty($filters['child_of_domain_group'])) {
            $prefix = $filters['child_of_domain_group'] . '/';
            $query->where('domain_group', 'LIKE', $prefix . '%');
        }

        // 상태 필터
        if (!empty($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }

        // 소유자 회원 필터
        if (isset($filters['member_id'])) {
            if ($filters['member_id'] === 'null' || $filters['member_id'] === '') {
                $query->whereNull('member_id');
            } else {
                $query->where('member_id', '=', (int)$filters['member_id']);
            }
        }
    }

    // =========================================================================
    // 카운트 및 존재 확인 메서드
    // =========================================================================

    /**
     * 상태별 도메인 수
     *
     * @param string $status 상태
     * @return int
     */
    public function countByStatus(string $status): int
    {
        return $this->countBy(['status' => $status]);
    }

    /**
     * 특정 회원이 소유한 도메인 수 조회
     *
     * @param int $memberId 회원 ID
     * @return int
     */
    public function countByMemberId(int $memberId): int
    {
        return $this->countBy(['member_id' => $memberId]);
    }

    /**
     * 도메인 존재 여부 확인
     *
     * @param string $domainName 도메인명
     * @return bool
     */
    public function existsByDomain(string $domainName): bool
    {
        return $this->existsBy(['domain' => strtolower($domainName)]);
    }

    /**
     * 도메인 존재 여부 확인 (특정 ID 제외)
     * 수정 시 자기 자신을 제외하고 중복 확인
     *
     * @param string $domainName 도메인명
     * @param int|null $excludeId 제외할 도메인 ID
     * @return bool
     */
    public function existsByDomainExcept(string $domainName, ?int $excludeId = null): bool
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain', '=', strtolower($domainName));

        if ($excludeId !== null) {
            $query->where('domain_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    // =========================================================================
    // JSON 설정 필드 업데이트 메서드
    // =========================================================================

    /**
     * site_config JSON 필드 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param array $siteConfig 업데이트할 설정 배열
     * @return bool 성공 여부
     */
    public function updateSiteConfig(int $domainId, array $siteConfig): bool
    {
        $affected = $this->update($domainId, [
            'site_config' => json_encode($siteConfig, JSON_UNESCAPED_UNICODE),
        ]);

        return $affected > 0;
    }

    /**
     * seo_config JSON 필드 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param array $seoConfig 업데이트할 설정 배열
     * @return bool 성공 여부
     */
    public function updateSeoConfig(int $domainId, array $seoConfig): bool
    {
        $affected = $this->update($domainId, [
            'seo_config' => json_encode($seoConfig, JSON_UNESCAPED_UNICODE),
        ]);

        return $affected > 0;
    }

    /**
     * theme_config JSON 필드 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param array $themeConfig 업데이트할 설정 배열
     * @return bool 성공 여부
     */
    public function updateThemeConfig(int $domainId, array $themeConfig): bool
    {
        $affected = $this->update($domainId, [
            'theme_config' => json_encode($themeConfig, JSON_UNESCAPED_UNICODE),
        ]);

        return $affected > 0;
    }

    /**
     * company_config JSON 필드 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param array $companyConfig 업데이트할 설정 배열
     * @return bool 성공 여부
     */
    public function updateCompanyConfig(int $domainId, array $companyConfig): bool
    {
        $affected = $this->update($domainId, [
            'company_config' => json_encode($companyConfig, JSON_UNESCAPED_UNICODE),
        ]);

        return $affected > 0;
    }

    /**
     * extension_config JSON 필드 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param array $extensionConfig 업데이트할 설정 배열
     * @return bool 성공 여부
     */
    public function updateExtensionConfig(int $domainId, array $extensionConfig): bool
    {
        $affected = $this->update($domainId, [
            'extension_config' => json_encode($extensionConfig, JSON_UNESCAPED_UNICODE),
        ]);

        return $affected !== false;
    }

    /**
     * extra_config JSON 필드 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param array $extraConfig 업데이트할 설정 배열
     * @return bool 성공 여부
     */
    public function updateExtraConfig(int $domainId, array $extraConfig): bool
    {
        $affected = $this->update($domainId, [
            'extra_config' => json_encode($extraConfig, JSON_UNESCAPED_UNICODE),
        ]);

        return $affected !== false;
    }

    // =========================================================================
    // 일괄 업데이트 메서드
    // =========================================================================

    /**
     * 일괄 상태 변경
     *
     * @param array $domainIds 도메인 ID 배열
     * @param string $status 변경할 상태
     * @return int 변경된 행 수
     */
    public function updateStatus(array $domainIds, string $status): int
    {
        if (empty($domainIds)) {
            return 0;
        }

        // 유효한 상태값 확인
        $validStatuses = ['active', 'inactive', 'blocked'];
        if (!in_array($status, $validStatuses, true)) {
            return 0;
        }

        return $this->getDb()->table($this->table)
            ->whereIn('domain_id', $domainIds)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    // =========================================================================
    // 통계 메서드
    // =========================================================================

    /**
     * 상태별 도메인 수 통계
     *
     * @return array ['active' => int, 'inactive' => int, 'blocked' => int]
     */
    public function getStatusCounts(): array
    {
        $result = $this->getDb()->table($this->table)
            ->select(['status', 'COUNT(*) as count'])
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'blocked' => 0,
        ];

        foreach ($result as $row) {
            $counts[$row['status']] = (int)$row['count'];
        }

        return $counts;
    }

    // =========================================================================
    // 타임스탬프 필드 설정
    // =========================================================================

    /**
     * 생성 타임스탬프 필드명
     */
    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    /**
     * 수정 타임스탬프 필드명
     */
    protected function getUpdatedAtField(): ?string
    {
        return 'updated_at';
    }
}
