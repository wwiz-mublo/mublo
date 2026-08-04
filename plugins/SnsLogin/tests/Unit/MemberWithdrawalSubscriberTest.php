<?php
namespace Tests\SnsLogin\Unit;

use Mublo\Core\Result\Result;
use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Plugin\SnsLogin\Subscriber\MemberWithdrawalSubscriber;
use Mublo\Service\Member\Event\MemberWithdrawingEvent;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;
use PHPUnit\Framework\TestCase;

class MemberWithdrawalSubscriberTest extends TestCase
{
    public function testBlocksWithdrawalWhenExternalRevocationFails(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->method('revokeAllForMember')->with(10)
            ->willReturn(Result::failure('카카오 연결 해제에 실패했습니다.'));
        $subscriber = new MemberWithdrawalSubscriber($connections, $this->createMock(Logger::class));
        $event = new MemberWithdrawingEvent($this->member());

        $subscriber->onMemberWithdrawing($event);

        $this->assertTrue($event->isBlocked());
        $this->assertSame('카카오 연결 해제에 실패했습니다.', $event->getBlockReason());
    }

    public function testDeletesLocalTokensOnlyAfterWithdrawalCompletes(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->expects($this->once())->method('deleteLocalConnections')->with(10)->willReturn(1);
        $subscriber = new MemberWithdrawalSubscriber($connections, $this->createMock(Logger::class));

        $subscriber->onMemberWithdrawn(new MemberWithdrawnEvent($this->member()));
    }

    public function testDoesNotRevokeWhenAnotherPluginAlreadyBlockedWithdrawal(): void
    {
        $connections = $this->createMock(SnsConnectionManager::class);
        $connections->expects($this->never())->method('revokeAllForMember');
        $subscriber = new MemberWithdrawalSubscriber($connections, $this->createMock(Logger::class));
        $event = new MemberWithdrawingEvent($this->member());
        $event->setBlocked(true, '정산이 필요합니다.');

        $subscriber->onMemberWithdrawing($event);

        $this->assertSame('정산이 필요합니다.', $event->getBlockReason());
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
}
