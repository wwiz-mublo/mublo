<?php

namespace Tests\Unit\Core\Event;

use Mublo\Core\Event\Member\MemberListQueryEvent;
use Mublo\Core\Event\Subscriber\MemberQuerySubscriber;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Repository\Member\MemberRepository;
use PHPUnit\Framework\TestCase;

final class MemberQuerySubscriberLazyTest extends TestCase
{
    public function testRepositoriesAreResolvedOnlyWhenMatchingEventRuns(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->expects(self::once())
            ->method('findByCriteria')
            ->with(14, ['status' => 'active'])
            ->willReturn([['member_id' => 1]]);
        $memberResolutions = 0;
        $levelResolutions = 0;

        $subscriber = new MemberQuerySubscriber(
            static function () use (&$memberResolutions, $members): MemberRepository {
                $memberResolutions++;
                return $members;
            },
            static function () use (&$levelResolutions): MemberLevelRepository {
                $levelResolutions++;
                throw new \RuntimeException('회원 목록 이벤트에서는 등급 저장소가 필요 없다');
            }
        );

        self::assertSame(0, $memberResolutions);
        self::assertSame(0, $levelResolutions);

        $event = new MemberListQueryEvent(14, ['status' => 'active']);
        $subscriber->onMemberListQuery($event);

        self::assertSame([['member_id' => 1]], $event->getMembers());
        self::assertSame(1, $memberResolutions);
        self::assertSame(0, $levelResolutions);
    }
}
