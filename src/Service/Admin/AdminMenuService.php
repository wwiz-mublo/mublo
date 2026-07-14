<?php

namespace Mublo\Service\Admin;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * AdminMenuService
 *
 * 관리자 메뉴 관리 서비스
 * - 코어 메뉴 정의
 * - EventSystem을 통한 플러그인/패키지 메뉴 통합
 * - 메뉴 캐싱
 *
 * Code 체계:
 * - Core: 숫자_숫자 (001, 002, 003_001)
 * - Plugin: P_{PluginName}_{code}
 * - Package: K_{PackageName}_{code}
 */
class AdminMenuService
{
    private EventDispatcher $eventDispatcher;
    private ?AdminPermissionService $permissionService = null;
    private ?array $cachedMenus = null;
    private ?array $cachedCodes = null;

    public function __construct(
        EventDispatcher $eventDispatcher,
        ?AdminPermissionService $permissionService = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->permissionService = $permissionService;
    }

    /**
     * AdminPermissionService 설정 (지연 주입용)
     */
    public function setPermissionService(AdminPermissionService $permissionService): void
    {
        $this->permissionService = $permissionService;
    }

    /**
     * 전체 메뉴 반환
     *
     * @param bool $useCache 캐시 사용 여부
     */
    public function getMenus(bool $useCache = true): array
    {
        if ($useCache && $this->cachedMenus !== null) {
            return $this->cachedMenus;
        }

        // 이벤트 생성
        $event = new AdminMenuBuildingEvent();

        // 1. Core 메뉴 추가 (source: core)
        $event->setSource('core');
        $this->addCoreMenus($event);

        // 2. 이벤트 발송 (Plugin/Package가 메뉴 추가)
        // Provider에서 각 Subscriber 호출 전에 setSource() 호출함
        $this->eventDispatcher->dispatch($event);

        // 3. 지연된 작업 처리 (addSubmenuTo, insertBefore, insertAfter)
        $event->processDeferredOperations();

        // 4. 캐시 저장
        $this->cachedMenus = $event->getSortedMenus();
        $this->cachedCodes = $event->getRegisteredCodes();

        return $this->cachedMenus;
    }

    /**
     * 등록된 모든 코드 반환
     */
    public function getRegisteredCodes(): array
    {
        if ($this->cachedCodes === null) {
            $this->getMenus();
        }

        return $this->cachedCodes ?? [];
    }

    /**
     * 캐시 초기화
     */
    public function clearCache(): void
    {
        $this->cachedMenus = null;
        $this->cachedCodes = null;
    }

    /**
     * 현재 URL 기반 Active 코드 반환
     */
    public function getActiveCode(string $currentPath): string
    {
        $menus = $this->getMenus();
        $matchedCode = '';
        $matchedLength = 0;

        foreach ($menus as $group => $groupData) {
            foreach ($groupData['items'] as $item) {
                // 메인 메뉴 체크
                if ($this->matchesPath($item['url'], $currentPath)) {
                    $length = strlen($item['url']);
                    if ($length > $matchedLength) {
                        $matchedCode = $item['code'];
                        $matchedLength = $length;
                    }
                }

                // 서브메뉴 체크
                foreach ($item['submenu'] ?? [] as $sub) {
                    if ($this->matchesPath($sub['url'], $currentPath)) {
                        $length = strlen($sub['url']);
                        if ($length > $matchedLength) {
                            $matchedCode = $sub['code'];
                            $matchedLength = $length;
                        }
                    }
                }
            }
        }

        return $matchedCode;
    }

    /**
     * activeCode로 브레드크럼 경로(상위 → 현재) 구성
     *
     * 예) '002_001' → [사이트 설정(상위, url '#'), 기본 설정(현재)]
     * 마지막 항목이 현재 페이지. 매칭 실패 시 빈 배열.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function getBreadcrumb(string $activeCode): array
    {
        if ($activeCode === '') {
            return [];
        }

        foreach ($this->getMenus() as $group) {
            foreach ($group['items'] ?? [] as $item) {
                // 1차 메뉴 자체가 현재 페이지
                if (($item['code'] ?? '') === $activeCode) {
                    return [
                        ['label' => $item['label'] ?? '', 'url' => $item['url'] ?? ''],
                    ];
                }

                // 서브메뉴 매칭 → 상위 + 현재
                foreach ($item['submenu'] ?? [] as $sub) {
                    if (($sub['code'] ?? '') === $activeCode) {
                        return [
                            ['label' => $item['label'] ?? '', 'url' => $item['url'] ?? ''],
                            ['label' => $sub['label'] ?? '', 'url' => $sub['url'] ?? ''],
                        ];
                    }
                }
            }
        }

        return [];
    }

    /**
     * 권한 필터링된 메뉴 반환
     *
     * 사용자의 level_value 기준으로 접근 불가 메뉴를 제외한 메뉴 반환
     * is_super=1인 최고관리자는 필터링 없이 전체 메뉴 반환
     * domain_group이 제공되면 상위 도메인의 차단 규칙도 상속 적용
     *
     * @param int $domainId 도메인 ID
     * @param int $levelValue 회원 레벨 값
     * @param bool $isSuper 최고관리자 여부
     * @param string|null $domainGroup 도메인 그룹 (상위 상속용)
     * @return array 필터링된 메뉴
     */
    public function getFilteredMenus(int $domainId, int $levelValue, bool $isSuper = false, ?string $domainGroup = null): array
    {
        $menus = $this->getMenus();

        // 최고관리자는 필터링 없음
        if ($isSuper) {
            return $menus;
        }

        // super_only 서브메뉴 제거
        $menus = $this->removeSuperOnlyItems($menus);

        // 권한 서비스가 없으면 필터링 없음
        if ($this->permissionService === null) {
            return $menus;
        }

        // 차단된 메뉴 목록 조회 (상위 도메인 상속 포함)
        $deniedMenus = $this->permissionService->getDeniedMenusByLevel($domainId, $levelValue, $domainGroup);

        if (empty($deniedMenus)) {
            return $menus;
        }

        // 메뉴 필터링
        $filteredMenus = [];

        foreach ($menus as $groupKey => $group) {
            $filteredItems = [];

            foreach ($group['items'] ?? [] as $item) {
                $menuCode = $item['code'];

                // 1차 메뉴 전체 차단 체크
                if ($this->isMenuDenied($deniedMenus, $menuCode, 'list')) {
                    continue; // 전체 차단 시 메뉴 자체를 숨김
                }

                // 서브메뉴 필터링
                $filteredSubmenus = [];
                foreach ($item['submenu'] ?? [] as $sub) {
                    $subCode = $sub['code'];

                    // 서브메뉴 차단 체크 (list 액션)
                    if (!$this->isMenuDenied($deniedMenus, $subCode, 'list')) {
                        $filteredSubmenus[] = $sub;
                    }
                }

                // 서브메뉴가 하나도 없으면 1차 메뉴도 숨김 (서브메뉴가 있는 경우)
                if (!empty($item['submenu']) && empty($filteredSubmenus)) {
                    continue;
                }

                $item['submenu'] = $filteredSubmenus;
                $filteredItems[] = $item;
            }

            // 그룹에 메뉴가 있으면 추가
            if (!empty($filteredItems)) {
                $filteredMenus[$groupKey] = $group;
                $filteredMenus[$groupKey]['items'] = $filteredItems;
            }
        }

        return $filteredMenus;
    }

    /**
     * 특정 메뉴가 차단되었는지 확인 (헬퍼)
     *
     * @param array $deniedMenus [menu_code => denied_actions, ...]
     * @param string $menuCode 메뉴 코드
     * @param string $action 확인할 액션
     * @return bool 차단이면 true
     */
    private function isMenuDenied(array $deniedMenus, string $menuCode, string $action): bool
    {
        // 직접 차단 체크
        if (isset($deniedMenus[$menuCode])) {
            $deniedActions = $deniedMenus[$menuCode];

            if ($deniedActions === '*') {
                return true;
            }

            $actions = array_map('trim', explode(',', $deniedActions));
            if (in_array($action, $actions, true)) {
                return true;
            }
        }

        // 상위 메뉴 차단 체크 (예: 003_001 → 003)
        if (str_contains($menuCode, '_')) {
            $parentCode = explode('_', $menuCode)[0];
            if (isset($deniedMenus[$parentCode])) {
                $deniedActions = $deniedMenus[$parentCode];

                if ($deniedActions === '*') {
                    return true;
                }

                $actions = array_map('trim', explode(',', $deniedActions));
                if (in_array($action, $actions, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 경로 매칭 확인
     */
    private function matchesPath(string $menuUrl, string $currentPath): bool
    {
        if ($menuUrl === '#' || empty($menuUrl)) {
            return false;
        }

        // 정확히 일치
        if ($menuUrl === $currentPath) {
            return true;
        }

        // 접두사 매칭 (하위 경로도 활성화)
        return str_starts_with($currentPath, $menuUrl . '/');
    }

    /**
     * 코어 메뉴 추가
     *
     * 메뉴 그룹 구조:
     * - platform (플랫폼, priority: 100): Core 메뉴
     * - plugin (플러그인, priority: 80): Plugin이 추가하는 메뉴
     * - package (패키지, priority: 60): Package가 추가하는 메뉴
     */
    private function addCoreMenus(AdminMenuBuildingEvent $event): void
    {
        // =====================================================================
        // 플랫폼 그룹 (Core 메뉴)
        // =====================================================================
        $event->addGroup('platform', '플랫폼', 100);

        $event->addMenus('platform', [
            [
                'label' => '대시보드',
                'url' => '/admin/dashboard',
                'icon' => 'bi-speedometer2',
                'code' => '001',
            ],
            [
                'label' => '사이트 설정',
                'url' => '#',
                'icon' => 'bi-gear',
                'code' => '002',
                'submenu' => [
                    [
                        'label' => '관리자 매뉴얼',
                        'url' => '/admin/guide',
                        'code' => '002_000',
                    ],
                    [
                        'label' => '기본 설정',
                        'url' => '/admin/settings',
                        'code' => '002_001',
                    ],
                    [
                        'label' => '확장 기능',
                        'url' => '/admin/extensions',
                        'code' => '002_002',
                    ],
                    [
                        'label' => '도메인 관리',
                        'url' => '/admin/domains',
                        'code' => '002_003',
                    ],
                    [
                        'label' => '메뉴 관리',
                        'url' => '/admin/menu',
                        'code' => '002_004',
                    ],
                    [
                        'label' => '시스템 관리',
                        'url' => '/admin/system',
                        'code' => '002_005',
                    ],
                    [
                        'label' => 'AI 설정',
                        'url' => '/admin/ai-settings',
                        'code' => '002_006',
                    ],
                ],
            ],
            [
                'label' => '회원 관리',
                'url' => '#',
                'icon' => 'bi-people',
                'code' => '003',
                'submenu' => [
                    [
                        'label' => '회원 관리',
                        'url' => '/admin/member',
                        'code' => '003_001',
                    ],
                    [
                        'label' => '포인트 지갑',
                        'url' => '/admin/point',
                        'code' => '003_002',
                    ],
                    [
                        'label' => '회원 등급 관리',
                        'url' => '/admin/member-levels',
                        'code' => '003_003',
                    ],
                    [
                        'label' => '관리자 접근 권한 관리',
                        'url' => '/admin/admin-permissions',
                        'code' => '003_004',
                    ],
                    [
                        'label' => '추가 입력 정보 관리',
                        'url' => '/admin/member-field',
                        'code' => '003_005',
                    ],
                    [
                        'label' => '약관/정책 관리',
                        'url' => '/admin/policy',
                        'code' => '003_006',
                    ],
                ],
            ],
            [
                'label' => '블록 관리',
                'url' => '#',
                'icon' => 'bi-grid',
                'code' => '004',
                'submenu' => [
                    [
                        // 미리보기 기반 편집 화면(fullPage). 접근은 다른 메뉴처럼
                        // 권한관리(denied_menus)를 따른다 — 별도 운영자 게이트 없음.
                        'label' => '블록 에디터',
                        'url' => '/admin/block-editor',
                        'code' => '004_000',
                    ],
                    [
                        'label' => '블록 행 관리',
                        'url' => '/admin/block-row',
                        'code' => '004_001',
                    ],
                    [
                        'label' => '블록 페이지 관리',
                        'url' => '/admin/block-page',
                        'code' => '004_002',
                    ],
                    [
                        // super_only 를 쓰지 않는다. 일반 관리자도 목록·상세는 볼 수 있고,
                        // 업로드·적용·삭제만 컨트롤러의 canOperateDomain() 가드가 막는다(설계 9.4).
                        'label' => '블록 킷 관리',
                        'url' => '/admin/block-kit',
                        'code' => '004_003',
                    ],
                ],
            ],
            // Board 관리 메뉴: packages/Board/Subscriber/AdminMenuSubscriber.php에서 이벤트로 등록
        ]);

        // =====================================================================
        // 패키지 그룹 (Package가 메뉴 추가)
        // =====================================================================
        $event->addGroup('package', '패키지', 80);

        // =====================================================================
        // 플러그인 그룹 (Plugin이 메뉴 추가)
        // =====================================================================
        $event->addGroup('plugin', '플러그인', 60);
    }

    /**
     * super_only 플래그가 설정된 서브메뉴 제거 (비SUPER 사용자용)
     */
    private function removeSuperOnlyItems(array $menus): array
    {
        foreach ($menus as $groupKey => $group) {
            foreach ($group['items'] ?? [] as $itemKey => $item) {
                $filtered = array_filter(
                    $item['submenu'] ?? [],
                    fn($sub) => empty($sub['super_only'])
                );
                $menus[$groupKey]['items'][$itemKey]['submenu'] = array_values($filtered);

                // 서브메뉴가 모두 제거되면 1차 메뉴도 제거
                if (!empty($item['submenu']) && empty($menus[$groupKey]['items'][$itemKey]['submenu'])) {
                    unset($menus[$groupKey]['items'][$itemKey]);
                }
            }
            $menus[$groupKey]['items'] = array_values($menus[$groupKey]['items'] ?? []);
        }
        return $menus;
    }

}
