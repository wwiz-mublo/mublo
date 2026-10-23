<?php
declare(strict_types=1);

namespace Tests\Unit\Subscriber;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\Member\MemberFormRenderingEvent;
use Mublo\Core\Event\Tracking\PageViewedEvent;
use Mublo\Entity\Domain\Domain;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberActivityRepository;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;
use Mublo\Subscriber\MemberActivitySubscriber;
use PHPUnit\Framework\TestCase;

/**
 * 회원 활동 기록은 켠 사이트의 로그인 회원 페이지 조회만 남기고, 탈퇴하면 지운다.
 */
final class MemberActivitySubscriberTest extends TestCase
{
    private function domain(bool $enabled, int $days = 90): Domain
    {
        return Domain::fromArray([
            'domain_id' => 3,
            'domain' => 'example.test',
            'site_config' => ['member_activity_log' => $enabled, 'member_activity_days' => $days],
        ]);
    }

    private function subscriber(MemberActivityRepository $repo, ?Domain $domain, int $roll = 50): MemberActivitySubscriber
    {
        return new MemberActivitySubscriber($repo, fn() => $domain, null, fn() => $roll);
    }

    private function view(?int $memberId, string $type = 'other', string $url = '/shop/products/7', string $title = '냉장고'): PageViewedEvent
    {
        return new PageViewedEvent(3, $url, $type, $memberId, pageTitle: $title);
    }

    public function testDisabledSiteRecordsNothing(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->expects($this->never())->method('insert');

        $this->subscriber($repo, $this->domain(false))->onPageViewed($this->view(7));
    }

    public function testGuestAndAuthPagesAreSkipped(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->expects($this->never())->method('insert');

        $sub = $this->subscriber($repo, $this->domain(true));
        $sub->onPageViewed($this->view(null));
        $sub->onPageViewed($this->view(7, 'auth', '/login'));
        $sub->onPageViewed($this->view(7, 'member', '/mypage/profile'));
        $sub->onPageViewed($this->view(7, 'other', '/mypage/profile'));
        $sub->onPageViewed($this->view(7, 'other', '/mypage/profile/'));
        $sub->onPageViewed($this->view(7, 'other', '/mypage/profile?return=%2Fshop'));
        $sub->onPageViewed($this->view(7, 'other', '/mypage/withdraw'));
    }

    public function testMemberPageViewIsRecordedWithTitle(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->method('findLatest')->willReturn(null);
        $repo->expects($this->once())->method('insert')->with(3, 7, 'other', '/shop/products/7', '냉장고');
        $repo->expects($this->never())->method('purgeOlderThan');

        $this->subscriber($repo, $this->domain(true))->onPageViewed($this->view(7));
    }

    public function testRefreshWithinAMinuteIsRecordedOnce(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->method('findLatest')->willReturn(['page_url' => '/shop/products/7', 'created_at' => date('Y-m-d H:i:s')]);
        $repo->expects($this->never())->method('insert');

        $this->subscriber($repo, $this->domain(true))->onPageViewed($this->view(7));
    }

    public function testOccasionallyPurgesByRetention(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->method('findLatest')->willReturn(null);
        $repo->expects($this->once())->method('purgeOlderThan')
            ->with(3, $this->callback(fn(string $before) => abs(strtotime($before) - (time() - 30 * 86400)) < 5));

        $this->subscriber($repo, $this->domain(true, 30), roll: 0)->onPageViewed($this->view(7));
    }

    public function testWithdrawalDeletesLogs(): void
    {
        $member = $this->createMock(Member::class);
        $member->method('getMemberId')->willReturn(7);

        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->expects($this->once())->method('deleteByMember')->with(7);

        $this->subscriber($repo, null)->onMemberWithdrawn(new MemberWithdrawnEvent($member));
    }

    public function testAdminFormShowsRecentActivityEscaped(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->method('findRecent')->with(3, 7, 30)->willReturn([
            ['page_type' => 'other', 'page_url' => '/shop/products/7', 'page_title' => '<b>냉장고</b>', 'created_at' => '2026-10-22 10:00:00'],
            ['page_type' => 'other', 'page_url' => 'https://evil.test/', 'page_title' => '', 'created_at' => '2026-10-22 09:00:00'],
        ]);

        $context = $this->createMock(Context::class);
        $context->method('getDomainInfo')->willReturn($this->domain(true));
        $context->method('getDomainId')->willReturn(3);

        $event = new MemberFormRenderingEvent('edit', ['member_id' => 7], $context);
        $this->subscriber($repo, null)->onMemberFormRendering($event);

        $html = implode('', $event->getSectionsSorted());
        $this->assertStringContainsString('최근 활동', $html);
        $this->assertStringContainsString('&lt;b&gt;냉장고&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="/shop/products/7"', $html);
        $this->assertStringNotContainsString('href="https://evil.test/"', $html);
    }

    public function testAdminFormHiddenWhenDisabledAndEmpty(): void
    {
        $repo = $this->createMock(MemberActivityRepository::class);
        $repo->method('findRecent')->willReturn([]);

        $context = $this->createMock(Context::class);
        $context->method('getDomainInfo')->willReturn($this->domain(false));
        $context->method('getDomainId')->willReturn(3);

        $event = new MemberFormRenderingEvent('edit', ['member_id' => 7], $context);
        $this->subscriber($repo, null)->onMemberFormRendering($event);

        $this->assertFalse($event->hasSections());
    }
}
