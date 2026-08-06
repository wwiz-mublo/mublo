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
        public string $displayName,
        public string $publicId = '',
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

    /**
     * @deprecated since 1.1.0; removal planned for 2.0.0. 신규 코드는 getPublicId()를 사용한다.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }
}
