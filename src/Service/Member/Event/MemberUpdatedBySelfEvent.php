<?php
namespace Mublo\Service\Member\Event;

use Mublo\Entity\Member\Member;

/**
 * 본인에 의한 회원 정보 수정 완료 이벤트
 *
 * 회원이 자신의 정보를 직접 수정한 경우 발행
 *
 * 활용 예시:
 * - 비밀번호 변경 시 로그인 세션 갱신 안내
 * - 이메일 변경 시 인증 메일 발송
 */
class MemberUpdatedBySelfEvent extends MemberUpdatedEvent
{
    /**
     * @param Member $member 수정된 회원 엔티티
     * @param array $changes 변경된 필드 목록
     */
    public function __construct(Member $member, array $changes = [])
    {
        parent::__construct($member, $changes, self::SOURCE_SELF);
    }
}
