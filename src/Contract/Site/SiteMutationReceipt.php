<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

final readonly class SiteMutationReceipt
{
    /**
     * @param array<string, int> $resourceRevisions resource_id => revision
     */
    public function __construct(
        public int $eventId,
        public int $domainId,
        public int $siteRevision,
        public string $source,
        public ?string $correlationId,
        public array $resourceRevisions
    ) {
    }
}
