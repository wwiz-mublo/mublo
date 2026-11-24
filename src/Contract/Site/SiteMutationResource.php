<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

final readonly class SiteMutationResource
{
    public function __construct(
        public string $resourceId,
        public string $resourceType,
        public string $fingerprint
    ) {
    }
}
