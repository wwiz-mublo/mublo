<?php
declare(strict_types=1);
namespace Mublo\Contract\Identity;

/** 인증창 실행에 필요한 표준 준비 결과. */
final readonly class IdentityPrepareResult
{
    /** @param array<string, mixed> $providerData */
    public function __construct(
        public string $token,
        public string $actionUrl,
        public array $providerData = [],
    ) {
    }
}
