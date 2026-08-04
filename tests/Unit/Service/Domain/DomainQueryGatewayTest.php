<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Contract\Site\DomainDescriptor;
use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainQueryGateway;
use PHPUnit\Framework\TestCase;

final class DomainQueryGatewayTest extends TestCase
{
    public function testMapsDomainEntityToStableDescriptor(): void
    {
        $entity = new Domain(
            7,
            'shop.example.com',
            '1/7',
            31,
            'active',
            siteConfig: ['site_title' => '상점'],
            companyConfig: ['name' => '회사'],
            seoConfig: ['logo_pc' => '/logo.png']
        );
        $repository = $this->createMock(DomainRepository::class);
        $repository->expects($this->once())->method('find')->with(7)->willReturn($entity);

        $domain = (new DomainQueryGateway($repository))->find(7);

        $this->assertInstanceOf(DomainDescriptor::class, $domain);
        $this->assertSame('shop.example.com', $domain->hostname);
        $this->assertSame('1/7', $domain->domainGroup);
        $this->assertSame('상점', $domain->siteTitle);
        $this->assertSame(['name' => '회사'], $domain->companyConfig);
    }

    public function testActiveQueryIsBoundedAndMapped(): void
    {
        $entity = new Domain(2, 'two.example.com');
        $repository = $this->createMock(DomainRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['status' => 'active'], 1)
            ->willReturn([$entity]);

        $domains = (new DomainQueryGateway($repository))->findActive(0);

        $this->assertCount(1, $domains);
        $this->assertSame(2, $domains[0]->domainId);
    }
}
