<?php
declare(strict_types=1);
namespace Mublo\Contract\Member;

/**
 * 확장에 노출하는 읽기 전용 회원 프로필.
 */
final readonly class MemberProfile
{
    public function __construct(
        public int $memberId,
        public int $domainId,
        public string $userId,
        public ?string $nickname,
        public int $levelValue,
        public bool $active,
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $email = null,
        public string $domainGroup = '',
        public bool $admin = false,
        public ?string $levelType = null,
        public string $publicId = '',
    ) {
    }

    public function displayName(): string
    {
        return $this->nickname ?: ($this->name ?: $this->userId);
    }

    public function publicDisplayName(): string
    {
        $nickname = trim((string) $this->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        return $this->publicId !== '' ? '회원 ' . substr($this->publicId, 0, 12) : '회원';
    }
}
