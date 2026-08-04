<?php

namespace Tests\MemberPoint\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Plugin\MemberPoint\Service\MemberPointService;
use Mublo\Plugin\MemberPoint\Service\MemberPointConfigService;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Core\Result\Result;

/**
 * MemberPointServiceTest
 *
 * 회원 포인트 지급 서비스 테스트
 */
class MemberPointServiceTest extends TestCase
{
    private MockObject $balanceManagerMock;
    private MockObject $configServiceMock;
    private MemberPointService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->balanceManagerMock = $this->createMock(BalanceManager::class);
        $this->configServiceMock  = $this->createMock(MemberPointConfigService::class);

        $this->service = new MemberPointService(
            $this->balanceManagerMock,
            $this->configServiceMock
        );
    }

    // ─── awardSignup ───

    public function testAwardSignupReturnsTrueOnSuccess(): void
    {
        $this->configServiceMock->method('getActionConfig')
            ->with(10, 'signup')
            ->willReturn(['enabled' => true, 'point' => 1000]);

        $this->balanceManagerMock->expects($this->once())
            ->method('adjust')
            ->willReturn(Result::success('포인트가 조정되었습니다.'));

        $result = $this->service->awardSignup(10, 100);

        $this->assertTrue($result);
    }

    public function testAwardSignupReturnsFalseWhenDisabled(): void
    {
        $this->configServiceMock->method('getActionConfig')
            ->willReturn(['enabled' => false, 'point' => 1000]);

        $this->balanceManagerMock->expects($this->never())
            ->method('adjust');

        $result = $this->service->awardSignup(10, 100);

        $this->assertFalse($result);
    }

    public function testAwardSignupReturnsFalseWhenPointIsZero(): void
    {
        $this->configServiceMock->method('getActionConfig')
            ->willReturn(['enabled' => true, 'point' => 0]);

        $this->balanceManagerMock->expects($this->never())
            ->method('adjust');

        $result = $this->service->awardSignup(10, 100);

        $this->assertFalse($result);
    }

    public function testAwardSignupReturnsFalseWhenBalanceManagerFails(): void
    {
        $this->configServiceMock->method('getActionConfig')
            ->willReturn(['enabled' => true, 'point' => 500]);

        $this->balanceManagerMock->method('adjust')
            ->willReturn(Result::failure('잔액 조정 실패'));

        $result = $this->service->awardSignup(10, 100);

        $this->assertFalse($result);
    }

    public function testAwardSignupPassesCorrectIdempotencyKey(): void
    {
        $this->configServiceMock->method('getActionConfig')
            ->willReturn(['enabled' => true, 'point' => 1000]);

        $this->balanceManagerMock->expects($this->once())
            ->method('adjust')
            ->with($this->callback(function (array $params) {
                return $params['idempotency_key'] === 'mp_signup_10_42'
                    && $params['domain_id'] === 10
                    && $params['member_id'] === 42
                    && $params['amount'] === 1000;
            }))
            ->willReturn(Result::success('포인트가 조정되었습니다.'));

        $this->service->awardSignup(10, 42);
    }

    // ─── awardLevelUp ───

    public function testAwardLevelUpReturnsTrueOnSuccess(): void
    {
        $this->configServiceMock->method('getLevelUpConfig')
            ->with(10)
            ->willReturn(['enabled' => true, 'levels' => ['3' => 500, '5' => 1000]]);

        $this->balanceManagerMock->expects($this->once())
            ->method('adjust')
            ->willReturn(Result::success('포인트가 조정되었습니다.'));

        $result = $this->service->awardLevelUp(10, 100, 3);

        $this->assertTrue($result);
    }

    public function testAwardLevelUpReturnsFalseWhenDisabled(): void
    {
        $this->configServiceMock->method('getLevelUpConfig')
            ->willReturn(['enabled' => false, 'levels' => ['3' => 500]]);

        $this->balanceManagerMock->expects($this->never())
            ->method('adjust');

        $result = $this->service->awardLevelUp(10, 100, 3);

        $this->assertFalse($result);
    }

    public function testAwardLevelUpReturnsFalseWhenLevelNotConfigured(): void
    {
        $this->configServiceMock->method('getLevelUpConfig')
            ->willReturn(['enabled' => true, 'levels' => ['5' => 1000]]);

        $this->balanceManagerMock->expects($this->never())
            ->method('adjust');

        // 레벨 3은 설정에 없음
        $result = $this->service->awardLevelUp(10, 100, 3);

        $this->assertFalse($result);
    }

    public function testAwardLevelUpUsesLevelSpecificPoint(): void
    {
        $this->configServiceMock->method('getLevelUpConfig')
            ->willReturn(['enabled' => true, 'levels' => ['3' => 300, '5' => 500]]);

        $this->balanceManagerMock->expects($this->once())
            ->method('adjust')
            ->with($this->callback(fn(array $p) => $p['amount'] === 500 && $p['action'] === 'level_up'))
            ->willReturn(Result::success('포인트가 조정되었습니다.'));

        $this->service->awardLevelUp(10, 100, 5);
    }

    public function testAwardLevelUpIdempotencyKeyIncludesLevelValue(): void
    {
        $this->configServiceMock->method('getLevelUpConfig')
            ->willReturn(['enabled' => true, 'levels' => ['3' => 300]]);

        $this->balanceManagerMock->expects($this->once())
            ->method('adjust')
            ->with($this->callback(fn(array $p) => $p['idempotency_key'] === 'mp_levelup_10_100_3'))
            ->willReturn(Result::success('포인트가 조정되었습니다.'));

        $this->service->awardLevelUp(10, 100, 3);
    }
}
