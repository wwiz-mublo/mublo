<?php

namespace Mublo\Packages\Shop\EventSubscriber;

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

    public function __construct(CouponService $couponService, CouponRepository $couponRepository)
    {
        $this->couponService = $couponService;
        $this->couponRepository = $couponRepository;
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

        foreach ($policies as $policy) {
            // allowed_member_levels가 설정되어 있으면 현재 등급과 비교
            $allowedLevels = $policy->getAllowedMemberLevels();
            if (!empty($allowedLevels)) {
                $memberLevel = (int) $event->getMember()->getLevelValue();
                $levels = array_map('intval', explode(',', $allowedLevels));
                if (!in_array($memberLevel, $levels, true)) {
                    continue;
                }
            }

            $this->couponService->issueCoupon($policy->getCouponGroupId(), $memberId);
        }
    }
}
