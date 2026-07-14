<?php
namespace Mublo\Contract\Identity;

/** 본인인증 공급자가 검증을 마친 표준 신원 스냅샷. */
final readonly class IdentityVerificationResult
{
    public function __construct(
        public string $name,
        public string $birth,
        public string $gender,
        public string $ci,
        public string $di,
        public ?string $phone = null,
    ) {
    }
}
