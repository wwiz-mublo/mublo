<?php
/**
 * CouponAutoIssueSubscriber 단위 테스트
 *
 * LEVEL 트리거의 등급 제한(allowed_member_levels) 판정이 '변경 후' 등급을 기준으로
 * 이뤄지는지 검증한다. 이벤트가 실어 보내는 회원 엔티티는 수정 전 스냅샷이라
 * 그것으로 판정하면 승급자가 쿠폰을 못 받고 강등자가 상위 등급 쿠폰을 받는다.
 */

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Entity\Member\Member;
use Mublo\Packages\Shop\Entity\CouponPolicy;
use Mublo\Packages\Shop\EventSubscriber\CouponAutoIssueSubscriber;
use Mublo\Packages\Shop\Repository\CouponRepository;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Service\Member\Event\MemberUpdatedByAdminEvent;
use Mublo\Core\Result\Result;
use Tests\Shop\TestCase;

class CouponAutoIssueSubscriberTest extends TestCase
{
    private const MEMBER_ID = 7;
    private const DOMAIN_ID = 1;
    private const VIP_COUPON = 42;

    /** 일반(1) → VIP(5) 승급: VIP 전용 쿠폰이 발행돼야 한다 */
    public function testIssuesCouponUsingLevelAfterUpdate(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->once())
            ->method('issueCoupon')
            ->with(self::VIP_COUPON, self::MEMBER_ID)
            ->willReturn(Result::success('발행'));

        $subscriber = $this->makeSubscriber(
            $couponService,
            $this->policies(['5']),
            $this->profileWithLevel(5)
        );

        // 이벤트에 실리는 엔티티는 승급 '전'(1등급) 스냅샷이다
        $subscriber->onMemberUpdated($this->levelChangedEvent(1));
    }

    /** VIP(5) → 일반(1) 강등: 옛 등급으로 판정하면 VIP 쿠폰이 잘못 나간다 */
    public function testDoesNotIssueCouponWhenDemotedBelowAllowedLevel(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->never())->method('issueCoupon');

        $subscriber = $this->makeSubscriber(
            $couponService,
            $this->policies(['5']),
            $this->profileWithLevel(1)
        );

        $subscriber->onMemberUpdated($this->levelChangedEvent(5));
    }

    /** 등급 제한이 없는 정책은 회원 등급을 보지 않고 발행한다 */
    public function testIssuesCouponWhenPolicyHasNoLevelRestriction(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->once())
            ->method('issueCoupon')
            ->with(self::VIP_COUPON, self::MEMBER_ID)
            ->willReturn(Result::success('발행'));

        $subscriber = $this->makeSubscriber(
            $couponService,
            $this->policies([null]),
            $this->profileWithLevel(3)
        );

        $subscriber->onMemberUpdated($this->levelChangedEvent(1));
    }

    /** 회원 조회 실패로 등급을 확정할 수 없으면 제한 있는 정책은 발행하지 않는다 */
    public function testSkipsRestrictedPolicyWhenMemberLevelUnresolvable(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->never())->method('issueCoupon');

        $subscriber = $this->makeSubscriber(
            $couponService,
            $this->policies(['5']),
            null
        );

        $subscriber->onMemberUpdated($this->levelChangedEvent(5));
    }

    /** 등급 외 항목만 수정된 경우 LEVEL 트리거는 아예 동작하지 않는다 */
    public function testIgnoresUpdateWithoutLevelChange(): void
    {
        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->never())->method('issueCoupon');

        $couponRepository = $this->createMock(CouponRepository::class);
        $couponRepository->expects($this->never())->method('getAutoIssuePolicies');

        $members = $this->createMock(MemberQueryInterface::class);
        $members->expects($this->never())->method('findProfile');

        $subscriber = new CouponAutoIssueSubscriber($couponService, $couponRepository, $members);
        $subscriber->onMemberUpdated(
            new MemberUpdatedByAdminEvent($this->member(1), ['nickname'], 0)
        );
    }

    // =========================================================
    // 헬퍼
    // =========================================================

    private function makeSubscriber(
        CouponService $couponService,
        array $policies,
        ?MemberProfile $profile
    ): CouponAutoIssueSubscriber {
        $couponRepository = $this->createMock(CouponRepository::class);
        $couponRepository->method('getAutoIssuePolicies')
            ->with(self::DOMAIN_ID, 'LEVEL')
            ->willReturn($policies);

        $members = $this->createMock(MemberQueryInterface::class);
        $members->method('findProfile')->with(self::MEMBER_ID)->willReturn($profile);

        return new CouponAutoIssueSubscriber($couponService, $couponRepository, $members);
    }

    /** @param list<string|null> $allowedLevels 정책별 allowed_member_levels 값 */
    private function policies(array $allowedLevels): array
    {
        return array_map(
            fn (?string $levels) => CouponPolicy::fromArray([
                'coupon_group_id'       => self::VIP_COUPON,
                'domain_id'             => self::DOMAIN_ID,
                'coupon_type'           => 'AUTO',
                'auto_issue_trigger'    => 'LEVEL',
                'allowed_member_levels' => $levels,
            ]),
            $allowedLevels
        );
    }

    private function levelChangedEvent(int $levelBeforeUpdate): MemberUpdatedByAdminEvent
    {
        return new MemberUpdatedByAdminEvent($this->member($levelBeforeUpdate), ['level_value'], 0);
    }

    private function member(int $levelValue): Member
    {
        return Member::fromArray([
            'member_id'   => self::MEMBER_ID,
            'domain_id'   => self::DOMAIN_ID,
            'user_id'     => 'tester',
            'level_value' => $levelValue,
        ]);
    }

    private function profileWithLevel(int $levelValue): MemberProfile
    {
        return new MemberProfile(
            memberId: self::MEMBER_ID,
            domainId: self::DOMAIN_ID,
            userId: 'tester',
            nickname: '테스터',
            levelValue: $levelValue,
            active: true
        );
    }
}
