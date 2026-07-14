<?php
namespace Mublo\Service\Member\Event;

use Mublo\Entity\Member\Member;

/**
 * 관리자에 의한 회원 등록 완료 이벤트
 *
 * 관리자 페이지에서 회원을 직접 등록한 경우 발행
 *
 * 추가 데이터:
 * - adminId: 등록한 관리자 ID
 *
 * 활용 예시:
 * - 감사 로그: 어떤 관리자가 등록했는지 기록
 * - 관리자 등록 시에는 인증 메일 생략 등
 */
class MemberRegisteredByAdminEvent extends MemberRegisteredEvent
{
    private int $adminId;

    /**
     * @param Member $member 등록된 회원 엔티티
     * @param int $adminId 등록한 관리자의 회원 ID
     */
    public function __construct(Member $member, int $adminId)
    {
        parent::__construct($member, self::SOURCE_ADMIN);
        $this->adminId = $adminId;
    }

    /**
     * 등록한 관리자 ID 반환
     */
    public function getAdminId(): int
    {
        return $this->adminId;
    }
}
