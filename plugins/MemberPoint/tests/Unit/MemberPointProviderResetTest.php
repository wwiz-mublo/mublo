<?php

namespace Tests\MemberPoint\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Plugin\MemberPoint\Service\MemberPointDataResetter;
use Mublo\Plugin\MemberPoint\MemberPointProvider;

/**
 * MemberPointDataResetter::reset() 회귀 테스트.
 *
 * '포인트 내역 삭제' 리셋은 balance_logs 의 MemberPoint 행을 지운 뒤, 영향받은 회원의
 * members.point_balance 스냅샷을 남은 원장 합계로 재정합해야 한다(= 적립 포인트 회수).
 * 재정합을 빠뜨리면 원장≠스냅샷 불일치가 남아 이후 무결성 repair 가 잔액을 소급 삭감한다.
 */
class MemberPointProviderResetTest extends TestCase
{
    #[Test]
    public function testResetDelegatesCoreLedgerMutationToResetGateway(): void
    {
        $gateway = $this->createMock(BalanceResetGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('resetSource')
            ->with(1, 'plugin', 'MemberPoint')
            ->willReturn(2);

        $result = (new MemberPointDataResetter($gateway))->reset('memberpoint', 1);

        $this->assertSame(1, $result->tablesCleared);
        $this->assertStringContainsString('2건 삭제', $result->details);
    }

    #[Test]
    public function testResetReportsNoClearedTableWhenSourceHasNoLogs(): void
    {
        $gateway = $this->createStub(BalanceResetGatewayInterface::class);
        $gateway->method('resetSource')->willReturn(0);

        $result = (new MemberPointDataResetter($gateway))->reset('memberpoint', 1);

        $this->assertSame(0, $result->tablesCleared);
    }

    #[Test]
    public function testResetRejectsUnknownCategory(): void
    {
        $gateway = $this->createMock(BalanceResetGatewayInterface::class);
        $gateway->expects($this->never())->method('resetSource');

        $result = (new MemberPointDataResetter($gateway))->reset('something-else', 1);

        $this->assertSame(0, $result->tablesCleared);
    }

    #[Test]
    public function testProviderDelegatesToRegisteredResetter(): void
    {
        $gateway = $this->createStub(BalanceResetGatewayInterface::class);
        $gateway->method('resetSource')->willReturn(1);
        $resetter = new MemberPointDataResetter($gateway);
        $provider = new MemberPointProvider($resetter);

        $this->assertEquals($resetter->getResetCategories(), $provider->getResetCategories());
        $this->assertSame(1, $provider->reset('memberpoint', 1)->tablesCleared);
    }
}
