<?php
/**
 * Shop 관리자 메뉴 — 코드 충돌 검증
 *
 * 메뉴 코드가 겹치면 AdminMenuBuildingEvent 가 예외를 던지고, 그 예외는 특정 페이지가
 * 아니라 관리자 전체를 500 으로 만든다(메뉴는 모든 관리자 페이지가 빌드한다).
 * 항목을 추가할 때 눈으로 번호를 세지 않아도 되도록 여기서 막는다.
 */

namespace Tests\Shop\Unit;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\AdminMenuSubscriber;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriberTest extends TestCase
{
    public function testMenuBuildsWithoutCodeCollision(): void
    {
        $event = new AdminMenuBuildingEvent();

        (new AdminMenuSubscriber())->onAdminMenuBuilding($event);

        $this->assertNotEmpty($event->getMenus(), '관리자 메뉴가 비어 있습니다');
    }

    /** 같은 URL 이 두 항목에 걸리면 어느 쪽이 활성인지 화면이 판정하지 못한다 */
    public function testMenuUrlsAreUnique(): void
    {
        $event = new AdminMenuBuildingEvent();
        (new AdminMenuSubscriber())->onAdminMenuBuilding($event);

        $urls = $this->collectUrls($event->getMenus());

        $this->assertSame(
            array_values(array_unique($urls)),
            $urls,
            '관리자 메뉴 URL 이 중복됩니다: ' . implode(', ', array_diff_assoc($urls, array_unique($urls)))
        );
    }

    /**
     * @param array<mixed> $menus
     * @return list<string>
     */
    private function collectUrls(array $menus): array
    {
        $urls = [];

        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }
            if (!empty($menu['url']) && is_string($menu['url'])) {
                $urls[] = $menu['url'];
            }
            foreach (['children', 'submenus', 'items'] as $key) {
                if (!empty($menu[$key]) && is_array($menu[$key])) {
                    $urls = array_merge($urls, $this->collectUrls($menu[$key]));
                }
            }
        }

        return $urls;
    }
}
