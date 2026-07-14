<?php
/**
 * packages/Shop/tests/Unit/Service/OrderPointServiceTest.php
 *
 * OrderPointService::validate() 단위 테스트
 *
 * validate()는 의존성을 호출하지 않는 순수 검증 로직이므로
 * 생성자 의존성은 Mock으로 대체하고 검증 규칙만 확인한다.
 *
 * 검증 규칙:
 * - 0 이하: 미사용으로 통과
 * - 비활성(enabled=false): 실패
 * - 단위(unit) 배수 아님: 실패
 * - 최소(min) 미만: 실패
 * - 최대(max) 초과: 실패
 * - 잔액(balance) 초과: 실패
 * - 결제금액(payable) 초과: 실패
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\OrderPointService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Contract\Balance\BalanceGatewayInterface;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Core\Result\Result;

class OrderPointServiceTest extends TestCase
{
    private OrderPointService $service;
    private BalanceGatewayInterface $balanceGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->balanceGateway = $this->createMock(BalanceGatewayInterface::class);
        $this->service = new OrderPointService(
            $this->balanceGateway,
            $this->createMock(ShopConfigService::class),
            $this->createMock(MemberLevelService::class),
        );
    }

    /** 표준 사용 컨텍스트 (단위 100, 최소 100, 최대 30000, 잔액 50000) */
    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'unit'    => 100,
            'min'     => 100,
            'max'     => 30000,
            'balance' => 50000,
        ], $overrides);
    }

    public function testZeroPointIsAllowedAsNoUsage(): void
    {
        $result = $this->service->validate(0, $this->ctx(), 20000);
        $this->assertTrue($result->isSuccess());
    }

    public function testDisabledRejectsUsage(): void
    {
        $result = $this->service->validate(1000, $this->ctx(['enabled' => false]), 20000);
        $this->assertTrue($result->isFailure());
    }

    public function testNonUnitMultipleRejected(): void
    {
        // 단위 100인데 150 → 실패
        $result = $this->service->validate(150, $this->ctx(), 20000);
        $this->assertTrue($result->isFailure());
    }

    public function testBelowMinimumRejected(): void
    {
        // 최소 100인데 단위는 50으로 낮춰 50 사용 시도 → 최소 미만
        $result = $this->service->validate(50, $this->ctx(['unit' => 50]), 20000);
        $this->assertTrue($result->isFailure());
    }

    public function testAboveMaximumRejected(): void
    {
        // 최대 30000 초과
        $result = $this->service->validate(30100, $this->ctx(), 50000);
        $this->assertTrue($result->isFailure());
    }

    public function testExceedingBalanceRejected(): void
    {
        $result = $this->service->validate(20000, $this->ctx(['balance' => 1000, 'max' => 0]), 50000);
        $this->assertTrue($result->isFailure());
    }

    public function testExceedingPayableRejected(): void
    {
        // 잔액·한도는 충분하지만 결제금액(5000) 초과
        $result = $this->service->validate(10000, $this->ctx(['balance' => 100000]), 5000);
        $this->assertTrue($result->isFailure());
    }

    public function testValidUsagePasses(): void
    {
        // 단위 배수, 최소~최대, 잔액·결제금액 이내
        $result = $this->service->validate(10000, $this->ctx(), 20000);
        $this->assertTrue($result->isSuccess());
    }

    public function testUsageEqualToPayablePasses(): void
    {
        // 포인트로 전액 결제 (point == payable)
        $result = $this->service->validate(20000, $this->ctx(['balance' => 20000]), 20000);
        $this->assertTrue($result->isSuccess());
    }

    public function testNoMinNoMaxOnlyBalanceAndPayable(): void
    {
        // min/max 0 (제한 없음) → 단위·잔액·결제금액만 검증
        $result = $this->service->validate(300, $this->ctx(['min' => 0, 'max' => 0]), 20000);
        $this->assertTrue($result->isSuccess());
    }

    public function testHoldUsesOrderScopedIdempotencyKey(): void
    {
        $this->balanceGateway->expects($this->once())
            ->method('adjust')
            ->with($this->callback(static fn(array $params): bool =>
                $params['amount'] === -700
                && $params['action'] === 'order_point_hold'
                && $params['idempotency_key'] === 'shop_point_hold_ORD-1'
            ))
            ->willReturn(Result::success('ok'));

        $result = $this->service->hold([
            'order_no' => 'ORD-1',
            'member_id' => 10,
            'domain_id' => 1,
            'point_used' => 700,
        ]);

        $this->assertTrue($result->isSuccess());
    }

    public function testTwoOrdersCannotBothHoldTheSameAvailableBalance(): void
    {
        $balance = 1000;
        $this->balanceGateway->method('adjust')
            ->willReturnCallback(static function (array $params) use (&$balance): Result {
                $next = $balance + (int) $params['amount'];
                if ($next < 0) {
                    return Result::failure('잔액이 부족합니다.');
                }
                $balance = $next;
                return Result::success('ok');
            });

        $base = ['member_id' => 10, 'domain_id' => 1, 'point_used' => 700];
        $first = $this->service->hold($base + ['order_no' => 'ORD-A']);
        $second = $this->service->hold($base + ['order_no' => 'ORD-B']);

        $this->assertTrue($first->isSuccess());
        $this->assertTrue($second->isFailure());
        $this->assertSame(300, $balance);
    }

    public function testConfirmRequiresExistingHold(): void
    {
        $this->balanceGateway->method('findLogByIdempotencyKey')->willReturn(null);

        $result = $this->service->confirm([
            'order_no' => 'ORD-1', 'member_id' => 10, 'domain_id' => 1, 'point_used' => 700,
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testReleaseOnlyCreditsAnActuallyHeldOrder(): void
    {
        $this->balanceGateway->method('findLogByIdempotencyKey')
            ->with('shop_point_hold_ORD-1', 1)
            ->willReturn(['log_id' => 1]);
        $this->balanceGateway->expects($this->once())
            ->method('adjust')
            ->with($this->callback(static fn(array $params): bool =>
                $params['amount'] === 700
                && $params['action'] === 'order_point_release'
                && $params['idempotency_key'] === 'shop_point_release_ORD-1'
            ))
            ->willReturn(Result::success('ok'));

        $result = $this->service->release([
            'order_no' => 'ORD-1', 'member_id' => 10, 'domain_id' => 1, 'point_used' => 700,
        ]);

        $this->assertTrue($result->isSuccess());
    }
}
