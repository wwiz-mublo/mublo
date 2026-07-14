<?php

namespace Tests\Unit\Core\Provider;

use Mublo\Contract\Block\BlockKitGatewayInterface;
use Mublo\Contract\Block\BlockPageQueryInterface;
use Mublo\Contract\Site\CompanyInfoInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Provider\ServiceProvider;
use Mublo\Core\Registry\ContractRegistry;
use PHPUnit\Framework\TestCase;

class ServiceProviderContractBindingTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testMandatoryCoreContractsAreDiBindingsNotRegistryEntries(): void
    {
        $container = DependencyContainer::getInstance();
        (new ServiceProvider())->register($container);

        $registry = $container->get(ContractRegistry::class);
        foreach ([
            BlockKitGatewayInterface::class,
            BlockPageQueryInterface::class,
            CompanyInfoInterface::class,
        ] as $contract) {
            $this->assertTrue($container->has($contract));
            $this->assertFalse($registry->has($contract));
        }
    }
}
