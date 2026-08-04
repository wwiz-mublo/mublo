<?php
declare(strict_types=1);
namespace Mublo\Service\Member\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Entity\Member\Member;

/**
 * 회원 탈퇴 진행 이벤트 (차단 가능)
 *
 * 발행 시점: 본인 탈퇴(withdraw) 검증 통과 후, DB 반영 전.
 * 구독자는 setBlocked()로 탈퇴를 차단할 수 있다
 * (예: 미정산 포인트/진행 중 주문이 있는 회원의 탈퇴 보류).
 */
class MemberWithdrawingEvent extends AbstractEvent implements FailFastEventInterface
{
    private bool $blocked = false;
    private ?string $blockReason = null;

    public function __construct(
        private readonly Member $member,
        private readonly string $reason = ''
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

    /** 탈퇴 사유 (회원 입력) */
    public function getReason(): string
    {
        return $this->reason;
    }

    public function setBlocked(bool $blocked, ?string $reason = null): void
    {
        $this->blocked = $blocked;
        if ($reason !== null) {
            $this->blockReason = $reason;
        }
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }
}
