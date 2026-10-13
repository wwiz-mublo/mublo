<?php

namespace Tests\Unit\Service\Member;

use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberLevelDescriptor;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\Event\MemberUpdatedEvent;
use Mublo\Service\Member\MemberAccountGateway;
use Mublo\Service\Member\MemberService;
use PHPUnit\Framework\TestCase;

final class MemberAccountGatewayTest extends TestCase
{
    public function testDelegatesRegistrationAndRelatedPersistenceToMemberService(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->expects($this->never())->method('create');
        $service = $this->createMock(MemberService::class);
        $request = new MemberRegistrationRequest(3, 'sns-user', 'secret-hash', '닉네임');
        $related = static function (int $memberId): void {};
        $service->expects($this->once())->method('registerAccount')
            ->with($request, $related)->willReturn(17);
        $gateway = $this->gateway($members, $service);
        $this->assertSame(17, $gateway->create($request, $related));
    }

    public function testCredentialVerificationDoesNotExposePasswordHash(): void
    {
        $hash = password_hash('correct', PASSWORD_BCRYPT);
        $member = Member::fromArray([
            'member_id' => 9,
            'domain_id' => 2,
            'user_id' => 'operator',
            'password' => $hash,
            'status' => 'active',
        ]);
        $profile = new MemberProfile(9, 2, 'operator', null, 10, true, admin: true);
        $members = $this->createMock(MemberRepository::class);
        $members->method('findByDomainAndUserId')->with(2, 'operator')->willReturn($member);
        $queries = $this->createMock(MemberQueryInterface::class);
        $queries->expects($this->once())->method('findProfile')->with(9)->willReturn($profile);

        $gateway = $this->gateway($members, queries: $queries);
        $this->assertNull($gateway->verifyCredentials(2, 'operator', 'wrong'));
        $this->assertSame($profile, $gateway->verifyCredentials(2, 'operator', 'correct'));
    }

    public function testChangeLevelMovesMemberWithinAllowedLevelsAndAnnouncesIt(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->with(5)->willReturn($this->member(5, 1));
        $members->expects($this->once())->method('updateLevelIfIn')->with(5, 2, [1, 2])->willReturn(1);
        $events = $this->createMock(EventDispatcher::class);
        $events->expects($this->once())->method('dispatch')->with($this->callback(
            static fn ($event) => $event instanceof MemberUpdatedEvent
                && $event->isLevelChanged()
                && $event->getSource() === MemberUpdatedEvent::SOURCE_API
                && $event->getPreviousLevelValue() === 1
        ))->willReturnArgument(0);

        $gateway = $this->gateway($members, events: $events);
        $this->assertTrue($gateway->changeLevel(5, 2, [1, 2]));
    }

    public function testChangeLevelLeavesLevelsOutsideTheAllowedSetAlone(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturnOnConsecutiveCalls($this->member(5, 7), $this->member(5, 2), null);
        $members->expects($this->never())->method('updateLevelIfIn');
        $events = $this->createMock(EventDispatcher::class);
        $events->expects($this->never())->method('dispatch');

        $gateway = $this->gateway($members, events: $events);
        $this->assertFalse($gateway->changeLevel(5, 2, [1, 2]), '관리자가 따로 준 등급');
        $this->assertFalse($gateway->changeLevel(5, 2, [1, 2]), '이미 그 등급');
        $this->assertFalse($gateway->changeLevel(5, 2, [1, 2]), '회원 없음');
    }

    public function testChangeLevelReportsNoChangeWhenTheLevelMovedConcurrently(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturn($this->member(5, 1));
        $members->method('updateLevelIfIn')->willReturn(0);
        $events = $this->createMock(EventDispatcher::class);
        $events->expects($this->never())->method('dispatch');

        $this->assertFalse($this->gateway($members, events: $events)->changeLevel(5, 2, [1, 2]));
    }

    public function testChangeLevelRefusesAdministrativeOrUnknownLevels(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->expects($this->never())->method('updateLevelIfIn');
        $gateway = $this->gateway($members);

        foreach ([[215, [1]], [2, [1, 255]], [99, [1]]] as [$to, $from]) {
            try {
                $gateway->changeLevel(5, $to, $from);
                $this->fail("{$to} ← " . implode(',', $from) . ' 는 거절해야 한다');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function gateway(
        MemberRepository $members,
        ?MemberService $service = null,
        ?MemberQueryInterface $queries = null,
        ?EventDispatcher $events = null
    ): MemberAccountGateway {
        return new MemberAccountGateway(
            $members,
            $service ?? $this->createStub(MemberService::class),
            $queries ?? $this->createStub(MemberQueryInterface::class),
            $this->levels(),
            $events
        );
    }

    /** 1 일반회원 · 2 셀러회원 · 7 관리자가 따로 주는 등급 · 215 사이트 운영자 · 255 최고관리자 (99 는 없다) */
    private function levels(): MemberLevelCatalogInterface
    {
        $levels = [
            1 => new MemberLevelDescriptor(1, 1, '일반회원', 'BASIC', false, false, false),
            2 => new MemberLevelDescriptor(2, 2, '셀러회원', 'BASIC', false, false, false),
            7 => new MemberLevelDescriptor(7, 7, '판매자', 'BASIC', false, false, false),
            215 => new MemberLevelDescriptor(4, 215, '사이트 운영자', 'SITE_MASTER', false, true, true),
            255 => new MemberLevelDescriptor(5, 255, '최고관리자', 'SUPER', true, true, true),
        ];
        $catalog = $this->createStub(MemberLevelCatalogInterface::class);
        $catalog->method('findByValue')->willReturnCallback(static fn (int $value) => $levels[$value] ?? null);

        return $catalog;
    }

    private function member(int $id, int $level): Member
    {
        return Member::fromArray([
            'member_id' => $id,
            'domain_id' => 1,
            'user_id' => 'user' . $id,
            'password' => 'hash',
            'level_value' => $level,
            'status' => 'active',
        ]);
    }
}
