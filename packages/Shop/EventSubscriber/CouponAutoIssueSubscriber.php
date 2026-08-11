<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Shop\Repository\CouponRepository;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Service\Auth\Event\MemberLoggedInEvent;
use Mublo\Service\Member\Event\MemberRegisteredByUserEvent;
use Mublo\Service\Member\Event\MemberUpdatedEvent;

/**
 * 쿠폰 자동 발행 Subscriber
 *
 * 회원 이벤트를 구독하여 auto_issue_trigger 조건에 맞는 쿠폰을 자동 발행한다.
 *
 * 지원 트리거:
 * - JOIN: 회원가입 완료 시
 * - LOGIN: 로그인 완료 시
 * - LEVEL: 회원 등급 변경 시
 */
class CouponAutoIssueSubscriber implements EventSubscriberInterface
{
    private CouponService $couponService;
    private CouponRepository $couponRepository;
    private MemberQueryInterface $members;

    public function __construct(
        CouponService $couponService,
        CouponRepository $couponRepository,
        MemberQueryInterface $members
    ) {
        $this->couponService = $couponService;
        $this->couponRepository = $couponRepository;
        $this->members = $members;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MemberRegisteredByUserEvent::class => 'onMemberRegistered',
            MemberLoggedInEvent::class => 'onMemberLoggedIn',
            MemberUpdatedEvent::class => 'onMemberUpdated',
        ];
    }

    /**
     * 회원가입 시 JOIN 트리거 쿠폰 자동 발행
     */
    public function onMemberRegistered(MemberRegisteredByUserEvent $event): void
    {
        $domainId = $event->getDomainId();
        $memberId = $event->getMemberId();

        $policies = $this->couponRepository->getAutoIssuePolicies($domainId, 'JOIN');

        foreach ($policies as $policy) {
            $this->couponService->issueCoupon($policy->getCouponGroupId(), $memberId);
        }
    }

    /**
     * 로그인 시 LOGIN 트리거 쿠폰 자동 발행
     */
    public function onMemberLoggedIn(MemberLoggedInEvent $event): void
    {
        $domainId = $event->getDomainId();
        $memberId = $event->getMemberId();

        $policies = $this->couponRepository->getAutoIssuePolicies($domainId, 'LOGIN');

        foreach ($policies as $policy) {
            $this->couponService->issueCoupon($policy->getCouponGroupId(), $memberId);
        }
    }

    /**
     * 회원 등급 변경 시 LEVEL 트리거 쿠폰 자동 발행
     */
    public function onMemberUpdated(MemberUpdatedEvent $event): void
    {
        if (!$event->isLevelChanged()) {
            return;
        }

        $domainId = $event->getDomainId();
        $memberId = $event->getMemberId();

        $policies = $this->couponRepository->getAutoIssuePolicies($domainId, 'LEVEL');
        if ($policies === []) {
            return;
        }

        // 등급 제한 판정은 '변경 후' 등급으로 해야 한다. 이벤트가 실어 보내는 회원 객체는
        // 수정 전에 읽어둔 스냅샷이라 등급 컬럼이 옛 값이다 — 그것으로 판정하면 승급자는
        // 쿠폰을 못 받고 강등자가 상위 등급 쿠폰을 받는다. 커밋된 값을 Contract로 재조회한다.
        $memberLevel = $this->members->findProfile($memberId)?->levelValue;

        foreach ($policies as $policy) {
            // allowed_member_levels가 설정되어 있으면 변경 후 등급과 비교
            $allowedLevels = $policy->getAllowedMemberLevels();
            if (!empty($allowedLevels)) {
                // 등급을 확인할 수 없으면 제한이 걸린 정책은 발행하지 않는다(fail-closed)
                if ($memberLevel === null) {
                    continue;
                }

                $levels = array_map('intval', explode(',', $allowedLevels));
                if (!in_array($memberLevel, $levels, true)) {
                    continue;
                }
            }

            $this->couponService->issueCoupon($policy->getCouponGroupId(), $memberId);
        }
    }
}
