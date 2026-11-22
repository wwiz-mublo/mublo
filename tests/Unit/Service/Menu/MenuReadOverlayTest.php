<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Menu;

use Mublo\Contract\Menu\MenuTreeEntry;
use Mublo\Service\Menu\MenuReadOverlay;
use PHPUnit\Framework\TestCase;

final class MenuReadOverlayTest extends TestCase
{
    public function testEmptyOverlayIsDifferentFromNoOverlay(): void
    {
        $overlay = new MenuReadOverlay();

        $this->assertNull($overlay->tree(7));
        $this->assertTrue($overlay->replaceTree(7, [])->isSuccess());
        $this->assertSame([], $overlay->tree(7));

        $overlay->clear();
        $this->assertNull($overlay->tree(7));
    }

    public function testNormalizesDraftIdsSortsAndFiltersInactiveEntries(): void
    {
        $overlay = new MenuReadOverlay();
        $result = $overlay->replaceTree(7, [
            $this->entry(0, 'child', 'root>child', '루트 > 하위', 'root', 2, 0, false),
            $this->entry(0, 'root', 'root', '루트', null, 1, 0),
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $all = $overlay->tree(7, false);
        $this->assertNotNull($all);
        $this->assertSame(['root', 'child'], array_column($all, 'menuCode'));
        $this->assertSame(-2, $all[0]->nodeId);
        $this->assertSame(-1, $all[1]->nodeId);
        $this->assertSame(['root'], array_column($overlay->tree(7), 'menuCode'));
    }

    public function testRejectsBrokenHierarchyWithoutReplacingExistingTree(): void
    {
        $overlay = new MenuReadOverlay();
        $root = $this->entry(1, 'root', 'root', '루트', null, 1, 0);
        $this->assertTrue($overlay->replaceTree(7, [$root])->isSuccess());

        $broken = $this->entry(2, 'child', 'missing>child', '누락 > 하위', 'missing', 2, 0);
        $this->assertTrue($overlay->replaceTree(7, [$broken])->isFailure());
        $remaining = $overlay->tree(7);
        $this->assertNotNull($remaining);
        $this->assertSame(['root'], array_column($remaining, 'menuCode'));
    }

    private function entry(
        int $nodeId,
        string $menuCode,
        string $pathCode,
        string $pathName,
        ?string $parentCode,
        int $depth,
        int $sortOrder,
        bool $active = true
    ): MenuTreeEntry {
        return new MenuTreeEntry(
            $nodeId,
            $menuCode,
            $pathCode,
            $pathName,
            $parentCode,
            $depth,
            $sortOrder,
            $menuCode === 'root' ? '루트' : '하위',
            '/' . $menuCode,
            isActive: $active
        );
    }
}
