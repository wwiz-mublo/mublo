<?php
namespace Mublo\Repository\Member;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * MemberFieldRepository
 *
 * 회원 추가 필드 정의 (member_fields) 데이터베이스 접근
 *
 * 책임:
 * - member_fields 테이블 CRUD
 * - 필드 정의 조회
 */
class MemberFieldRepository extends BaseRepository
{
    protected string $table = 'member_fields';
    protected string $entityClass = \stdClass::class;
    protected string $primaryKey = 'field_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 도메인별 필드 목록 조회
     */
    public function findByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 목록 표시 필드 조회 (is_visible_list = 1)
     */
    public function findListVisibleByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_visible_list', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 검색 가능 필드 조회 (is_searched = 1)
     */
    public function findSearchableByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->select(['field_id', 'field_name', 'field_label', 'is_encrypted'])
            ->where('domain_id', '=', $domainId)
            ->where('is_searched', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 회원가입 표시 필드 조회 (is_visible_signup = 1, 관리자 전용 제외)
     */
    public function findSignupVisibleByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_visible_signup', '=', 1)
            ->where('is_admin_only', '=', 0)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 프론트용 필드 조회 (관리자 전용 제외)
     */
    public function findFrontByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_admin_only', '=', 0)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 필드명으로 조회 (중복 검사용)
     */
    public function findByDomainAndName(int $domainId, string $fieldName): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('field_name', '=', $fieldName)
            ->first();
    }

    /**
     * 필드명 중복 검사 (수정 시 자신 제외)
     */
    public function existsByDomainAndName(int $domainId, string $fieldName, ?int $excludeId = null): bool
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('field_name', '=', $fieldName);

        if ($excludeId !== null) {
            $query->where('field_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 도메인별 최대 정렬 순서 조회
     */
    public function getMaxSortOrder(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->max('sort_order') ?? 0;
    }

    /**
     * 정렬 순서 업데이트
     */
    public function updateSortOrder(int $fieldId, int $domainId, int $sortOrder): int
    {
        return $this->db->table($this->table)
            ->where('field_id', '=', $fieldId)
            ->where('domain_id', '=', $domainId)
            ->update(['sort_order' => $sortOrder]);
    }

    /**
     * 필드 ID 목록으로 필드 정의 조회
     *
     * 전체 컬럼을 반환한다 — 과거 좁은 select 가 is_unique/is_admin_only 를 빠뜨려
     * 저장 경계·유일성 키 계산이 무력화된 전례가 있으므로 컬럼을 추리지 않는다.
     */
    public function findByIds(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));

        return $this->db->table($this->table)
            ->whereRaw("field_id IN ({$placeholders})", array_values($fieldIds))
            ->get();
    }

    /**
     * Entity 변환 없이 배열 반환
     */
    protected function toEntity(array $row): object
    {
        return (object) $row;
    }

    /**
     * 생성 타임스탬프 없음
     */
    protected function getCreatedAtField(): ?string
    {
        return null;
    }

    /**
     * 수정 타임스탬프 없음
     */
    protected function getUpdatedAtField(): ?string
    {
        return null;
    }
}
