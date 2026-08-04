<?php

namespace Tests\Unit\Service\Menu;

use Mublo\Contract\Menu\MenuDescriptor;
use Mublo\Core\Result\Result;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Service\Menu\MenuManagementGateway;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

final class MenuManagementGatewayTest extends TestCase
{
    public function testMapsProviderRowsToStableDescriptors(): void
    {
        $service = $this->createStub(MenuService::class);
        $repository = $this->createMock(MenuItemRepository::class);
        $repository->expects($this->once())
            ->method('findByProvider')
            ->with(7, 'package', 'Shop')
            ->willReturn([[
                'item_id' => '12',
                'menu_code' => 'SHOP0001',
                'label' => '기획전',
                'url' => '/shop/exhibition/summer',
            ]]);

        $items = (new MenuManagementGateway($service, $repository))
            ->findProviderMenus(7, 'package', 'Shop');

        $this->assertCount(1, $items);
        $this->assertInstanceOf(MenuDescriptor::class, $items[0]);
        $this->assertSame(12, $items[0]->itemId);
        $this->assertSame('/shop/exhibition/summer', $items[0]->url);
    }

    public function testMutationsAlwaysPassDomainOwnershipScope(): void
    {
        $service = $this->createMock(MenuService::class);
        $repository = $this->createStub(MenuItemRepository::class);
        $updated = Result::success('updated');
        $removed = Result::success('removed');

        $service->expects($this->once())
            ->method('updateItem')
            ->with(42, ['label' => '변경'], 9)
            ->willReturn($updated);
        $service->expects($this->once())
            ->method('deleteItem')
            ->with(42, 9)
            ->willReturn($removed);

        $gateway = new MenuManagementGateway($service, $repository);
        $this->assertSame($updated, $gateway->updateMenu(9, 42, ['label' => '변경']));
        $this->assertSame($removed, $gateway->removeMenu(9, 42));
    }
}
