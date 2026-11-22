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

final class MenuServiceTargetedTreeMutationTest extends TestCase
{
    public function testMovesSubtreeWithoutReplacingPlacementIds(): void
    {
        $rows = [
            1 => $this->row(1, 'support', 'support', '고객센터', null, 1, 1),
            2 => $this->row(2, 'faq', 'faq', 'FAQ', null, 1, 2),
            3 => $this->row(3, 'detail', 'faq>detail', 'FAQ>상세', 'faq', 2, 1),
        ];
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $trees = $this->createMock(MenuTreeRepository::class);
        $trees->method('getDb')->willReturn($db);
        $trees->method('findByIdAndDomain')->willReturnCallback(
            static fn (int $nodeId, int $domainId): ?array => $domainId === 1 ? ($rows[$nodeId] ?? null) : null
        );
        $trees->method('findByDomain')->willReturnCallback(static fn (): array => array_values($rows));
        $trees->method('findRootNodes')->willReturnCallback(
            static fn (): array => array_values(array_filter($rows, static fn (array $row): bool => $row['parent_code'] === null))
        );
        $trees->method('findChildren')->willReturnCallback(
            static fn (int $domainId, string $parent): array => array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['parent_code'] === $parent
            ))
        );
        $trees->method('update')->willReturnCallback(static function (int $nodeId, array $data) use (&$rows): int {
            $rows[$nodeId] = array_replace($rows[$nodeId], $data);
            return 1;
        });
        $trees->method('updateSortOrder')->willReturnCallback(
            static function (int $nodeId, int $domainId, int $sortOrder) use (&$rows): int {
                $rows[$nodeId]['sort_order'] = $sortOrder;
                return 1;
            }
        );
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static function (SiteMutationContext $context): bool {
                $resources = array_column($context->resources(), 'resourceId');
                return in_array('menu_children:root', $resources, true)
                    && in_array('menu_children:support', $resources, true)
                    && in_array('menu_children:faq', $resources, true)
                    && in_array('menu_children:support>faq', $resources, true);
            })
        )->willReturn(new SiteMutationReceipt(1, 1, 1, SiteMutationContext::SOURCE_ADMIN, null, []));

        $service = new MenuService(
            $this->createStub(MenuItemRepository::class),
            $trees,
            $this->createStub(CodeGenerator::class),
            null,
            null,
            null,
            $recorder
        );
        $result = $service->moveTreeNode(1, 2, 1);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame('support>faq', $rows[2]['path_code']);
        $this->assertSame('support', $rows[2]['parent_code']);
        $this->assertSame(2, $rows[2]['depth']);
        $this->assertSame('support>faq>detail', $rows[3]['path_code']);
        $this->assertSame('support>faq', $rows[3]['parent_code']);
        $this->assertSame(3, $rows[3]['depth']);
        $this->assertSame([1, 2, 3], array_keys($rows));
    }

    public function testRejectsPartialSiblingReorder(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('transaction');
        $trees = $this->createMock(MenuTreeRepository::class);
        $trees->method('getDb')->willReturn($db);
        $trees->method('findRootNodes')->willReturn([
            $this->row(1, 'home', 'home', '홈', null, 1, 1),
            $this->row(2, 'shop', 'shop', '쇼핑', null, 1, 2),
        ]);
        $service = new MenuService(
            $this->createStub(MenuItemRepository::class),
            $trees,
            $this->createStub(CodeGenerator::class)
        );

        $result = $service->reorderTreeChildren(1, null, [2]);

        $this->assertTrue($result->isFailure());
    }

    /** @return array<string, int|string|null> */
    private function row(
        int $nodeId,
        string $menuCode,
        string $pathCode,
        string $pathName,
        ?string $parentCode,
        int $depth,
        int $sortOrder
    ): array {
        return [
            'node_id' => $nodeId,
            'domain_id' => 1,
            'menu_code' => $menuCode,
            'path_code' => $pathCode,
            'path_name' => $pathName,
            'parent_code' => $parentCode,
            'depth' => $depth,
            'sort_order' => $sortOrder,
        ];
    }
}
