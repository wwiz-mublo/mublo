<?php
namespace Mublo\Service\Member\Event;

use Mublo\Entity\Member\Member;

/**
 * 사용자 직접 가입 완료 이벤트
 *
 * Front에서 회원가입 폼을 통해 직접 가입한 경우에만 발행
 *
 * 활용 예시:
 * - 가입 인증 메일 발송
 * - 신규 회원 환영 메시지
 */
class MemberRegisteredByUserEvent extends MemberRegisteredEvent
{
    public function __construct(Member $member)
    {
        parent::__construct($member, self::SOURCE_USER);
    }
}
