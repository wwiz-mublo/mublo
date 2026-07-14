<?php
namespace Mublo\Service\Member\Event;

use Mublo\Entity\Member\Member;

/**
 * 관리자에 의한 회원 정보 수정 완료 이벤트
 *
 * 관리자가 회원 정보를 수정한 경우 발행
 *
 * 추가 데이터:
 * - adminId: 수정한 관리자 ID
 *
 * 활용 예시:
 * - 감사 로그: 어떤 관리자가 수정했는지 기록
 * - 회원에게 정보 변경 알림 발송
 */
class MemberUpdatedByAdminEvent extends MemberUpdatedEvent
{
    private int $adminId;

    /**
     * @param Member $member 수정된 회원 엔티티
     * @param array $changes 변경된 필드 목록
     * @param int $adminId 수정한 관리자의 회원 ID
     */
    public function __construct(Member $member, array $changes, int $adminId)
    {
        parent::__construct($member, $changes, self::SOURCE_ADMIN);
        $this->adminId = $adminId;
    }

    /**
     * 수정한 관리자 ID 반환
     */
    public function getAdminId(): int
    {
        return $this->adminId;
    }
}
