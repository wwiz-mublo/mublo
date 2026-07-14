<?php
namespace Mublo\Service\Member\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Member\Member;

/**
 * 회원 삭제 완료 이벤트 (readonly)
 *
 * 발행 시점: 관리자 하드 삭제(DB row 제거) 후.
 * 용도: 플러그인 소유 데이터 정리(FK 없는 자체 테이블), 감사 로그.
 *
 * 주의: member 는 삭제 "이전" 스냅샷이다 — DB 에는 더 이상 존재하지 않으므로
 * 구독자는 member_id 재조회 대신 이 스냅샷을 사용해야 한다.
 */
class MemberDeletedEvent extends AbstractEvent
{
    public function __construct(
        private readonly Member $member,
        private readonly int $adminId = 0
    ) {
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

    public function getAdminId(): int
    {
        return $this->adminId;
    }
}
