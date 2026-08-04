<?php
declare(strict_types=1);

namespace Mublo\Service\Domain;

use Mublo\Contract\Site\DomainDescriptor;
use Mublo\Contract\Site\DomainQueryInterface;
use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Domain\DomainRepository;

final class DomainQueryGateway implements DomainQueryInterface
{
    public function __construct(private DomainRepository $domains)
    {
    }

    public function find(int $domainId): ?DomainDescriptor
    {
        return $this->describe($this->domains->find($domainId));
    }

    public function findByHostname(string $hostname): ?DomainDescriptor
    {
        return $this->describe($this->domains->findByDomain($hostname));
    }

    public function findActive(int $limit = 500): array
    {
        $limit = max(1, $limit);

        return array_map(
            fn (Domain $domain): DomainDescriptor => $this->describeDomain($domain),
            $this->domains->findBy(['status' => 'active'], $limit)
        );
    }

    private function describe(?object $domain): ?DomainDescriptor
    {
        return $domain instanceof Domain ? $this->describeDomain($domain) : null;
    }

    private function describeDomain(Domain $domain): DomainDescriptor
    {
        return new DomainDescriptor(
            $domain->getDomainId(),
            $domain->getDomain(),
            $domain->getDomainGroup(),
            $domain->getMemberId(),
            $domain->getStatus(),
            $domain->getSiteTitle(),
            $domain->getCompanyConfig(),
            $domain->getSeoConfig()
        );
    }
}
