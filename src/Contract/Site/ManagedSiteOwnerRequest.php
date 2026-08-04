<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/** Input contract for selecting or creating the owner of a managed child site. */
final readonly class ManagedSiteOwnerRequest
{
    public function __construct(
        public int $operatorDomainId,
        public string $operatorDomainGroup,
        public int $actorMemberId,
        public bool $actorIsSuper,
        public int $actorLevelValue,
        public ?int $existingMemberId,
        public ?string $userId,
        public ?string $password,
        public ?string $nickname,
        public ?int $levelValue,
    ) {
    }
}
