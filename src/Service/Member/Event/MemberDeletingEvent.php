<?php
declare(strict_types=1);
namespace Mublo\Service\Member\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Entity\Member\Member;

/**
 * 회원 삭제 진행 이벤트 (차단 가능)
 *
 * 발행 시점: 관리자 하드 삭제 검증 통과 후, DB 삭제 전.
 * 구독자는 setBlocked()로 삭제를 차단할 수 있다.
 */
class MemberDeletingEvent extends AbstractEvent implements FailFastEventInterface
{
    private bool $blocked = false;
    private ?string $blockReason = null;

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

    /** 삭제를 수행한 관리자 ID (미전달 시 0) */
    public function getAdminId(): int
    {
        return $this->adminId;
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
