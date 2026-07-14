<?php

namespace Mublo\Service\Admin\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * AdminMenuBuildingEvent
 *
 * 관리자 메뉴 빌드 시 발생하는 이벤트
 * 플러그인/패키지에서 메뉴를 추가할 수 있음
 *
 * Code Prefix 규칙:
 * - Core: 숫자만 사용 (003, 003_001 등) - 정의된 코드 그대로 사용
 * - Plugin 자체 메뉴 (addPluginMenu): P_{PluginName}_{code} (예: P_MemberPoint_001)
 * - Package 자체 메뉴 (addPackageMenu): K_{PackageName}_{code} (예: K_Shop_001)
 * - Core 메뉴에 서브메뉴 추가 (addSubmenuTo): {parentCode}_{prefix}_{code}
 *   예: 003_P_MemberPoint_001 (회원관리 > 포인트 내역)
 */
class AdminMenuBuildingEvent extends AbstractEvent
{
    /**
     * 메뉴 아이템 목록
     * [group => [label, priority, items]]
     */
    private array $menus = [];

    /**
     * 등록된 모든 코드 (중복 체크용)
     */
    private array $registeredCodes = [];

    /**
     * 현재 소스 정보 (core, plugin, package)
     */
    private string $currentSource = 'core';
    private string $currentSourceName = '';

    /**
     * 지연 처리할 작업들 (특정 위치 삽입)
     */
    private array $deferredOperations = [];

    /**
     * 소스 정보 설정 (Provider에서 호출)
     *
     * @param string $type 'core', 'plugin', 'package'
     * @param string $name 플러그인/패키지 이름 (core일 경우 빈 문자열)
     */
    public function setSource(string $type, string $name = ''): self
    {
        $this->currentSource = $type;
        $this->currentSourceName = $name;
        return $this;
    }

    /**
     * 코드에 자동 prefix 적용
     */
    private function applyCodePrefix(string $code): string
    {
        if (empty($code)) {
            return '';
        }

        // Core는 prefix 없음
        if ($this->currentSource === 'core') {
            return $code;
        }

        // Plugin: P_{Name}_{code}
        if ($this->currentSource === 'plugin') {
            return "P_{$this->currentSourceName}_{$code}";
        }

        // Package: K_{Name}_{code}
        if ($this->currentSource === 'package') {
            return "K_{$this->currentSourceName}_{$code}";
        }

        return $code;
    }

    /**
     * 코드 중복 체크
     *
     * @throws \RuntimeException 중복 코드 발견 시
     */
    private function validateCode(string $code): void
    {
        if (empty($code)) {
            return;
        }

        if (isset($this->registeredCodes[$code])) {
            $existingSource = $this->registeredCodes[$code];
            throw new \RuntimeException(
                "Menu code '{$code}' is already registered by [{$existingSource}]. " .
                "Current source: [{$this->currentSource}:{$this->currentSourceName}]"
            );
        }

        $this->registeredCodes[$code] = "{$this->currentSource}:{$this->currentSourceName}";
    }

    /**
     * 메뉴 그룹 추가
     */
    public function addGroup(string $group, string $label, int $priority = 0): self
    {
        if (!isset($this->menus[$group])) {
            $this->menus[$group] = [
                'label' => $label,
                'priority' => $priority,
                'items' => [],
            ];
        }

        return $this;
    }

    /**
     * 메뉴 아이템 추가 (그룹 끝에 추가)
     *
     * @param string $group 그룹 키
     * @param array $item 메뉴 아이템
     */
    public function addMenu(string $group, array $item): self
    {
        if (!isset($this->menus[$group])) {
            $this->addGroup($group, $group);
        }

        $normalizedItem = $this->normalizeItem($item);
        $this->menus[$group]['items'][] = $normalizedItem;

        return $this;
    }

    /**
     * 여러 메뉴 한번에 추가
     */
    public function addMenus(string $group, array $items): self
    {
        foreach ($items as $item) {
            $this->addMenu($group, $item);
        }

        return $this;
    }

    /**
     * 기존 메뉴의 서브메뉴로 추가
     *
     * @param string $parentCode 부모 메뉴 코드 (Core 코드, 예: '003')
     * @param array $submenu 서브메뉴 아이템
     */
    public function addSubmenuTo(string $parentCode, array $submenu): self
    {
        $this->deferredOperations[] = [
            'type' => 'add_submenu',
            'parentCode' => $parentCode,
            'item' => $submenu,
            'source' => $this->currentSource,
            'sourceName' => $this->currentSourceName,
        ];

        return $this;
    }

    /**
     * 여러 서브메뉴 한번에 추가
     */
    public function addSubmenusTo(string $parentCode, array $submenus): self
    {
        foreach ($submenus as $submenu) {
            $this->addSubmenuTo($parentCode, $submenu);
        }

        return $this;
    }

    /**
     * 플러그인 메뉴 추가 (plugin 그룹 아래)
     *
     * Plugin Provider에서 호출하여 자신의 메뉴를 추가합니다.
     * setSource('plugin', 'PluginName')이 먼저 호출되어 있어야 합니다.
     *
     * 코드 생성 규칙:
     * - 루트 메뉴: 코드 없음 (컨테이너 역할)
     * - 서브메뉴: P_{PluginName}_{code} (예: P_MemberPoint_001)
     *
     * @param string $pluginLabel 플러그인 표시 이름 (예: '회원 포인트')
     * @param string|null $icon 아이콘 클래스 (예: 'bi-coin'). null/'' 이면 manifest.json icon 사용.
     * @param array $submenus 서브메뉴 목록
     *
     * 아이콘($icon)을 null/'' 로 주면 현재 소스(setSource로 지정한 플러그인)의
     * manifest.json "icon" 을 단일 소스로 사용한다. 명시하면 그 값이 우선.
     *
     * @example
     * $event->setSource('plugin', 'MemberPoint');
     * $event->addPluginMenu('회원 포인트', null, [   // null → manifest.json icon 사용
     *     ['label' => '포인트 설정', 'url' => '/admin/memberpoint/settings', 'code' => '001'],
     *     ['label' => '포인트 내역', 'url' => '/admin/memberpoint/history', 'code' => '002'],
     * ]);
     * // 결과: P_MemberPoint_001, P_MemberPoint_002
     */
    public function addPluginMenu(string $pluginLabel, ?string $icon, array $submenus): self
    {
        $menu = [
            'label' => $pluginLabel,
            'url' => '#',
            'icon' => $this->resolveMenuIcon($icon, 'bi-grid'),
            'code' => '',  // normalizeItem에서는 빈 코드로 처리 (서브메뉴 코드에 영향 안 줌)
            'submenu' => $submenus,
        ];

        if (!isset($this->menus['plugin'])) {
            $this->addGroup('plugin', 'plugin');
        }

        $normalizedItem = $this->normalizeItem($menu);
        // 루트 컨테이너에 고유 코드 부여 (Sidebar submenu toggle ID 충돌 방지)
        $normalizedItem['code'] = "P_{$this->currentSourceName}";
        $this->menus['plugin']['items'][] = $normalizedItem;

        return $this;
    }

    /**
     * 패키지 메뉴 추가 (package 그룹 아래)
     *
     * Package Provider에서 호출하여 자신의 메뉴를 추가합니다.
     * setSource('package', 'PackageName')이 먼저 호출되어 있어야 합니다.
     *
     * 코드 생성 규칙:
     * - 루트 메뉴: 코드 없음 (컨테이너 역할)
     * - 서브메뉴: K_{PackageName}_{code} (예: K_Shop_001)
     *
     * @param string $packageLabel 패키지 표시 이름 (예: '쇼핑몰')
     * @param string $icon 아이콘 클래스 (예: 'bi-shop')
     * @param array $submenus 서브메뉴 목록
     *
     * 아이콘($icon)을 null/'' 로 주면 현재 소스(setSource로 지정한 패키지/플러그인)의
     * manifest.json "icon" 을 단일 소스로 사용한다. 명시하면 그 값이 우선.
     *
     * @example
     * $event->setSource('package', 'Shop');
     * $event->addPackageMenu('쇼핑몰', null, [   // null → manifest.json icon 사용
     *     ['label' => '상품 관리', 'url' => '/admin/shop/products', 'code' => '001'],
     *     ['label' => '주문 관리', 'url' => '/admin/shop/orders', 'code' => '002'],
     * ]);
     * // 결과: K_Shop_001, K_Shop_002
     */
    public function addPackageMenu(string $packageLabel, ?string $icon, array $submenus): self
    {
        $menu = [
            'label' => $packageLabel,
            'url' => '#',
            'icon' => $this->resolveMenuIcon($icon, 'bi-grid'),
            'code' => '',  // normalizeItem에서는 빈 코드로 처리 (서브메뉴 코드에 영향 안 줌)
            'submenu' => $submenus,
        ];

        if (!isset($this->menus['package'])) {
            $this->addGroup('package', 'package');
        }

        $normalizedItem = $this->normalizeItem($menu);
        // 루트 컨테이너에 고유 코드 부여 (Sidebar submenu toggle ID 충돌 방지)
        $normalizedItem['code'] = "K_{$this->currentSourceName}";
        $this->menus['package']['items'][] = $normalizedItem;

        return $this;
    }

    /**
     * 메뉴 컨테이너 아이콘 해석 (플러그인/패키지 공용).
     * 명시값이 있으면 그대로, 없으면(null/'') 현재 소스의 manifest.json icon,
     * 그것도 없으면 폴백($fallback).
     */
    private function resolveMenuIcon(?string $icon, string $fallback): string
    {
        if ($icon !== null && $icon !== '') {
            return $icon;
        }

        return $this->manifestIcon() ?? $fallback;
    }

    /**
     * 현재 소스(setSource)의 manifest.json "icon" 반환(없으면 null).
     * 아이콘 단일 소스화 — 패키지/플러그인이 admin·확장관리·프론트에서 한 아이콘을 공유.
     */
    private function manifestIcon(): ?string
    {
        if ($this->currentSourceName === '') {
            return null;
        }

        $dir  = $this->currentSource === 'plugin' ? 'plugins' : 'packages';
        $path = MUBLO_ROOT_PATH . '/' . $dir . '/' . $this->currentSourceName . '/manifest.json';

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return (is_array($data) && !empty($data['icon'])) ? (string) $data['icon'] : null;
    }

    /**
     * 특정 메뉴의 서브메뉴 중 지정 코드 뒤에 삽입
     *
     * @param string $parentCode 부모 메뉴 코드 (예: '005')
     * @param string $afterCode  기준 서브메뉴 코드 (예: '005_004') — 이 항목 뒤에 삽입
     * @param array  $item       삽입할 서브메뉴 아이템
     */
    public function insertSubmenuAfter(string $parentCode, string $afterCode, array $item): self
    {
        $this->deferredOperations[] = [
            'type'       => 'insert_submenu_after',
            'parentCode' => $parentCode,
            'afterCode'  => $afterCode,
            'item'       => $item,
            'source'     => $this->currentSource,
            'sourceName' => $this->currentSourceName,
        ];

        return $this;
    }

    /**
     * 특정 메뉴의 서브메뉴 중 지정 코드 앞에 삽입
     *
     * @param string $parentCode  부모 메뉴 코드 (예: '005')
     * @param string $beforeCode  기준 서브메뉴 코드 (예: '005_004') — 이 항목 앞에 삽입
     * @param array  $item        삽입할 서브메뉴 아이템
     */
    public function insertSubmenuBefore(string $parentCode, string $beforeCode, array $item): self
    {
        $this->deferredOperations[] = [
            'type'        => 'insert_submenu_before',
            'parentCode'  => $parentCode,
            'beforeCode'  => $beforeCode,
            'item'        => $item,
            'source'      => $this->currentSource,
            'sourceName'  => $this->currentSourceName,
        ];

        return $this;
    }

    /**
     * 특정 메뉴 앞에 삽입
     *
     * @param string $targetCode 대상 메뉴 코드
     * @param string $group 그룹 키
     * @param array $item 메뉴 아이템
     */
    public function insertBefore(string $targetCode, string $group, array $item): self
    {
        $this->deferredOperations[] = [
            'type' => 'insert_before',
            'targetCode' => $targetCode,
            'group' => $group,
            'item' => $item,
            'source' => $this->currentSource,
            'sourceName' => $this->currentSourceName,
        ];

        return $this;
    }

    /**
     * 특정 메뉴 뒤에 삽입
     *
     * @param string $targetCode 대상 메뉴 코드
     * @param string $group 그룹 키
     * @param array $item 메뉴 아이템
     */
    public function insertAfter(string $targetCode, string $group, array $item): self
    {
        $this->deferredOperations[] = [
            'type' => 'insert_after',
            'targetCode' => $targetCode,
            'group' => $group,
            'item' => $item,
            'source' => $this->currentSource,
            'sourceName' => $this->currentSourceName,
        ];

        return $this;
    }

    /**
     * 지연된 작업들 처리 (모든 리스너 실행 후 호출)
     */
    public function processDeferredOperations(): void
    {
        foreach ($this->deferredOperations as $op) {
            // 소스 정보 복원
            $this->currentSource = $op['source'];
            $this->currentSourceName = $op['sourceName'];

            switch ($op['type']) {
                case 'add_submenu':
                    $this->processAddSubmenu($op['parentCode'], $op['item']);
                    break;

                case 'insert_before':
                    $this->processInsertBefore($op['targetCode'], $op['group'], $op['item']);
                    break;

                case 'insert_after':
                    $this->processInsertAfter($op['targetCode'], $op['group'], $op['item']);
                    break;

                case 'insert_submenu_after':
                    $this->processInsertSubmenuAfter($op['parentCode'], $op['afterCode'], $op['item']);
                    break;

                case 'insert_submenu_before':
                    $this->processInsertSubmenuBefore($op['parentCode'], $op['beforeCode'], $op['item']);
                    break;
            }
        }

        $this->deferredOperations = [];
    }

    /**
     * 서브메뉴 추가 처리
     *
     * 서브메뉴의 code는 {parentCode}_{prefix}_{code} 형식으로 생성됩니다.
     * 예: 003_P_MemberPoint_001 (회원관리 > 포인트 내역)
     */
    private function processAddSubmenu(string $parentCode, array $submenu): void
    {
        $normalizedItem = $this->normalizeItem($submenu, $parentCode);

        foreach ($this->menus as $group => &$groupData) {
            foreach ($groupData['items'] as &$item) {
                if (($item['code'] ?? '') === $parentCode) {
                    $item['submenu'][] = $normalizedItem;
                    return;
                }
            }
        }
    }

    /**
     * 특정 메뉴 앞에 삽입 처리
     */
    private function processInsertBefore(string $targetCode, string $group, array $item): void
    {
        if (!isset($this->menus[$group])) {
            $this->addGroup($group, $group);
        }

        $normalizedItem = $this->normalizeItem($item);
        $items = &$this->menus[$group]['items'];

        foreach ($items as $index => $existingItem) {
            if (($existingItem['code'] ?? '') === $targetCode) {
                array_splice($items, $index, 0, [$normalizedItem]);
                return;
            }
        }

        // 대상을 찾지 못하면 끝에 추가
        $items[] = $normalizedItem;
    }

    /**
     * 특정 메뉴 뒤에 삽입 처리
     */
    private function processInsertAfter(string $targetCode, string $group, array $item): void
    {
        if (!isset($this->menus[$group])) {
            $this->addGroup($group, $group);
        }

        $normalizedItem = $this->normalizeItem($item);
        $items = &$this->menus[$group]['items'];

        foreach ($items as $index => $existingItem) {
            if (($existingItem['code'] ?? '') === $targetCode) {
                array_splice($items, $index + 1, 0, [$normalizedItem]);
                return;
            }
        }

        // 대상을 찾지 못하면 끝에 추가
        $items[] = $normalizedItem;
    }

    /**
     * 특정 부모 메뉴의 서브메뉴 중 지정 코드 뒤에 삽입 처리
     */
    private function processInsertSubmenuAfter(string $parentCode, string $afterCode, array $item): void
    {
        $normalizedItem = $this->normalizeItem($item, $parentCode);

        foreach ($this->menus as &$groupData) {
            foreach ($groupData['items'] as &$menuItem) {
                if (($menuItem['code'] ?? '') === $parentCode) {
                    $submenu = &$menuItem['submenu'];
                    foreach ($submenu as $index => $sub) {
                        if (($sub['code'] ?? '') === $afterCode) {
                            array_splice($submenu, $index + 1, 0, [$normalizedItem]);
                            return;
                        }
                    }
                    // 대상을 찾지 못하면 끝에 추가
                    $submenu[] = $normalizedItem;
                    return;
                }
            }
        }
    }

    /**
     * 특정 부모 메뉴의 서브메뉴 중 지정 코드 앞에 삽입 처리
     */
    private function processInsertSubmenuBefore(string $parentCode, string $beforeCode, array $item): void
    {
        $normalizedItem = $this->normalizeItem($item, $parentCode);

        foreach ($this->menus as &$groupData) {
            foreach ($groupData['items'] as &$menuItem) {
                if (($menuItem['code'] ?? '') === $parentCode) {
                    $submenu = &$menuItem['submenu'];
                    foreach ($submenu as $index => $sub) {
                        if (($sub['code'] ?? '') === $beforeCode) {
                            array_splice($submenu, $index, 0, [$normalizedItem]);
                            return;
                        }
                    }
                    // 대상을 찾지 못하면 끝에 추가
                    $submenu[] = $normalizedItem;
                    return;
                }
            }
        }
    }

    /**
     * 전체 메뉴 반환
     */
    public function getMenus(): array
    {
        return $this->menus;
    }

    /**
     * 특정 그룹의 메뉴 반환
     */
    public function getGroup(string $group): array
    {
        return $this->menus[$group]['items'] ?? [];
    }

    /**
     * 등록된 모든 코드 반환
     */
    public function getRegisteredCodes(): array
    {
        return $this->registeredCodes;
    }

    /**
     * 우선순위 기준으로 정렬된 전체 메뉴 반환
     */
    public function getSortedMenus(): array
    {
        $sorted = $this->menus;

        uasort($sorted, function ($a, $b) {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });

        return $sorted;
    }

    /**
     * 메뉴 아이템 정규화 (코드 prefix 및 중복 검사 포함)
     *
     * 코드 생성 규칙:
     * - 코어 메뉴: 정의된 코드 그대로 사용 (003_001)
     * - 플러그인/패키지에서 addSubmenuTo로 추가: {parentCode}_{P_Name_code} (003_P_MemberPoint_001)
     *
     * @param array $item 메뉴 아이템
     * @param string|null $parentCode 부모 메뉴 코드 (addSubmenuTo로 추가될 때만 사용)
     */
    private function normalizeItem(array $item, ?string $parentCode = null): array
    {
        $normalized = array_merge([
            'label' => '',
            'url' => '#',
            'icon' => 'bi-circle',
            'code' => '',
            'open' => false,
            'submenu' => [],
            'source' => $this->currentSource,
            'sourceName' => $this->currentSourceName,
        ], $item);

        // 코드에 prefix 적용
        if (!empty($normalized['code'])) {
            $prefixedCode = $this->applyCodePrefix($normalized['code']);

            // 부모 코드 추가는 플러그인/패키지에서 addSubmenuTo로 추가할 때만
            // 코어 메뉴는 정의에서 이미 전체 코드를 지정함 (003_001)
            if ($parentCode !== null && $this->currentSource !== 'core') {
                $normalized['code'] = "{$parentCode}_{$prefixedCode}";
            } else {
                $normalized['code'] = $prefixedCode;
            }

            $this->validateCode($normalized['code']);
        }

        // 서브메뉴도 재귀적으로 정규화
        // 코어: parentCode 전달 안함 (이미 전체 코드 정의됨)
        // 플러그인/패키지: parentCode 전달 (코드 조합 필요)
        if (!empty($normalized['submenu'])) {
            $passParentCode = ($this->currentSource !== 'core') ? ($normalized['code'] ?: null) : null;
            $normalized['submenu'] = array_map(
                fn($sub) => $this->normalizeItem($sub, $passParentCode),
                $normalized['submenu']
            );
        }

        return $normalized;
    }
}
