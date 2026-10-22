<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Contract\Member\MemberSegmentQueryInterface;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\CouponBulkIssueService;
use Mublo\Packages\Shop\Service\CouponService;
use PHPUnit\Framework\TestCase;

/**
 * 쿠폰 일괄 지급은 조건으로 고른 회원마다 관리자 발행을 거치고,
 * 이미 받은 회원은 건너뛰며, 정책 단위로 막히면 멈춘다.
 */
class CouponBulkIssueServiceTest extends TestCase
{
    private function service(CouponService $coupons, array $memberIds, array $totals = []): CouponBulkIssueService
    {
        $members = $this->createMock(MemberSegmentQueryInterface::class);
        $members->method('findActiveMemberIds')->willReturn($memberIds);

        $orders = $this->createMock(OrderRepository::class);
        $orders->method('getPaidTotalsByMembers')->willReturn($totals);

        return new CouponBulkIssueService($coupons, $members, $orders);
    }

    public function testPurchaseConditionsFilterByPaidTotals(): void
    {
        $coupons = $this->createMock(CouponService::class);
        $totals = [1 => 50000, 3 => 300000];

        $this->assertSame([1, 3], $this->service($coupons, [1, 2, 3], $totals)->findTargets(5, ['purchase' => 'yes']));
        $this->assertSame([2], $this->service($coupons, [1, 2, 3], $totals)->findTargets(5, ['purchase' => 'no']));
        $this->assertSame([3], $this->service($coupons, [1, 2, 3], $totals)->findTargets(5, ['purchase' => 'yes', 'min_purchase' => 100000]));
        $this->assertSame([1, 2, 3], $this->service($coupons, [1, 2, 3], $totals)->findTargets(5, []));
    }

    public function testIssuesThroughAdminPathAndCountsSkipped(): void
    {
        $coupons = $this->createMock(CouponService::class);
        $coupons->expects($this->exactly(3))->method('issueByAdmin')
            ->willReturnCallback(fn(int $group, int $member, int $domain) => $member === 2
                ? Result::failure('쿠폰 발행 한도를 초과했습니다.')
                : Result::success('쿠폰이 발행되었습니다.'));

        $result = $this->service($coupons, [1, 2, 3])->issue(5, 9, []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->get('issued'));
        $this->assertSame(1, $result->get('skipped'));
        $this->assertSame(0, $result->get('failed'));
    }

    public function testPolicyLevelRejectionStopsAtFirstMember(): void
    {
        $coupons = $this->createMock(CouponService::class);
        $coupons->expects($this->once())->method('issueByAdmin')
            ->willReturn(Result::failure('현재 발행이 중지된 쿠폰입니다.'));

        $result = $this->service($coupons, [1, 2, 3])->issue(5, 9, []);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('현재 발행이 중지된 쿠폰입니다.', $result->getMessage());
    }

    public function testSoldOutMidwayStopsAndReportsRemaining(): void
    {
        $coupons = $this->createMock(CouponService::class);
        $coupons->method('issueByAdmin')
            ->willReturnCallback(fn(int $group, int $member) => $member === 1
                ? Result::success('쿠폰이 발행되었습니다.')
                : Result::failure('쿠폰 발행 수량이 모두 소진되었습니다.'));

        $result = $this->service($coupons, [1, 2, 3])->issue(5, 9, []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->get('issued'));
        $this->assertSame(2, $result->get('failed'));
    }

    public function testNoTargetsFails(): void
    {
        $coupons = $this->createMock(CouponService::class);
        $coupons->expects($this->never())->method('issueByAdmin');

        $this->assertFalse($this->service($coupons, [])->issue(5, 9, [])->isSuccess());
    }
}
