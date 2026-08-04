<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 신뢰 확장이 회원 계정을 만들 때 사용하는 명시적 입력 모델. */
final readonly class MemberRegistrationRequest
{
    public function __construct(
        public int $domainId,
        public string $userId,
        public string $passwordHash,
        public string $nickname,
        public int $levelValue = 1,
        public ?int $originDomainId = null,
        public ?string $domainGroup = null
    ) {
    }
}
