<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

interface DomainQueryInterface
{
    public function find(int $domainId): ?DomainDescriptor;

    public function findByHostname(string $hostname): ?DomainDescriptor;

    /** @return list<DomainDescriptor> */
    public function findActive(int $limit = 500): array;
}
