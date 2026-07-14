<?php

namespace Mublo\Service\Mypage;

use Mublo\Service\Menu\MenuService;

/**
 * MypageMenuBuilder
 *
 * 마이페이지 사이드바 메뉴를 빌드하는 서비스.
 *
 * MypageController뿐 아니라 Package/Plugin 컨트롤러에서도
 * 마이페이지 레이아웃을 사용할 수 있도록 Core 서비스로 분리.
 *
 * 메뉴 소스: menu_items.show_in_mypage=1 (관리자 설정 반영)
 * Plugin/Package 메뉴 추가: 패키지 설치 시 menu_items에 INSERT (이벤트 방식 불사용)
 */
class MypageMenuBuilder
{
    /** 아이콘 없는 항목 폴백 */
    private const FALLBACK_ICON = 'bi-grid';

    /** 시스템/코어 메뉴 아이콘 — URL 말단(basename) 기준 하드코딩 */
    private const SYSTEM_ICONS = [
        'profile'  => 'bi-person-circle',
        'balance'  => 'bi-coin',
        'notifications' => 'bi-bell',
        'withdraw' => 'bi-box-arrow-right',
    ];

    /** @var array<string, ?string> manifest 아이콘 캐시 (type:name) */
    private array $manifestIconCache = [];

    public function __construct(private MenuService $menuService) {}

    /**
     * 사이드바 메뉴 목록 빌드
     *
     * @param string $section  현재 활성 섹션 식별자
     * @param int    $domainId 도메인 ID
     * @return array<int, array{label: string, url: string, section: string, order: int, active: bool}>
     */
    public function buildMenus(string $currentUrl, int $domainId): array
    {
        $dbRows = $this->menuService->getMypageMenus($domainId);

        // active 판정은 전체 URL 매칭 — /mypage/board/articles 와 /mypage/articles 처럼
        // basename이 겹치는 섹션들을 정확히 구분한다. (section은 _layout 구분선 판정용으로만 유지)
        return array_map(fn($row) => [
            'label'     => $row['label'],
            'url'       => $row['url'],
            'section'   => basename($row['url']),
            'order'     => (int) $row['mypage_order'],
            'is_system' => (bool) ($row['is_system'] ?? false),
            'icon'      => $this->resolveIcon($row),
            'active'    => ($row['url'] === $currentUrl),
        ], $dbRows);
    }

    /**
     * 메뉴 아이콘 해석.
     * - 패키지/플러그인: manifest.json 의 icon
     * - 시스템/코어: URL 말단 기준 하드코딩(SYSTEM_ICONS)
     * - 그 외: 폴백
     */
    private function resolveIcon(array $row): string
    {
        $type = $row['provider_type'] ?? 'core';

        if ($type === 'package' || $type === 'plugin') {
            $icon = $this->manifestIcon($type, (string) ($row['provider_name'] ?? ''));
            if ($icon !== null) {
                return $icon;
            }
        }

        $key = basename((string) ($row['url'] ?? ''));
        return self::SYSTEM_ICONS[$key] ?? self::FALLBACK_ICON;
    }

    /**
     * 확장 manifest.json 의 icon 반환(없으면 null). type:name 캐시.
     */
    private function manifestIcon(string $type, string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $cacheKey = $type . ':' . $name;
        if (array_key_exists($cacheKey, $this->manifestIconCache)) {
            return $this->manifestIconCache[$cacheKey];
        }

        $dir  = $type === 'plugin' ? 'plugins' : 'packages';
        $path = MUBLO_ROOT_PATH . '/' . $dir . '/' . $name . '/manifest.json';

        $icon = null;
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && !empty($data['icon'])) {
                $icon = (string) $data['icon'];
            }
        }

        return $this->manifestIconCache[$cacheKey] = $icon;
    }
}
