<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/** Stable, read-only representation of a Domain managed by a parent operator. */
final readonly class ManagedSite
{
    public function __construct(
        public int $domainId,
        public string $domain,
        public string $domainGroup,
        public ?int $ownerMemberId,
        public string $status,
    ) {
    }
}
