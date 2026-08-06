<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Rendering;

use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionView;
use Mublo\Core\Rendering\ViewContext;
use PHPUnit\Framework\TestCase;

final class MemberActionMenuTest extends TestCase
{
    private const PUBLIC_ID = 'a3f9c2e81b47d06f5a92c1';

    public function testRendersAllThreeTargetTransports(): void
    {
        $view = new ViewContext('front');
        $view->bind(['mublo' => ['security' => ['csrfToken' => 'csrf-value']]]);

        $html = $view->memberActionMenu([
            new MemberActionView('plugin:Profile:path', '프로필', 'bi bi-person', '/profile', MemberActionTargetTransport::PublicPath),
            new MemberActionView('plugin:Profile:query', '프로필 쿼리', '', '/profile', MemberActionTargetTransport::PublicQuery),
            new MemberActionView('plugin:DirectMessage:compose', '쪽지 보내기', '', '/direct-message/compose', MemberActionTargetTransport::PrivateBody),
        ], self::PUBLIC_ID);

        self::assertStringContainsString('href="/profile/' . self::PUBLIC_ID . '"', $html);
        self::assertStringContainsString('href="/profile?member=' . self::PUBLIC_ID . '"', $html);
        self::assertStringContainsString('method="post" action="/direct-message/compose"', $html);
        self::assertStringContainsString('name="target_public_id" value="' . self::PUBLIC_ID . '"', $html);
        self::assertStringContainsString('name="_token" value="csrf-value"', $html);
    }

    public function testNoCsrfKeepsPublicLinksAndDropsPrivateForms(): void
    {
        $view = new ViewContext('front');
        $view->bind(['mublo' => ['security' => ['csrfToken' => '']]]);

        $html = $view->memberActionMenu([
            new MemberActionView('plugin:Profile:path', '프로필', '', '/profile', MemberActionTargetTransport::PublicPath),
            new MemberActionView('plugin:DirectMessage:compose', '쪽지', '', '/direct-message/compose', MemberActionTargetTransport::PrivateBody),
        ], self::PUBLIC_ID);

        self::assertStringContainsString('/profile/' . self::PUBLIC_ID, $html);
        self::assertStringNotContainsString('/direct-message/compose', $html);
    }

    public function testRendersEscapedTextTrigger(): void
    {
        $view = new ViewContext('front');
        $view->bind(['mublo' => ['security' => ['csrfToken' => 'csrf-value']]]);

        $html = $view->memberActionMenu([
            new MemberActionView('plugin:DirectMessage:compose', '쪽지 보내기', '', '/direct-message/compose', MemberActionTargetTransport::PrivateBody),
        ], self::PUBLIC_ID, [
            'triggerLabel' => '회원 <관리자>',
            'ariaLabel' => '작성자 메뉴',
        ]);

        self::assertStringContainsString('mublo-mam--text', $html);
        self::assertStringContainsString('aria-label="작성자 메뉴"', $html);
        self::assertStringContainsString('회원 &lt;관리자&gt;', $html);
        self::assertStringNotContainsString('회원 <관리자>', $html);
    }

    public function testInvalidPublicIdRendersNothing(): void
    {
        $view = new ViewContext('front');
        self::assertSame('', $view->memberActionMenu([
            new MemberActionView('plugin:Profile:path', '프로필', '', '/profile', MemberActionTargetTransport::PublicPath),
        ], '7'));
    }
}
