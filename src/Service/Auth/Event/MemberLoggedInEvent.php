<?php
namespace Mublo\Service\Auth\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Member\Member;

/**
 * 회원 로그인 완료 이벤트
 *
 * 로그인 성공 시 발행 (attempt, loginByMember 모두 포함)
 *
 * 활용 예시:
 * - 쿠폰 자동 발행 (LOGIN 트리거)
 * - 로그인 기록 로깅
 */
class MemberLoggedInEvent extends AbstractEvent
{
    private Member $member;

    public function __construct(Member $member)
    {
        $this->member = $member;
    }

    public function getMember(): Member
    {
        return $this->member;
    }

    public function getMemberId(): int
    {
        return $this->member->getMemberId();
    }

    public function getDomainId(): int
    {
        return $this->member->getDomainId();
    }
}
