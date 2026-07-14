<?php
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
    ) {
    }
}
