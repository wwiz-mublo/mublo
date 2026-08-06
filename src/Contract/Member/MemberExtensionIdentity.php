<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

use JsonSerializable;

/**
 * 확장 이벤트에 전달하는 회원 식별 정보.
 *
 * 서버 내부에서는 정수 PK를 유지하되 직렬화 경계에는 공개 식별자와 표시명만 남긴다.
 */
final readonly class MemberExtensionIdentity implements JsonSerializable
{
    public function __construct(
        private int $memberId,
        private int $domainId,
        private string $publicId,
        private string $displayName,
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

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /** @return array{publicId: string, displayName: string} */
    public function jsonSerialize(): array
    {
        return [
            'publicId' => $this->publicId,
            'displayName' => $this->displayName,
        ];
    }
}
