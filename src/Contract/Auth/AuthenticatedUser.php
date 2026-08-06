<?php
declare(strict_types=1);
namespace Mublo\Contract\Auth;

/**
 * 현재 요청에서 인증된 사용자의 안정적인 읽기 전용 표현.
 *
 * 세션 저장 구조나 Member Entity를 확장 API에 노출하지 않는다.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $memberId,
        public int $domainId,
        public string $userId,
        public ?string $nickname,
        public int $levelValue,
        public bool $admin,
        public bool $super,
        public bool $canOperateDomain,
        public ?string $avatar,
        public bool $canCreateSite = false,
        public ?string $name = null,
        public string $domainGroup = '',
        public string $levelType = '',
        public string $publicId = '',
    ) {
    }

    public function displayName(): string
    {
        $nickname = trim((string) $this->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        $name = trim((string) $this->name);
        return $name !== '' ? $name : $this->userId;
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
