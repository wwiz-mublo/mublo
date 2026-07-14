<?php
namespace Mublo\Repository\Member;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * AdminPermission Repository
 *
 * 관리자 접근 권한 (Negative ACL) 데이터베이스 접근 담당
 *
 * 책임:
 * - member_level_denied_menus 테이블 CRUD
 * - 권한 차단 목록 조회
 *
 * Negative ACL:
 * - 테이블에 등록된 메뉴+액션만 차단
 * - 미등록은 허용 (화이트리스트가 아닌 블랙리스트 방식)
 * - is_super=1인 최고관리자는 체크 안 함 (모든 권한)
 *
 * denied_actions 형식:
 * - 개별 액션: 'list,read,write,edit,delete,download' (콤마 구분)
 * - 전체 차단: '*'
 */
class AdminPermissionRepository extends BaseRepository
{
    protected string $table = 'member_level_denied_menus';
    protected string $entityClass = 'stdClass';  // 단순 데이터 객체 사용
    protected string $primaryKey = 'id';

    /**
     * 허용 가능한 액션 목록
     */
    public const ALLOWED_ACTIONS = ['list', 'read', 'write', 'edit', 'delete', 'download'];

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    // ========================================
    // 조회
    // ========================================

    /**
     * 도메인별 전체 차단 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array
     */
    public function getByDomain(int $domainId): array
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('level_value', 'ASC')
            ->orderBy('menu_code', 'ASC')
            ->get();
    }

    /**
     * 도메인+레벨별 차단 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @return array [menu_code => denied_actions, ...]
     */
    public function getDeniedMenusByLevel(int $domainId, int $levelValue): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['menu_code', 'denied_actions'])
            ->where('domain_id', '=', $domainId)
            ->where('level_value', '=', $levelValue)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['menu_code']] = $row['denied_actions'];
        }

        return $result;
    }

    /**
     * 복수 도메인+레벨별 차단 목록 조회 (상위 도메인 상속용)
     *
     * 여러 도메인의 차단 규칙을 합집합으로 병합
     * 같은 menu_code가 여러 도메인에 있으면 denied_actions를 합산
     *
     * @param array $domainIds 도메인 ID 배열
     * @param int $levelValue 레벨 값
     * @return array [menu_code => denied_actions, ...]
     */
    public function getDeniedMenusByLevelForDomains(array $domainIds, int $levelValue): array
    {
        if (empty($domainIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
        $params = array_merge($domainIds, [$levelValue]);

        $sql = "SELECT menu_code, denied_actions
                FROM {$this->table}
                WHERE domain_id IN ({$placeholders}) AND level_value = ?";

        $rows = $this->getDb()->select($sql, $params);

        // 합집합 병합: 같은 menu_code의 denied_actions를 합산
        $result = [];
        foreach ($rows as $row) {
            $code = $row['menu_code'];
            $actions = $row['denied_actions'];

            if (!isset($result[$code])) {
                $result[$code] = $actions;
                continue;
            }

            // 이미 전체 차단이면 유지
            if ($result[$code] === '*') {
                continue;
            }

            // 새로 들어온 것이 전체 차단이면 덮어쓰기
            if ($actions === '*') {
                $result[$code] = '*';
                continue;
            }

            // 개별 액션 합집합
            $existing = array_map('trim', explode(',', $result[$code]));
            $incoming = array_map('trim', explode(',', $actions));
            $merged = array_unique(array_merge($existing, $incoming));
            $result[$code] = implode(',', $merged);
        }

        return $result;
    }

    /**
     * 특정 메뉴 코드의 차단 정보 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string $menuCode 메뉴 코드
     * @return array|null ['id' => int, 'denied_actions' => string] or null
     */
    public function findDeniedMenu(int $domainId, int $levelValue, string $menuCode): ?array
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('level_value', '=', $levelValue)
            ->where('menu_code', '=', $menuCode)
            ->first();
    }

    /**
     * 특정 액션이 차단되었는지 확인
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string $menuCode 메뉴 코드
     * @param string $action 확인할 액션 (list, read, write, edit, delete, download)
     * @return bool 차단이면 true
     */
    public function isActionDenied(int $domainId, int $levelValue, string $menuCode, string $action): bool
    {
        $row = $this->findDeniedMenu($domainId, $levelValue, $menuCode);

        if (!$row) {
            return false;  // 등록 안 됨 = 허용
        }

        $deniedActions = $row['denied_actions'];

        // 전체 차단
        if ($deniedActions === '*') {
            return true;
        }

        // 개별 액션 체크
        $actions = array_map('trim', explode(',', $deniedActions));
        return in_array($action, $actions, true);
    }

    /**
     * 상위 메뉴 코드 포함 차단 체크
     *
     * 예: '003_001'이면 '003'도 같이 체크
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string $menuCode 메뉴 코드
     * @param string $action 확인할 액션
     * @return bool 차단이면 true
     */
    public function isActionDeniedWithParent(int $domainId, int $levelValue, string $menuCode, string $action): bool
    {
        // 현재 메뉴 체크
        if ($this->isActionDenied($domainId, $levelValue, $menuCode, $action)) {
            return true;
        }

        // 상위 메뉴 체크 (예: 003_001 → 003)
        if (str_contains($menuCode, '_')) {
            $parentCode = explode('_', $menuCode)[0];
            return $this->isActionDenied($domainId, $levelValue, $parentCode, $action);
        }

        return false;
    }

    // ========================================
    // 생성/수정/삭제
    // ========================================

    /**
     * 차단 메뉴 등록 또는 수정 (upsert)
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string $menuCode 메뉴 코드
     * @param string|array $deniedActions 차단 액션 ('*' 또는 배열)
     * @return int|null 생성된 ID 또는 null
     */
    public function saveDeniedMenu(int $domainId, int $levelValue, string $menuCode, string|array $deniedActions): ?int
    {
        // 배열이면 문자열로 변환
        if (is_array($deniedActions)) {
            $deniedActions = implode(',', $deniedActions);
        }

        // 빈 값이면 삭제
        if (empty($deniedActions)) {
            $this->deleteDeniedMenu($domainId, $levelValue, $menuCode);
            return null;
        }

        $existing = $this->findDeniedMenu($domainId, $levelValue, $menuCode);

        if ($existing) {
            // 수정
            $this->getDb()->table($this->table)
                ->where('id', '=', $existing['id'])
                ->update(['denied_actions' => $deniedActions]);
            return $existing['id'];
        } else {
            // 생성
            return $this->getDb()->table($this->table)->insert([
                'domain_id' => $domainId,
                'level_value' => $levelValue,
                'menu_code' => $menuCode,
                'denied_actions' => $deniedActions,
            ]);
        }
    }

    /**
     * 레벨별 차단 메뉴 일괄 저장
     *
     * 기존 데이터 삭제 후 새로 등록
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param array $deniedMenus [menu_code => denied_actions, ...]
     * @return int 저장된 개수
     */
    public function saveAllForLevel(int $domainId, int $levelValue, array $deniedMenus): int
    {
        // 기존 데이터 삭제
        $this->deleteByLevel($domainId, $levelValue);

        // 새 데이터 등록
        $count = 0;
        foreach ($deniedMenus as $menuCode => $deniedActions) {
            if (!empty($deniedActions)) {
                $this->saveDeniedMenu($domainId, $levelValue, $menuCode, $deniedActions);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 특정 차단 메뉴 삭제
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string $menuCode 메뉴 코드
     * @return int 삭제된 행 수
     */
    public function deleteDeniedMenu(int $domainId, int $levelValue, string $menuCode): int
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('level_value', '=', $levelValue)
            ->where('menu_code', '=', $menuCode)
            ->delete();
    }

    /**
     * 레벨별 전체 삭제
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @return int 삭제된 행 수
     */
    public function deleteByLevel(int $domainId, int $levelValue): int
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('level_value', '=', $levelValue)
            ->delete();
    }

    /**
     * ID로 삭제
     *
     * @param int $id 차단 메뉴 ID
     * @return int 삭제된 행 수
     */
    public function deleteById(int $id): int
    {
        return $this->delete($id);
    }

    /**
     * 여러 ID 일괄 삭제
     *
     * @param array $ids 삭제할 ID 목록
     * @return int 삭제된 행 수
     */
    public function deleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM " . $this->getDb()->prefixTable($this->table) . " WHERE id IN ({$placeholders})";

        return $this->getDb()->execute($sql, array_values($ids));
    }

    // ========================================
    // 관리자 목록용
    // ========================================

    /**
     * 도메인별 권한 제한 개수 조회
     *
     * @param int $domainId 도메인 ID
     * @param int|null $levelValue 레벨 필터 (null이면 전체)
     * @return int
     */
    public function countByDomain(int $domainId, ?int $levelValue = null): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($levelValue !== null) {
            $query->where('level_value', '=', $levelValue);
        }

        return $query->count();
    }

    /**
     * 도메인별 권한 제한 목록 조회 (페이지네이션)
     *
     * @param int $domainId 도메인 ID
     * @param int|null $levelValue 레벨 필터 (null이면 전체)
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return array
     */
    public function findByDomainPaginated(int $domainId, ?int $levelValue, int $limit, int $offset): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($levelValue !== null) {
            $query->where('level_value', '=', $levelValue);
        }

        return $query
            ->orderBy('level_value', 'ASC')
            ->orderBy('menu_code', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * 페이지네이션 목록 (관리자 화면용)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $filters ['level_value' => int]
     * @return array ['data' => array, 'total' => int, 'page' => int, ...]
     */
    public function getPaginatedList(int $domainId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        // 필터 적용
        if (!empty($filters['level_value'])) {
            $query->where('level_value', '=', (int) $filters['level_value']);
        }

        // 카운트
        $total = (clone $query)->count();

        // 데이터 조회
        $rows = $query
            ->orderBy('level_value', 'ASC')
            ->orderBy('menu_code', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * 도메인별 레벨별 그룹화된 목록 (UI 표시용)
     *
     * @param int $domainId 도메인 ID
     * @return array [level_value => [['menu_code' => ..., 'denied_actions' => ...], ...], ...]
     */
    public function getGroupedByLevel(int $domainId): array
    {
        $rows = $this->getByDomain($domainId);

        $grouped = [];
        foreach ($rows as $row) {
            $levelValue = $row['level_value'];
            if (!isset($grouped[$levelValue])) {
                $grouped[$levelValue] = [];
            }
            $grouped[$levelValue][] = [
                'id' => $row['id'],
                'menu_code' => $row['menu_code'],
                'denied_actions' => $row['denied_actions'],
            ];
        }

        return $grouped;
    }

    // ========================================
    // 유틸리티
    // ========================================

    /**
     * denied_actions 문자열을 배열로 변환
     *
     * @param string $deniedActions
     * @return array
     */
    public static function parseActions(string $deniedActions): array
    {
        if ($deniedActions === '*') {
            return self::ALLOWED_ACTIONS;
        }

        return array_filter(array_map('trim', explode(',', $deniedActions)));
    }

    /**
     * 타임스탬프 필드 오버라이드 (updated_at 없음)
     */
    protected function getUpdatedAtField(): ?string
    {
        return null;
    }
}
