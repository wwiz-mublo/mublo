<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Rendering\FrontViewContract;
use Mublo\Core\Rendering\ViewContext;

final class MemberNotificationHeaderViewTest extends TestCase
{
    /**
     * Header.php가 공용 컴포넌트($this->component)를 쓰므로 실제 ViewContext로 렌더한다.
     */
    private function renderHeader(array $data): string
    {
        $view = new ViewContext('basic');
        $mublo = array_replace_recursive(FrontViewContract::empty(true), $data);
        ob_start();
        $view->render(MUBLO_ROOT_PATH . '/views/Front/frame/basic/Header.php', ['mublo' => $mublo]);

        return (string) ob_get_clean();
    }

    public function testLoggedInMemberSeesBellAndCappedUnreadBadge(): void
    {
        $html = $this->renderHeader([
            'site' => ['config' => ['site_title' => 'Mublo']],
            'viewer' => [
                'available' => true,
                'authenticated' => true,
                'member' => ['memberId' => 10],
                'notificationUnreadCount' => 120,
            ],
        ]);

        $this->assertStringContainsString('href="/mypage/notifications"', $html);
        $this->assertStringContainsString('99+', $html);
        $this->assertStringContainsString('120개 읽지 않음', $html);
    }

    public function testGuestDoesNotSeeNotificationBell(): void
    {
        $html = $this->renderHeader([
            'viewer' => ['notificationUnreadCount' => 3],
        ]);

        $this->assertStringNotContainsString('/mypage/notifications', $html);
    }
}
