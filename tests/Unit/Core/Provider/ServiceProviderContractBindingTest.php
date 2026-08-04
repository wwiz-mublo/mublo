<?php

namespace Tests\Unit\Core\Provider;

use Mublo\Contract\Block\BlockKitGatewayInterface;
use Mublo\Contract\Block\BlockPageQueryInterface;
use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Contract\Block\BlockPreviewRendererInterface;
use Mublo\Contract\Block\BlockRenderContextInterface;
use Mublo\Contract\Site\CompanyInfoInterface;
use Mublo\Contract\Site\DomainQueryInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Contract\CustomField\CustomFieldValueValidatorInterface;
use Mublo\Contract\CustomField\CustomFieldFileManagerInterface;
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
            BlockContentCacheInvalidatorInterface::class,
            BlockPreviewRendererInterface::class,
            BlockRenderContextInterface::class,
            CompanyInfoInterface::class,
            DomainQueryInterface::class,
            MenuManagementInterface::class,
            MemberAccountGatewayInterface::class,
            MemberLevelCatalogInterface::class,
            SensitiveValueCodecInterface::class,
            CustomFieldValueValidatorInterface::class,
            CustomFieldFileManagerInterface::class,
        ] as $contract) {
            $this->assertTrue($container->has($contract));
            $this->assertFalse($registry->has($contract));
        }
    }
}
