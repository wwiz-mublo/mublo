<?php
namespace Tests\SnsLogin\Unit;

use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Plugin\SnsLogin\Subscriber\MemberLifecycleSubscriber;
use Mublo\Service\Member\Event\MemberDeletedEvent;
use Mublo\Service\Member\Event\MemberDeletingEvent;
use Mublo\Service\Member\Event\MemberWithdrawingEvent;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;
use PHPUnit\Framework\TestCase;

class MemberLifecycleSubscriberTest extends TestCase
{
    /**
     * 외부 폐기는 되돌릴 수 없으므로 탈퇴 확정 전에는 절대 나가면 안 된다.
     * 탈퇴 트랜잭션이 실패하면 회원은 살아 있는데 SNS 로그인만 끊긴 상태가 된다.
     */
    public function testNothingIsRevokedBeforeWithdrawalIsCommitted(): void
    {
        $this->assertArrayNotHasKey(
            MemberWithdrawingEvent::class,
            MemberLifecycleSubscriber::getSubscribedEvents(),
        );
    }

    public function testRevokesAndCleansUpOnlyAfterWithdrawalCommits(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->expects($this->once())->method('revokeAndCleanupForMember')
            ->with(10)->willReturn(['revoked' => 1, 'failed' => 0]);

        $subscriber = new MemberLifecycleSubscriber($connections, $this->createMock(Logger::class));
        $subscriber->onMemberWithdrawn(new MemberWithdrawnEvent($this->member()));
    }

    public function testCleanupFailureDoesNotEscapeIntoTheCommittedWithdrawal(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->method('revokeAndCleanupForMember')
            ->willThrowException(new \RuntimeException('storage down'));

        $subscriber = new MemberLifecycleSubscriber($connections, $this->createMock(Logger::class));

        $subscriber->onMemberWithdrawn(new MemberWithdrawnEvent($this->member()));
        $this->addToAssertionCount(1);
    }

    /**
     * 하드 삭제는 FK CASCADE 로 로컬 행을 함께 지운다.
     * 삭제 전에 토큰을 확보해 두지 않으면 제공자 연결을 영영 끊을 수 없다.
     */
    public function testCapturesTokensBeforeHardDeleteAndRevokesAfterwards(): void
    {
        $accounts = [$this->account()];
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->expects($this->once())->method('captureAccounts')->with(10)->willReturn($accounts);
        $connections->expects($this->once())->method('revokeDetachedAccounts')
            ->with($accounts)->willReturn(['revoked' => 1, 'failed' => 0]);

        $subscriber = new MemberLifecycleSubscriber($connections, $this->createMock(Logger::class));

        $subscriber->onMemberDeleting(new MemberDeletingEvent($this->member(), 3));
        $subscriber->onMemberDeleted(new MemberDeletedEvent($this->member(), 3));
    }

    public function testDoesNotBlockMemberDeletion(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->method('captureAccounts')->willReturn([$this->account()]);

        $subscriber = new MemberLifecycleSubscriber($connections, $this->createMock(Logger::class));
        $event = new MemberDeletingEvent($this->member(), 3);

        $subscriber->onMemberDeleting($event);

        $this->assertFalse($event->isBlocked());
    }

    public function testSkipsCaptureWhenAnotherSubscriberAlreadyBlockedDeletion(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->expects($this->never())->method('captureAccounts');

        $subscriber = new MemberLifecycleSubscriber($connections, $this->createMock(Logger::class));
        $event = new MemberDeletingEvent($this->member(), 3);
        $event->setBlocked(true, '정산이 필요합니다.');

        $subscriber->onMemberDeleting($event);

        $this->assertSame('정산이 필요합니다.', $event->getBlockReason());
    }

    public function testDeletionWithoutCapturedConnectionsRevokesNothing(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->expects($this->never())->method('revokeDetachedAccounts');

        $subscriber = new MemberLifecycleSubscriber($connections, $this->createMock(Logger::class));
        $subscriber->onMemberDeleted(new MemberDeletedEvent($this->member(), 3));
    }

    private function member(): Member
    {
        return Member::fromArray([
            'member_id' => 10,
            'domain_id' => 7,
            'user_id' => 'sns_kakao_user',
            'status' => 'active',
        ]);
    }

    private function account(): SnsAccount
    {
        return new SnsAccount(1, 7, 10, 'kakao', 'uid', null, '2026-07-26 21:00:00', 'access', 'refresh');
    }
}
