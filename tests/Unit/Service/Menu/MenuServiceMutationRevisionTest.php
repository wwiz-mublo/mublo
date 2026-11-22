<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Menu;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

final class MenuServiceMutationRevisionTest extends TestCase
{
    private function db(): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $db->method('afterCommit')->willReturnCallback(static function (callable $callback): void {
            $callback();
        });
        return $db;
    }

    private function receipt(): SiteMutationReceipt
    {
        return new SiteMutationReceipt(1, 1, 1, SiteMutationContext::SOURCE_ADMIN, null, []);
    }

    public function testCreateItemRecordsOnlyItsMenuResource(): void
    {
        $db = $this->db();
        $items = $this->createMock(MenuItemRepository::class);
        $items->method('getDb')->willReturn($db);
        $items->method('create')->willReturn(7);
        $items->method('findByMenuCode')->with(1, 'menu0001')->willReturn([
            'item_id' => 7,
            'domain_id' => 1,
            'menu_code' => 'menu0001',
            'label' => '새 메뉴',
        ]);
        $codes = $this->createMock(CodeGenerator::class);
        $codes->method('generate')->willReturn('menu0001');
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['menu:menu0001'])
        )->willReturn($this->receipt());

        $service = new MenuService(
            $items,
            $this->createMock(MenuTreeRepository::class),
            $codes,
            null,
            null,
            null,
            $recorder
        );

        $this->assertTrue($service->createItem(1, ['label' => '새 메뉴'])->isSuccess());
    }

    public function testRootPlacementRecordsOnlyRootChildrenScope(): void
    {
        $db = $this->db();
        $items = $this->createMock(MenuItemRepository::class);
        $items->method('findByMenuCode')->willReturn(['menu_code' => 'home', 'label' => '홈']);
        $trees = $this->createMock(MenuTreeRepository::class);
        $trees->method('getDb')->willReturn($db);
        $trees->method('getMaxSortOrder')->willReturn(0);
        $trees->method('create')->willReturn(9);
        $trees->method('findRootNodes')->willReturn([[
            'path_code' => 'home',
            'menu_code' => 'home',
            'sort_order' => 1,
        ]]);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['menu_children:root'])
        )->willReturn($this->receipt());

        $service = new MenuService(
            $items,
            $trees,
            $this->createMock(CodeGenerator::class),
            null,
            null,
            null,
            $recorder
        );

        $this->assertTrue($service->addToTree(1, 'home')->isSuccess());
    }

    public function testSharedContextCollectsParentScopeWithoutFlushing(): void
    {
        $db = $this->db();
        $items = $this->createMock(MenuItemRepository::class);
        $items->method('findByMenuCode')->willReturn(['menu_code' => 'faq', 'label' => 'FAQ']);
        $trees = $this->createMock(MenuTreeRepository::class);
        $trees->method('getDb')->willReturn($db);
        $trees->method('findByPathCode')->with(1, 'support')->willReturn([
            'path_code' => 'support',
            'path_name' => '고객센터',
            'depth' => 1,
        ]);
        $trees->method('getMaxSortOrder')->willReturn(0);
        $trees->method('create')->willReturn(10);
        $trees->method('findChildren')->with(1, 'support')->willReturn([[
            'path_code' => 'support>faq',
            'menu_code' => 'faq',
            'sort_order' => 1,
        ]]);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->never())->method('record');
        $context = SiteMutationContext::create(1, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');

        $service = new MenuService(
            $items,
            $trees,
            $this->createMock(CodeGenerator::class),
            null,
            null,
            null,
            $recorder
        );

        $this->assertTrue($service->addToTree(1, 'faq', 'support', $context)->isSuccess());
        $this->assertSame(
            ['menu_children:support'],
            array_column($context->resources(), 'resourceId')
        );
    }

    public function testSaveTreeAdvancesOnlyChangedParentCollection(): void
    {
        $db = $this->db();
        $itemRows = [
            ['menu_code' => 'support', 'label' => '고객센터'],
            ['menu_code' => 'about', 'label' => '소개'],
            ['menu_code' => 'faq', 'label' => 'FAQ'],
            ['menu_code' => 'contact', 'label' => '문의'],
        ];
        $nodes = [
            ['node_id' => 1, 'domain_id' => 1, 'menu_code' => 'support', 'path_code' => 'support',
                'path_name' => '고객센터', 'parent_code' => null, 'depth' => 1, 'sort_order' => 1],
            ['node_id' => 2, 'domain_id' => 1, 'menu_code' => 'about', 'path_code' => 'about',
                'path_name' => '소개', 'parent_code' => null, 'depth' => 1, 'sort_order' => 2],
            ['node_id' => 3, 'domain_id' => 1, 'menu_code' => 'faq', 'path_code' => 'support>faq',
                'path_name' => '고객센터>FAQ', 'parent_code' => 'support', 'depth' => 2, 'sort_order' => 1],
        ];

        $items = $this->createMock(MenuItemRepository::class);
        $items->method('findByDomain')->willReturn($itemRows);
        $trees = $this->createMock(MenuTreeRepository::class);
        $trees->method('getDb')->willReturn($db);
        $trees->method('findByDomain')->willReturnCallback(static function () use (&$nodes): array {
            return $nodes;
        });
        $trees->method('findRootNodes')->willReturnCallback(static function () use (&$nodes): array {
            return array_values(array_filter(
                $nodes,
                static fn (array $row): bool => $row['parent_code'] === null
            ));
        });
        $trees->method('findChildren')->willReturnCallback(
            static function (int $domainId, string $parent) use (&$nodes): array {
                return array_values(array_filter(
                    $nodes,
                    static fn (array $row): bool => $row['parent_code'] === $parent
                ));
            }
        );
        $trees->method('deleteByDomain')->willReturnCallback(static function () use (&$nodes): int {
            $count = count($nodes);
            $nodes = [];
            return $count;
        });
        $trees->method('create')->willReturnCallback(static function (array $data) use (&$nodes): int {
            $data['node_id'] = count($nodes) + 1;
            $nodes[] = $data;
            return $data['node_id'];
        });
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['menu_children:support'])
        )->willReturn($this->receipt());

        $service = new MenuService(
            $items,
            $trees,
            $this->createMock(CodeGenerator::class),
            null,
            null,
            null,
            $recorder
        );
        $result = $service->saveTree(1, [
            ['menu_code' => 'support', 'children' => [
                ['menu_code' => 'faq'],
                ['menu_code' => 'contact'],
            ]],
            ['menu_code' => 'about'],
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }
}
