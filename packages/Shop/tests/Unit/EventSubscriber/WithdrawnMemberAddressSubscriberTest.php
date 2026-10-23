<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\EventSubscriber\WithdrawnMemberAddressSubscriber;
use Mublo\Packages\Shop\Repository\MemberAddressRepository;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;
use PHPUnit\Framework\TestCase;

/**
 * 탈퇴는 소프트 삭제라 주소록 FK CASCADE 가 동작하지 않는다 — 구독자가 직접 지운다.
 */
final class WithdrawnMemberAddressSubscriberTest extends TestCase
{
    public function testWithdrawalDeletesTheMembersAddresses(): void
    {
        $addresses = $this->createMock(MemberAddressRepository::class);
        $addresses->expects($this->once())->method('deleteByMember')->with(7)->willReturn(2);

        (new WithdrawnMemberAddressSubscriber($addresses, $this->createMock(Logger::class)))
            ->onMemberWithdrawn($this->withdrawn());
    }

    /** 탈퇴는 이미 커밋됐다 — 정리에 실패해도 예외를 올리지 않고 로그만 남긴다. */
    public function testCleanupFailureIsLoggedNotThrown(): void
    {
        $addresses = $this->createStub(MemberAddressRepository::class);
        $addresses->method('deleteByMember')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('exception');

        (new WithdrawnMemberAddressSubscriber($addresses, $logger))
            ->onMemberWithdrawn($this->withdrawn());
    }

    private function withdrawn(): MemberWithdrawnEvent
    {
        return new MemberWithdrawnEvent(
            Member::fromArray([
                'member_id' => 7,
                'domain_id' => 1,
                'user_id' => 'buyer',
                'password' => 'hash',
                'status' => 'active',
            ]),
            '개인 사유',
        );
    }
}
