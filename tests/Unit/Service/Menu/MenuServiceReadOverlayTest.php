<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Menu;

use Mublo\Contract\Menu\MenuTreeEntry;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuReadOverlay;
use Mublo\Service\Menu\MenuService;
use Mublo\Infrastructure\Code\CodeGenerator;
use PHPUnit\Framework\TestCase;

final class MenuServiceReadOverlayTest extends TestCase
{
    public function testTreeAndHierarchyUseOverlayWithoutReadingRepository(): void
    {
        $treeRepository = $this->createMock(MenuTreeRepository::class);
        $treeRepository->expects($this->never())->method('findTreeWithItems');
        $overlay = new MenuReadOverlay();
        $result = $overlay->replaceTree(7, [
            new MenuTreeEntry(1, 'root', 'root', '루트', null, 1, 0, '루트', '/'),
            new MenuTreeEntry(2, 'child', 'root>child', '루트 > 하위', 'root', 2, 0, '하위', '/child'),
        ]);
        $this->assertTrue($result->isSuccess(), $result->getMessage());

        $service = new MenuService(
            $this->createMock(MenuItemRepository::class),
            $treeRepository,
            $this->createMock(CodeGenerator::class),
            readOverlay: $overlay
        );

        $tree = $service->getTree(7);
        $hierarchy = $service->getTreeHierarchy(7);
        $this->assertSame(['root', 'child'], array_column($tree, 'menu_code'));
        $this->assertSame('child', $hierarchy[0]['children'][0]['menu_code']);
    }

    public function testNoOverlayKeepsRepositoryPath(): void
    {
        $expected = [['menu_code' => 'stored']];
        $treeRepository = $this->createMock(MenuTreeRepository::class);
        $treeRepository->expects($this->once())->method('findTreeWithItems')->with(7, true)->willReturn($expected);
        $service = new MenuService(
            $this->createMock(MenuItemRepository::class),
            $treeRepository,
            $this->createMock(CodeGenerator::class),
            readOverlay: new MenuReadOverlay()
        );

        $this->assertSame($expected, $service->getTree(7));
    }
}
