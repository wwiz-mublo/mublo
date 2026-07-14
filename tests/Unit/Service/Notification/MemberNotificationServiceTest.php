<?php

namespace Tests\Unit\Service\Notification;

use Mublo\Contract\Notification\MemberNotification;
use Mublo\Contract\Notification\MemberNotificationPublishedEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Notification\MemberNotificationRepository;
use Mublo\Repository\Notification\MemberNotificationCreateResult;
use Mublo\Service\Notification\MemberNotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemberNotificationServiceTest extends TestCase
{
    public function testPublishesDomainScopedNotificationAndDispatchesNeutralEvent(): void
    {
        $repository = $this->createMock(MemberNotificationRepository::class);
        $repository->expects($this->once())->method('create')
            ->willReturn(new MemberNotificationCreateResult(31, true));
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturnCallback(fn (int $id) => $this->member($id, 7));
        $events = new EventDispatcher();
        $published = null;
        $events->addListener(MemberNotificationPublishedEvent::class, function ($event) use (&$published): void {
            $published = $event;
        });
        $service = new MemberNotificationService($repository, $members, $events);
        $notification = new MemberNotification(
            domainId: 7,
            memberId: 10,
            type: 'board.comment.created',
            title: '새 댓글이 달렸습니다.',
            targetUrl: '/board/articles/15#comment-3',
            source: 'package:Board',
            actorMemberId: 11,
        );

        $this->assertSame(31, $service->publish($notification));
        $this->assertInstanceOf(MemberNotificationPublishedEvent::class, $published);
        $this->assertSame($notification, $published->notification);
    }

    public function testDeduplicationReturnsExistingIdWithoutDispatchingAgain(): void
    {
        $repository = $this->createMock(MemberNotificationRepository::class);
        $repository->method('findByDeduplicationKey')->willReturn(['notification_id' => 17]);
        $repository->expects($this->never())->method('create');
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturn($this->member(10, 7));
        $events = new EventDispatcher();
        $called = false;
        $events->addListener(MemberNotificationPublishedEvent::class, function () use (&$called): void {
            $called = true;
        });
        $service = new MemberNotificationService($repository, $members, $events);

        $id = $service->publish(new MemberNotification(
            domainId: 7,
            memberId: 10,
            type: 'message.received',
            title: '새 쪽지가 도착했습니다.',
            deduplicationKey: 'message:99',
        ));

        $this->assertSame(17, $id);
        $this->assertFalse($called);
    }

    public function testRejectsRecipientFromAnotherDomain(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturn($this->member(10, 8));
        $service = new MemberNotificationService(
            $this->createMock(MemberNotificationRepository::class),
            $members,
            new EventDispatcher()
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->publish(new MemberNotification(7, 10, 'board.comment.created', '새 댓글'));
    }

    public function testAllowsActorFromAnotherDomainForGlobalResourceInteraction(): void
    {
        $repository = $this->createMock(MemberNotificationRepository::class);
        $repository->expects($this->once())->method('create')
            ->willReturn(new MemberNotificationCreateResult(32, true));
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturnCallback(fn (int $id) => match ($id) {
            10 => $this->member(10, 7),
            11 => $this->member(11, 8),
        });
        $service = new MemberNotificationService($repository, $members, new EventDispatcher());

        $id = $service->publish(new MemberNotification(
            domainId: 7,
            memberId: 10,
            type: 'board.comment.created',
            title: '새 댓글',
            actorMemberId: 11,
        ));

        $this->assertSame(32, $id);
    }

    public function testConcurrentDuplicateReturnsSameIdWithoutDispatchingEvent(): void
    {
        $repository = $this->createMock(MemberNotificationRepository::class);
        $repository->method('findByDeduplicationKey')->willReturn(null);
        $repository->expects($this->once())->method('create')
            ->willReturn(new MemberNotificationCreateResult(17, false));
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturn($this->member(10, 7));
        $events = new EventDispatcher();
        $called = false;
        $events->addListener(MemberNotificationPublishedEvent::class, function () use (&$called): void {
            $called = true;
        });
        $service = new MemberNotificationService($repository, $members, $events);

        $id = $service->publish(new MemberNotification(
            domainId: 7,
            memberId: 10,
            type: 'message.received',
            title: '새 쪽지가 도착했습니다.',
            deduplicationKey: 'message:99',
        ));

        $this->assertSame(17, $id);
        $this->assertFalse($called);
    }

    public function testOpenMarksOnlyScopedNotificationAndReturnsSafeInternalTarget(): void
    {
        $repository = $this->createMock(MemberNotificationRepository::class);
        $repository->method('findForMember')->with(7, 10, 31)->willReturn([
            'notification_id' => 31,
            'target_url' => '/messages/31',
            'read_at' => null,
        ]);
        $repository->expects($this->once())->method('markRead')->with(7, 10, 31)->willReturn(true);
        $service = new MemberNotificationService(
            $repository,
            $this->createMock(MemberRepository::class),
            new EventDispatcher()
        );

        $this->assertSame('/messages/31', $service->open(7, 10, 31));
    }

    #[DataProvider('invalidTargetProvider')]
    public function testContractRejectsExternalOrAmbiguousTargets(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MemberNotification(7, 10, 'message.received', '새 쪽지', targetUrl: $target);
    }

    public static function invalidTargetProvider(): array
    {
        return [
            'absolute URL' => ['https://example.com/phishing'],
            'scheme relative URL' => ['//example.com/phishing'],
            'backslash ambiguity' => ['/\\example.com'],
        ];
    }

    private function member(int $memberId, int $domainId): Member
    {
        return Member::fromArray([
            'member_id' => $memberId,
            'domain_id' => $domainId,
            'user_id' => 'member' . $memberId,
            'password' => 'hash',
            'level_value' => 1,
            'status' => 'active',
            'created_at' => '2026-07-13 00:00:00',
        ]);
    }
}
