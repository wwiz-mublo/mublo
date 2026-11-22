<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Menu;

use Mublo\Contract\Menu\MenuPlacementDescriptor;
use Mublo\Core\Result\Result;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Menu\MenuTreeManagementGateway;
use PHPUnit\Framework\TestCase;

final class MenuTreeManagementGatewayTest extends TestCase
{
    public function testMapsInactiveAndActivePlacementsToStableDescriptors(): void
    {
        $service = $this->createStub(MenuService::class);
        $trees = $this->createMock(MenuTreeRepository::class);
        $trees->expects($this->once())->method('findTreeWithItems')->with(7, false)->willReturn([[
            'node_id' => '12',
            'menu_code' => 'faq',
            'path_code' => 'support>faq',
            'parent_code' => 'support',
            'depth' => '2',
            'sort_order' => '3',
            'label' => 'FAQ',
            'url' => '/faq',
        ]]);

        $placements = (new MenuTreeManagementGateway($service, $trees))->listPlacements(7);

        $this->assertCount(1, $placements);
        $this->assertInstanceOf(MenuPlacementDescriptor::class, $placements[0]);
        $this->assertSame(12, $placements[0]->placementId);
        $this->assertSame('support', $placements[0]->parentCode);
    }

    public function testDelegatesScopedMutationsWithoutChangingContext(): void
    {
        $service = $this->createMock(MenuService::class);
        $trees = $this->createStub(MenuTreeRepository::class);
        $moved = Result::success('moved');
        $ordered = Result::success('ordered');
        $removed = Result::success('removed');
        $service->expects($this->once())->method('moveTreeNode')->with(4, 10, 20, 19, null)->willReturn($moved);
        $service->expects($this->once())->method('reorderTreeChildren')->with(4, 20, [19, 10], null)->willReturn($ordered);
        $service->expects($this->once())->method('removeFromTreeInDomain')->with(4, 10, null)->willReturn($removed);

        $gateway = new MenuTreeManagementGateway($service, $trees);

        $this->assertSame($moved, $gateway->movePlacement(4, 10, 20, 19));
        $this->assertSame($ordered, $gateway->reorderChildren(4, 20, [19, 10]));
        $this->assertSame($removed, $gateway->removePlacement(4, 10));
    }
}
