<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardPermission;
use Mublo\Packages\Board\Enum\PermissionTargetType;
use Mublo\Packages\Board\Enum\PermissionType;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardPermission Repository
 *
 * 게시판 권한 데이터베이스 접근 담당
 *
 * 책임:
 * - board_permissions 테이블 CRUD
 * - 그룹/카테고리/게시판 관리자 권한 조회
 * - BoardPermission Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardPermissionRepository extends BaseRepository
{
    protected string $table = 'board_permissions';
    protected string $entityClass = BoardPermission::class;
    protected string $primaryKey = 'permission_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 수정 타임스탬프 없음
     */
    protected function getUpdatedAtField(): ?string
    {
        return null;
    }

    // ========================================
    // 권한 확인 메서드
    // ========================================

    /**
     * 특정 대상에 대한 회원의 권한 확인
     *
     * @param string $targetType 'group' | 'category' | 'board'
     * @param int $targetId 대상 ID
     * @param int $memberId 회원 ID
     * @param string $permissionType 권한 타입 (기본: admin)
     */
    public function hasPermission(
        string $targetType,
        int $targetId,
        int $memberId,
        string $permissionType = PermissionType::ADMIN->value
    ): bool {
        return $this->existsBy([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'member_id' => $memberId,
            'permission_type' => $permissionType,
        ]);
    }

    /**
     * 그룹 관리자 여부 확인
     */
    public function isGroupAdmin(int $groupId, int $memberId): bool
    {
        return $this->hasPermission(PermissionTargetType::GROUP->value, $groupId, $memberId);
    }

    /**
     * 카테고리 관리자 여부 확인
     */
    public function isCategoryAdmin(int $categoryId, int $memberId): bool
    {
        return $this->hasPermission(PermissionTargetType::CATEGORY->value, $categoryId, $memberId);
    }

    /**
     * 게시판 관리자 여부 확인
     */
    public function isBoardAdmin(int $boardId, int $memberId): bool
    {
        return $this->hasPermission(PermissionTargetType::BOARD->value, $boardId, $memberId);
    }

    // ========================================
    // 조회 메서드
    // ========================================

    /**
     * 특정 대상의 권한 목록 조회
     *
     * @param string $targetType 'group' | 'category' | 'board'
     * @param int $targetId 대상 ID
     * @return BoardPermission[]
     */
    public function findByTarget(string $targetType, int $targetId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->orderBy('permission_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 그룹의 관리자 목록 조회
     *
     * @param int $groupId 그룹 ID
     * @return BoardPermission[]
     */
    public function findGroupAdmins(int $groupId): array
    {
        return $this->findByTarget(PermissionTargetType::GROUP->value, $groupId);
    }

    /**
     * 카테고리의 관리자 목록 조회
     *
     * @param int $categoryId 카테고리 ID
     * @return BoardPermission[]
     */
    public function findCategoryAdmins(int $categoryId): array
    {
        return $this->findByTarget(PermissionTargetType::CATEGORY->value, $categoryId);
    }

    /**
     * 게시판의 관리자 목록 조회
     *
     * @param int $boardId 게시판 ID
     * @return BoardPermission[]
     */
    public function findBoardAdmins(int $boardId): array
    {
        return $this->findByTarget(PermissionTargetType::BOARD->value, $boardId);
    }

    /**
     * 특정 대상의 관리자 회원 ID 목록 조회
     *
     * @param string $targetType 'group' | 'category' | 'board'
     * @param int $targetId 대상 ID
     * @return int[] 회원 ID 배열
     */
    public function getAdminMemberIds(string $targetType, int $targetId): array
    {
        $permissions = $this->findByTarget($targetType, $targetId);

        return array_map(
            fn(BoardPermission $p) => $p->getMemberId(),
            array_filter($permissions, fn(BoardPermission $p) => $p->isAdmin())
        );
    }

    /**
     * 회원이 관리자인 모든 대상 조회
     *
     * @param int $memberId 회원 ID
     * @param string|null $targetType 대상 타입 필터 (null이면 전체)
     * @return BoardPermission[]
     */
    public function findByMember(int $memberId, ?string $targetType = null): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId);

        if ($targetType !== null) {
            $query->where('target_type', '=', $targetType);
        }

        $rows = $query->orderBy('target_type', 'ASC')
            ->orderBy('target_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인별 권한 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param string|null $targetType 대상 타입 필터
     * @return BoardPermission[]
     */
    public function findByDomain(int $domainId, ?string $targetType = null): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($targetType !== null) {
            $query->where('target_type', '=', $targetType);
        }

        $rows = $query->orderBy('target_type', 'ASC')
            ->orderBy('target_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    // ========================================
    // 권한 부여/해제 메서드
    // ========================================

    /**
     * 권한 부여
     *
     * @return int|null 생성된 permission_id (이미 존재하면 null)
     */
    public function grantPermission(
        int $domainId,
        string $targetType,
        int $targetId,
        int $memberId,
        string $permissionType = PermissionType::ADMIN->value
    ): ?int {
        // 이미 존재하는지 확인
        if ($this->hasPermission($targetType, $targetId, $memberId, $permissionType)) {
            return null;
        }

        return $this->create([
            'domain_id' => $domainId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'member_id' => $memberId,
            'permission_type' => $permissionType,
        ]);
    }

    /**
     * 권한 해제
     *
     * @return int 삭제된 행 수
     */
    public function revokePermission(
        string $targetType,
        int $targetId,
        int $memberId,
        string $permissionType = PermissionType::ADMIN->value
    ): int {
        return $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->where('member_id', '=', $memberId)
            ->where('permission_type', '=', $permissionType)
            ->delete();
    }

    /**
     * 특정 대상의 모든 권한 삭제
     *
     * 그룹/카테고리/게시판 삭제 시 호출
     */
    public function revokeAllByTarget(string $targetType, int $targetId): int
    {
        return $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->delete();
    }

    /**
     * 회원의 모든 권한 삭제
     *
     * 회원 삭제 시 호출 (FK CASCADE로도 처리되지만 명시적 호출용)
     */
    public function revokeAllByMember(int $memberId): int
    {
        return $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->delete();
    }

    /**
     * 특정 대상의 관리자 일괄 설정
     *
     * 기존 관리자를 모두 제거하고 새로운 관리자 목록으로 교체
     *
     * @param int $domainId 도메인 ID
     * @param string $targetType 대상 타입
     * @param int $targetId 대상 ID
     * @param int[] $memberIds 새로운 관리자 회원 ID 목록
     * @param string $permissionType 권한 타입
     */
    public function setAdmins(
        int $domainId,
        string $targetType,
        int $targetId,
        array $memberIds,
        string $permissionType = PermissionType::ADMIN->value
    ): void {
        // 기존 권한 삭제
        $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->where('permission_type', '=', $permissionType)
            ->delete();

        // 새로운 권한 추가
        foreach ($memberIds as $memberId) {
            $this->create([
                'domain_id' => $domainId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'member_id' => (int) $memberId,
                'permission_type' => $permissionType,
            ]);
        }
    }

    // ========================================
    // 회원 정보 포함 조회
    // ========================================

    /**
     * 특정 대상의 관리자 목록 (회원 정보 포함)
     *
     * Note: members 테이블에는 user_id, level_value만 존재
     * name, email은 member_field_values 테이블에 암호화되어 저장됨
     *
     * @return array [['permission_id' => ..., 'member_id' => ..., 'user_id' => ..., 'level_value' => ...], ...]
     */
    public function findAdminsWithMemberInfo(string $targetType, int $targetId): array
    {
        return $this->getDb()->table($this->table . ' AS p')
            ->join('members AS m', 'p.member_id', '=', 'm.member_id')
            ->where('p.target_type', '=', $targetType)
            ->where('p.target_id', '=', $targetId)
            ->select([
                'p.permission_id',
                'p.member_id',
                'p.permission_type',
                'p.created_at',
                'm.user_id',
                'm.level_value',
            ])
            ->orderBy('p.permission_id', 'ASC')
            ->get();
    }
}
