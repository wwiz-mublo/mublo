<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 이벤트 payload에서 내부 Member Entity를 대신하는 불변 식별 정보. */
final readonly class MemberIdentity
{
    public function __construct(
        public int $memberId,
        public int $domainId,
        public string $userId,
        public string $displayName
    ) {
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }
}
