<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Rendering;

use Mublo\Contract\Auth\AuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class MypageLayoutTest extends TestCase
{
    public function testItRendersAuthenticatedUserContract(): void
    {
        $user = new AuthenticatedUser(
            memberId: 1,
            domainId: 1,
            userId: 'root',
            nickname: '전체관리자',
            levelValue: 255,
            admin: true,
            super: true,
            canOperateDomain: true,
            avatar: '/storage/avatar/root.png',
        );

        $html = $this->render($user);

        self::assertStringContainsString('전체관리자', $html);
        self::assertStringContainsString('/storage/avatar/root.png', $html);
        self::assertStringContainsString('주문 내역', $html);
    }

    public function testItKeepsLegacyArrayUserCompatible(): void
    {
        $html = $this->render([
            'user_id' => 'legacy',
            'nickname' => '기존회원',
            'level_name' => '우수회원',
            'avatar' => null,
        ]);

        self::assertStringContainsString('기존회원', $html);
        self::assertStringContainsString('우수회원', $html);
    }

    private function render(AuthenticatedUser|array|null $user): string
    {
        $renderer = new class {
            public object $assets;

            public function __construct()
            {
                $this->assets = new class {
                    public function addCss(string $path): void {}
                };
            }

            public function render(AuthenticatedUser|array|null $user): string
            {
                $mypageMenus = [[
                    'section' => 'orders',
                    'label' => '주문 내역',
                    'url' => '/orders',
                    'icon' => 'bi-bag',
                    'active' => true,
                ]];
                $currentSection = 'orders';
                $content = '<p>본문</p>';

                ob_start();
                include MUBLO_VIEW_PATH . '/Front/Mypage/basic/_layout.php';
                return (string) ob_get_clean();
            }
        };

        return $renderer->render($user);
    }
}
