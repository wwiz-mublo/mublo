<?php
declare(strict_types=1);

namespace Mublo\Service\Admin;

use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Member\AdminPermissionRepository;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Core\Result\Result;

/**
 * AdminPermissionService
 *
 * 관리자 메뉴 권한 체크 서비스 (네거티브 방식)
 * - 기본: 모든 관리자(is_admin=1)는 모든 메뉴 접근 가능
 * - 차단 등록된 메뉴+액션만 접근 불가
 * - 슈퍼관리자(is_super=1)는 모든 권한 체크 패스
 *
 * 권한 액션:
 * - list: 목록 접근
 * - read: 상세 읽기
 * - write: 작성
 * - edit: 수정
 * - delete: 삭제
 * - download: 파일 다운로드
 */
class AdminPermissionService
{
    private Database $db;
    private AdminPermissionRepository $permissionRepository;
    private MemberLevelRepository $levelRepository;

    /**
     * 허용 가능한 액션 목록
     */
    public const ALLOWED_ACTIONS = ['list', 'read', 'write', 'edit', 'delete', 'download'];

    /**
     * UI에서 사용하는 액션 그룹
     * 체크박스 5개로 표시 (명확한 구분)
     *
     * - 접근(l): 목록 페이지 접근 가능 여부
     * - 읽기(r): 상세 페이지 읽기 가능 여부
     * - 쓰기(w): 작성/수정 가능 여부 (write 차단 시 edit도 자동 차단)
     * - 삭제(d): 삭제 가능 여부
     * - 다운로드(f): 파일 다운로드 가능 여부
     */
    public const ACTION_GROUPS = [
        'l' => ['list'],              // 접근: 목록 페이지
        'r' => ['read'],              // 읽기: 상세 페이지
        'w' => ['write', 'edit'],     // 쓰기: 작성 + 수정
        'd' => ['delete'],            // 삭제
        'f' => ['download'],          // 다운로드
    ];

    /**
     * URL에 동작이 명시된 경우의 액션 매핑.
     *
     * HTTP 메서드가 권한 판별의 기본 안전망이며, 이 표는 delete/write 같은 더 세밀한
     * 권한을 구분하거나 GET 기반 편집 화면을 판별할 때만 사용한다. 새 작업 URL이 이 표에
     * 없더라도 POST/PUT/PATCH가 list 권한으로 떨어지지는 않는다.
     */
    private const MUTATION_ACTION_KEYWORDS = [
        // 권한이 더 강한 순서대로 검사한다.
        'download' => 'download',
        'export' => 'download',
        'delete' => 'delete',
        'remove' => 'delete',
        'destroy' => 'delete',
        'purge' => 'delete',
        'create' => 'write',
        'store' => 'write',
        'upload' => 'write',
        'import' => 'write',
        'copy' => 'write',
        'edit' => 'edit',
        'update' => 'edit',
        'modify' => 'edit',
        'apply' => 'edit',
        'rollback' => 'edit',
        'restore' => 'edit',
        'revert' => 'edit',
        'publish' => 'edit',
        'reorder' => 'edit',
        'order-set' => 'edit',
        'toggle' => 'edit',
        'move' => 'edit',
        'adjust' => 'edit',
        'reset' => 'edit',
    ];

    /** POST 조회 API처럼 안전하지 않은 HTTP 메서드를 의도적으로 쓰는 조회 동작. */
    private const READ_ACTION_KEYWORDS = [
        'view' => 'read',
        'detail' => 'read',
        'check' => 'read',
        'preview' => 'read',
        'search' => 'read',
        'index' => 'list',
        'list' => 'list',
    ];

    private const SAFE_HTTP_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        Database $db,
        AdminPermissionRepository $permissionRepository,
        MemberLevelRepository $levelRepository
    ) {
        $this->db = $db;
        $this->permissionRepository = $permissionRepository;
        $this->levelRepository = $levelRepository;
    }

    // =========================================================================
    // 응답 헬퍼
    // =========================================================================

    private function info(string $message, array $context = []): void
    {
        // 로깅 (현재 no-op, 추후 Logger 연결)
    }

    /**
     * URL 경로와 HTTP 메서드에서 CRUD 액션 판별
     *
     * URL segment 단위로 키워드 매칭 (정확한 일치 또는 -{keyword} 접미사).
     * camelCase 라우트(listDelete/listModify)는 kebab-case로 정규화한다.
     * 예: /admin/domains/status-edit/1 → 'edit' (segment 'status-edit' ends with '-edit')
     *     /admin/editors → 'list' (segment 'editors' !== 'edit')
     *
     * 동작 이름이 없는 POST/PUT/PATCH 등은 edit로 판별한다. URL 명명 누락이 조회 권한으로
     * 축소되는 fail-open을 막기 위한 기본값이다. $httpMethod를 생략하면 기존 호출부와의
     * 호환을 위해 URL만으로 판별한다.
     *
     * @param string $path URL 경로 (예: /admin/member/create)
     * @param string|null $httpMethod HTTP 메서드
     * @return string 액션 (list, read, write, edit, delete, download)
     */
    public function detectAction(string $path, ?string $httpMethod = null): string
    {
        $segments = array_map(
            static function (string $segment): string {
                $segment = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $segment);
                return strtolower($segment);
            },
            explode('/', trim($path, '/'))
        );

        $explicitMutation = $this->matchActionKeyword($segments, self::MUTATION_ACTION_KEYWORDS);
        if ($explicitMutation !== null) {
            return $explicitMutation;
        }

        $method = $httpMethod !== null ? strtoupper($httpMethod) : null;
        if ($method === 'DELETE') {
            return 'delete';
        }

        $explicitRead = $this->matchActionKeyword($segments, self::READ_ACTION_KEYWORDS);
        if ($explicitRead !== null) {
            return $explicitRead;
        }

        if ($method !== null && !in_array($method, self::SAFE_HTTP_METHODS, true)) {
            return 'edit';
        }

        // 마지막 segment가 숫자면 read (예: /admin/member/123)
        $lastSegment = end($segments);
        if ($lastSegment !== false && ctype_digit($lastSegment)) {
            return 'read';
        }

        return 'list';
    }

    /**
     * @param string[] $segments
     * @param array<string, string> $keywords
     */
    private function matchActionKeyword(array $segments, array $keywords): ?string
    {
        // 리소스 이름보다 실제 엔드포인트에 가까운 마지막 세그먼트의 동작을 우선한다.
        // 예: /admin/import/listModify 는 import(write)가 아니라 listModify(edit)이다.
        foreach (array_reverse($segments) as $segment) {
            foreach ($keywords as $keyword => $action) {
                if ($segment === $keyword || str_ends_with($segment, '-' . $keyword)) {
                    return $action;
                }
            }
        }

        return null;
    }

    /**
     * 권한 차단 여부 확인
     *
     * 상위 도메인 권한 상속:
     * domain_group이 제공되면 상위 도메인 체인의 차단 규칙도 함께 확인
     * 어느 도메인에서든 차단이면 차단 확정 (합집합)
     *
     * @param int $domainId 현재 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string $menuCode 메뉴 코드 (activeCode)
     * @param string $action 액션 (list, read, write, edit, delete, download)
     * @param string|null $domainGroup 도메인 그룹 (예: '1/2/5', 상위 상속용)
     * @return bool 차단되면 true, 허용이면 false
     */
    public function isDenied(int $domainId, int $levelValue, string $menuCode, string $action, ?string $domainGroup = null): bool
    {
        // 빈 메뉴코드는 체크 안함 (허용)
        if (empty($menuCode)) {
            return false;
        }

        // 체크할 메뉴 코드 목록 (현재 + 상위 메뉴)
        $codesToCheck = [$menuCode];

        $parentCode = $this->extractParentCode($menuCode);
        if ($parentCode !== null) {
            $codesToCheck[] = $parentCode;
        }

        // 체크할 도메인 ID 목록 (현재 + 상위 도메인 체인)
        $domainIds = $this->getDomainChain($domainId, $domainGroup);

        // denied_menus 조회 (복수 도메인 + 복수 메뉴코드)
        $domainPlaceholders = implode(',', array_fill(0, count($domainIds), '?'));
        $codePlaceholders = implode(',', array_fill(0, count($codesToCheck), '?'));
        $params = array_merge($domainIds, [$levelValue], $codesToCheck);

        $tableName = 'member_level_denied_menus';
        $sql = "SELECT menu_code, denied_actions
                FROM {$tableName}
                WHERE domain_id IN ({$domainPlaceholders})
                  AND level_value = ?
                  AND menu_code IN ({$codePlaceholders})";

        $deniedList = $this->db->select($sql, $params);

        // 차단 여부 판단
        foreach ($deniedList as $denied) {
            $actions = $denied['denied_actions'];

            // 전체 차단 (*)
            if ($actions === '*') {
                return true;
            }

            // 특정 액션 차단
            $deniedActions = array_map('trim', explode(',', $actions));
            if (in_array($action, $deniedActions, true)) {
                return true;
            }
        }

        // 차단 목록에 없으면 허용
        return false;
    }

    /**
     * 권한 허용 여부 확인 (isDenied의 반대)
     */
    public function isAllowed(int $domainId, int $levelValue, string $menuCode, string $action, ?string $domainGroup = null): bool
    {
        return !$this->isDenied($domainId, $levelValue, $menuCode, $action, $domainGroup);
    }

    /**
     * 특정 메뉴에서 허용된 액션 목록 반환
     *
     * @return array ['list' => true, 'read' => true, 'write' => false, ...]
     */
    public function getAllowedActions(int $domainId, int $levelValue, string $menuCode, ?string $domainGroup = null): array
    {
        $actions = ['list', 'read', 'write', 'edit', 'delete', 'download'];
        $result = [];

        foreach ($actions as $action) {
            $result[$action] = !$this->isDenied($domainId, $levelValue, $menuCode, $action, $domainGroup);
        }

        return $result;
    }

    /**
     * 상위 메뉴 코드 추출
     *
     * @param string $menuCode 메뉴 코드
     * @return string|null 상위 코드 (없으면 null)
     *
     * 예시:
     * - Core: '003_001' → '003'
     * - Plugin: 'P_MemberPoint_001' → 'P_MemberPoint'
     * - Package: 'K_Shop_001' → 'K_Shop'
     * - 최상위: '003' → null (상위 없음)
     */
    private function extractParentCode(string $menuCode): ?string
    {
        // 언더스코어가 없으면 최상위 메뉴
        if (!str_contains($menuCode, '_')) {
            return null;
        }

        // 마지막 언더스코어 위치 찾기
        $lastUnderscorePos = strrpos($menuCode, '_');
        if ($lastUnderscorePos === false) {
            return null;
        }

        // 마지막 언더스코어 이전 부분이 상위 코드
        $parentCode = substr($menuCode, 0, $lastUnderscorePos);

        // 상위 코드가 비어있으면 null
        return $parentCode !== '' ? $parentCode : null;
    }

    /**
     * 도메인 체인 추출 (현재 + 상위 도메인 ID 배열)
     *
     * domain_group = '1/2/5' → [5, 2, 1]
     * domain_group = null → [domainId]
     *
     * @param int $domainId 현재 도메인 ID
     * @param string|null $domainGroup 도메인 그룹 경로
     * @return array 도메인 ID 배열 (현재 포함, 상위순)
     */
    private function getDomainChain(int $domainId, ?string $domainGroup): array
    {
        if (empty($domainGroup)) {
            return [$domainId];
        }

        // domain_group 파싱: '1/2/5' → [1, 2, 5]
        $parts = array_filter(array_map('intval', explode('/', $domainGroup)));

        if (empty($parts)) {
            return [$domainId];
        }

        // 중복 제거 후 반환 (현재 domainId가 포함되지 않았을 경우 대비)
        $chain = array_unique(array_merge([$domainId], array_reverse($parts)));

        return array_values($chain);
    }

    // =========================================================================
    // 권한 체크 (Member 객체 사용)
    // =========================================================================

    /**
     * 특정 회원이 메뉴에 접근 가능한지 확인
     *
     * @param Member $member 회원 객체
     * @param int $domainId 도메인 ID
     * @param string $menuCode 메뉴 코드 (activeCode)
     * @param string $action 액션 (list, read, write, edit, delete, download)
     * @return bool 접근 가능하면 true
     */
    public function canAccess(Member $member, int $domainId, string $menuCode, string $action = 'list', ?string $domainGroup = null): bool
    {
        // 최고관리자는 모든 접근 허용
        if ($member->isSuper()) {
            return true;
        }

        // 관리자가 아니면 접근 불가
        if (!$member->isAdmin()) {
            return false;
        }

        // Negative ACL 체크: 차단되어 있으면 false
        return !$this->isDenied($domainId, $member->getLevelValue(), $menuCode, $action, $domainGroup);
    }

    // =========================================================================
    // 조회 (관리자 UI용)
    // =========================================================================

    /**
     * 도메인별 차단 목록 조회 (레벨별 그룹화)
     *
     * @param int $domainId 도메인 ID
     * @return array [level_value => [['id' => int, 'menu_code' => string, 'denied_actions' => string], ...], ...]
     */
    public function getGroupedByLevel(int $domainId): array
    {
        return $this->permissionRepository->getGroupedByLevel($domainId);
    }

    /**
     * 페이지네이션이 적용된 권한 제한 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param string $filterLevelValue 레벨 필터 (빈 문자열이면 전체)
     * @param int $page 현재 페이지
     * @param int $perPage 페이지당 항목 수
     * @return array ['items' => [...], 'pagination' => [...]]
     */
    public function getPaginatedList(int $domainId, string $filterLevelValue = '', int $page = 1, int $perPage = 20): array
    {
        $levelValue = $filterLevelValue !== '' ? (int) $filterLevelValue : null;

        // 전체 개수 조회
        $total = $this->permissionRepository->countByDomain($domainId, $levelValue);

        // 페이지네이션 계산
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        // 데이터 조회
        $items = $this->permissionRepository->findByDomainPaginated($domainId, $levelValue, $perPage, $offset);

        // 레벨명 매핑
        $levelNames = $this->getAdminLevelOptions(false);
        foreach ($items as &$item) {
            $item['level_name'] = $levelNames[$item['level_value']] ?? "레벨 {$item['level_value']}";
        }

        return [
            'items' => $items,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $total,
                'perPage' => $perPage,
            ],
        ];
    }

    /**
     * 도메인+레벨별 차단 목록 조회
     *
     * domain_group이 제공되면 상위 도메인 체인의 차단 규칙도 합산
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @param string|null $domainGroup 도메인 그룹 (상위 상속용)
     * @return array [menu_code => denied_actions, ...]
     */
    public function getDeniedMenusByLevel(int $domainId, int $levelValue, ?string $domainGroup = null): array
    {
        $domainIds = $this->getDomainChain($domainId, $domainGroup);

        // 단일 도메인이면 기존 방식 유지 (최적화)
        if (count($domainIds) === 1) {
            return $this->permissionRepository->getDeniedMenusByLevel($domainId, $levelValue);
        }

        // 복수 도메인 합집합 조회
        return $this->permissionRepository->getDeniedMenusByLevelForDomains($domainIds, $levelValue);
    }

    /**
     * 관리자 레벨 목록 조회 (is_admin=1 또는 is_super=1, is_super=1 제외 옵션)
     *
     * @param bool $excludeSuper 최고관리자 제외 여부
     * @return array
     */
    public function getAdminLevels(bool $excludeSuper = true): array
    {
        $levels = $this->levelRepository->getAdminLevels();

        if ($excludeSuper) {
            $levels = array_filter($levels, fn($level) => !$level->isSuper());
        }

        return array_values($levels);
    }

    /**
     * 관리자 레벨 옵션 (select용)
     *
     * @param bool $excludeSuper 최고관리자 제외
     * @return array [level_value => level_name, ...]
     */
    public function getAdminLevelOptions(bool $excludeSuper = true): array
    {
        $levels = $this->getAdminLevels($excludeSuper);

        $options = [];
        foreach ($levels as $level) {
            $options[$level->getLevelValue()] = $level->getLevelName();
        }

        return $options;
    }

    // =========================================================================
    // 저장
    // =========================================================================

    /**
     * 권한 저장 (메뉴 + 액션 그룹)
     *
     * 폼 데이터 형식:
     * - formData[level_value] = 100
     * - formData[menu_code] = '003'  (1차 메뉴)
     * - formData[submenu][003_001] = ['r', 'w']  (차단할 액션 그룹)
     * - formData[submenu][003_002] = ['r']
     *
     * @param int $domainId 도메인 ID
     * @param array $formData 폼 데이터
     * @return Result
     */
    public function saveFromForm(int $domainId, array $formData): Result
    {
        $levelValue = (int) ($formData['level_value'] ?? 0);

        if ($levelValue === 0) {
            return Result::failure('등급을 선택해주세요.');
        }

        // 레벨 검증
        $level = $this->levelRepository->findByValue($levelValue);
        if (!$level) {
            return Result::failure('존재하지 않는 등급입니다.');
        }

        if ($level->isSuper()) {
            return Result::failure('최고관리자 등급은 권한을 제한할 수 없습니다.');
        }

        if (!$level->isAdmin()) {
            return Result::failure('관리자 등급만 권한을 설정할 수 있습니다.');
        }

        // 서브메뉴별 차단 액션
        $submenu = $formData['submenu'] ?? [];
        $savedCount = 0;
        $repo = $this->permissionRepository;

        foreach ($submenu as $menuCode => $checkedGroups) {
            // 체크된 액션 그룹을 실제 액션으로 변환
            $deniedActions = $this->expandActionGroups((array) $checkedGroups);

            if (!empty($deniedActions)) {
                $repo->saveDeniedMenu($domainId, $levelValue, $menuCode, $deniedActions);
                $savedCount++;
            } else {
                // 체크 해제 시 삭제
                $repo->deleteDeniedMenu($domainId, $levelValue, $menuCode);
            }
        }

        $this->info('Admin permissions saved from form', [
            'domain_id' => $domainId,
            'level_value' => $levelValue,
            'saved_count' => $savedCount,
        ]);

        return Result::success('권한이 저장되었습니다.', ['saved_count' => $savedCount]);
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 권한 삭제 (단일)
     *
     * @param int $id 권한 ID
     * @return Result
     */
    public function delete(int $id): Result
    {
        $affected = $this->permissionRepository->deleteById($id);

        if ($affected === 0) {
            return Result::failure('삭제할 권한을 찾을 수 없습니다.');
        }

        $this->info('Admin permission deleted', ['id' => $id]);

        return Result::success('권한이 삭제되었습니다.');
    }

    /**
     * 권한 일괄 삭제
     *
     * @param array $ids 삭제할 ID 배열
     * @return Result
     */
    public function deleteBulk(array $ids): Result
    {
        if (empty($ids)) {
            return Result::failure('삭제할 권한을 선택해주세요.');
        }

        $affected = $this->permissionRepository->deleteByIds($ids);

        $this->info('Admin permissions bulk deleted', [
            'ids' => $ids,
            'affected' => $affected,
        ]);

        return Result::success("{$affected}개의 권한이 삭제되었습니다.", ['deleted_count' => $affected]);
    }

    /**
     * 레벨별 전체 삭제
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 레벨 값
     * @return Result
     */
    public function deleteByLevel(int $domainId, int $levelValue): Result
    {
        $affected = $this->permissionRepository->deleteByLevel($domainId, $levelValue);

        $this->info('Admin permissions deleted by level', [
            'domain_id' => $domainId,
            'level_value' => $levelValue,
            'affected' => $affected,
        ]);

        return Result::success('등급의 모든 권한 제한이 해제되었습니다.', ['deleted_count' => $affected]);
    }

    // =========================================================================
    // 유틸리티
    // =========================================================================

    /**
     * 액션 그룹을 실제 액션 배열로 확장
     *
     * @param array $groups ['r', 'w', 'd', 'f']
     * @return array ['list', 'read', 'write', 'edit', 'delete', 'download']
     */
    public function expandActionGroups(array $groups): array
    {
        $actions = [];

        foreach ($groups as $group) {
            if (isset(self::ACTION_GROUPS[$group])) {
                $actions = array_merge($actions, self::ACTION_GROUPS[$group]);
            }
        }

        return array_unique($actions);
    }

    /**
     * 실제 액션 배열을 액션 그룹으로 축약
     *
     * @param array|string $actions ['list', 'read', 'write'] 또는 'list,read,write' 또는 '*'
     * @return array ['r', 'w'] (체크된 그룹)
     */
    public function contractToActionGroups(array|string $actions): array
    {
        if ($actions === '*') {
            return ['l', 'r', 'w', 'd', 'f'];
        }

        if (is_string($actions)) {
            $actions = array_map('trim', explode(',', $actions));
        }

        $groups = [];

        foreach (self::ACTION_GROUPS as $group => $groupActions) {
            // 그룹의 모든 액션이 포함되어 있으면 해당 그룹 체크
            $hasAll = true;
            foreach ($groupActions as $action) {
                if (!in_array($action, $actions, true)) {
                    $hasAll = false;
                    break;
                }
            }
            if ($hasAll) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * 액션 라벨 반환
     *
     * @return array [action => label, ...]
     */
    public static function getActionLabels(): array
    {
        return [
            'list' => '목록',
            'read' => '읽기',
            'write' => '작성',
            'edit' => '수정',
            'delete' => '삭제',
            'download' => '다운로드',
        ];
    }

    /**
     * 액션 그룹 라벨 반환
     *
     * @return array [group => label, ...]
     */
    public static function getActionGroupLabels(): array
    {
        return [
            'l' => '접근',
            'r' => '읽기',
            'w' => '쓰기',
            'd' => '삭제',
            'f' => '다운로드',
        ];
    }
}
