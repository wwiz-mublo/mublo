<?php
declare(strict_types=1);
namespace Mublo\Service\Member\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Member\Member;

/**
 * 회원 탈퇴 완료 이벤트 (readonly)
 *
 * 발행 시점: 소프트 삭제(status=withdrawn + 개인정보 정리) 커밋 후.
 * 이미 완료된 사실 통지 — 되돌릴 수 없다.
 * 용도: 플러그인 소유 데이터 정리, 탈퇴 알림, 통계.
 *
 * 주의: member 는 탈퇴 "이전" 스냅샷이다 (추가필드 개인정보는 이미 삭제됨).
 */
class MemberWithdrawnEvent extends AbstractEvent
{
    public function __construct(
        private readonly Member $member,
        private readonly string $reason = ''
    ) {
    }


    public function getMemberId(): int
    {
        return $this->member->getMemberId();
    }

    public function getDomainId(): int
    {
        return $this->member->getDomainId();
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
