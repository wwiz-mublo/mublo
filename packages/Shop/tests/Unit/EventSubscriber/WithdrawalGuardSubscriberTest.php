<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\EventSubscriber\WithdrawalGuardSubscriber;
use Mublo\Packages\Shop\Repository\WithdrawalBlockerRepository;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Service\Member\Event\MemberWithdrawingEvent;
use PHPUnit\Framework\TestCase;

/**
 * 이행이 끝나지 않은 거래가 있으면 탈퇴를 보류시킨다.
 *
 * 탈퇴는 포인트 잔액을 0으로 정리하고 회원 쪽 창구를 닫는다. 그 뒤 취소·반품이 나오면
 * 환불할 곳이 없다 — 사용자가 동의를 눌러도 환불 의무는 남으므로, 경고가 아니라 보류다.
 */
final class WithdrawalGuardSubscriberTest extends TestCase
{
    public function testUnfinishedOrderHoldsTheWithdrawal(): void
    {
        $event = $this->withdrawing();

        $this->subscriber([
            ['order_no' => '20260917-0001', 'status' => 'shipping'],
        ])->onMemberWithdrawing($event);

        $this->assertTrue($event->isBlocked());
        // 어느 주문 때문인지 알려줘야 스스로 확인하고 기다릴 수 있다.
        $this->assertStringContainsString('20260917-0001', $event->getBlockReason());
        $this->assertStringContainsString('배송중', $event->getBlockReason());
    }

    /** 주문이 배송완료로 보여도 반품 심사가 열려 있을 수 있다. */
    public function testOpenClaimHoldsTheWithdrawal(): void
    {
        $event = $this->withdrawing();

        $this->subscriber([], [
            ['order_no' => '20260917-0002', 'status' => 'INSPECTING'],
        ])->onMemberWithdrawing($event);

        $this->assertTrue($event->isBlocked());
        $this->assertStringContainsString('20260917-0002', $event->getBlockReason());
    }

    public function testNothingPendingLetsTheWithdrawalThrough(): void
    {
        $event = $this->withdrawing();

        $this->subscriber([])->onMemberWithdrawing($event);

        $this->assertFalse($event->isBlocked());
    }

    /**
     * 쇼핑 데이터를 지키려는 장치가 쇼핑을 쓰지 않는 사이트의 탈퇴까지 멈추면 안 된다.
     * 조회에 실패하면 보류하지 않고 로그만 남긴다.
     */
    public function testUnreachableStorageDoesNotHoldTheWithdrawal(): void
    {
        $event = $this->withdrawing();
        $repository = $this->createStub(WithdrawalBlockerRepository::class);
        $repository->method('findUnfinishedOrders')->willThrowException(new \RuntimeException('db down'));

        (new WithdrawalGuardSubscriber($repository, $this->states(), $this->createMock(Logger::class)))
            ->onMemberWithdrawing($event);

        $this->assertFalse($event->isBlocked());
    }

    /** 앞선 구독자가 이미 막았다면 그 사유가 더 구체적일 수 있다 — 덮어쓰지 않는다. */
    public function testAnEarlierBlockReasonIsLeftIntact(): void
    {
        $event = $this->withdrawing();
        $event->setBlocked(true, '미정산 대금이 남아 있습니다.');

        $this->subscriber([
            ['order_no' => '20260917-0003', 'status' => 'paid'],
        ])->onMemberWithdrawing($event);

        $this->assertSame('미정산 대금이 남아 있습니다.', $event->getBlockReason());
    }

    /**
     * 첫 줄은 상황과 해야 할 일, 그 아래는 주문 한 건씩.
     *
     * 탈퇴 화면이 이 줄들을 목록 항목으로 쪼개 그리므로 형태가 계약이다. 글머리 기호를
     * 붙이면 목록 안에 기호가 두 번 들어가고, 가운데 정렬인 알림창에서는 들쭉날쭉해진다.
     */
    public function testSummaryComesFirstAndOrdersFollowOnePerLine(): void
    {
        $event = $this->withdrawing();

        $this->subscriber([
            ['order_no' => '20260917-0001', 'status' => 'paid'],
            ['order_no' => '20260917-0002', 'status' => 'shipping'],
        ])->onMemberWithdrawing($event);

        $lines = explode("\n", $event->getBlockReason());

        $this->assertStringContainsString('탈퇴할 수 없습니다', $lines[0]);
        $this->assertStringContainsString('다시 시도해주세요', $lines[0]);
        $this->assertSame('20260917-0001 (결제완료)', $lines[1]);
        $this->assertSame('20260917-0002 (배송중)', $lines[2]);
    }

    /** 주문이 많아도 메시지가 화면을 뒤덮지 않아야 한다. */
    public function testLongListIsSummarised(): void
    {
        $blocking = [];
        for ($i = 1; $i <= 8; $i++) {
            $blocking[] = ['order_no' => '20260917-000' . $i, 'status' => 'paid'];
        }

        $event = $this->withdrawing();
        $this->subscriber($blocking)->onMemberWithdrawing($event);

        $this->assertStringContainsString("\n외 3건", $event->getBlockReason());
    }

    /**
     * @param list<array{order_no: string, status: string}> $orders
     * @param list<array{order_no: string, status: string}> $claims
     */
    private function subscriber(array $orders, array $claims = []): WithdrawalGuardSubscriber
    {
        $repository = $this->createStub(WithdrawalBlockerRepository::class);
        $repository->method('findUnfinishedOrders')->willReturn($orders);
        $repository->method('findOpenClaims')->willReturn($claims);

        return new WithdrawalGuardSubscriber($repository, $this->states(), $this->createMock(Logger::class));
    }

    /**
     * 주문 상태는 도메인 설정에서 온다 — 기본 정의를 그대로 쓴다.
     * 여기서 라벨이 나오는지가 화면 안내의 품질을 좌우한다.
     */
    private function states(): OrderStateResolver
    {
        $states = $this->createStub(OrderStateResolver::class);
        $states->method('getAllStates')->willReturn(\Mublo\Packages\Shop\Enum\OrderAction::defaultStates());
        $states->method('getLabel')->willReturnCallback(
            function (int $domainId, string $stateId): string {
                foreach (\Mublo\Packages\Shop\Enum\OrderAction::defaultStates() as $state) {
                    if (($state['id'] ?? null) === $stateId) {
                        return (string) $state['label'];
                    }
                }
                return $stateId;
            }
        );

        return $states;
    }

    private function withdrawing(): MemberWithdrawingEvent
    {
        return new MemberWithdrawingEvent(
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
