<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Block;

use Mublo\Contract\Block\BlockRowSnapshot;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockReadOverlay;
use Mublo\Service\Block\BlockSkinService;
use PHPUnit\Framework\TestCase;

final class BlockReadOverlayTest extends TestCase
{
    public function testEmptyOverlayIsDifferentFromNoOverlay(): void
    {
        $overlay = $this->overlay();

        $this->assertNull($overlay->positionRows(7, 'index', null, true));
        $this->assertTrue($overlay->replacePositionRows(7, 'index', null, true, [])->isSuccess());
        $this->assertSame([], $overlay->positionRows(7, 'index', null, true));

        $overlay->clear();
        $this->assertNull($overlay->positionRows(7, 'index', null, true));
    }

    public function testNormalizesPlacementAndAssignsStableDraftIds(): void
    {
        $overlay = $this->overlay();
        $draft = new BlockRowSnapshot(
            0,
            7,
            0,
            ['admin_title' => '새 블록'],
            [[
                'content_mode' => 'single',
                'is_active' => 1,
            ]]
        );

        $result = $overlay->replacePageRows(7, 30, [$draft]);
        $rows = $overlay->pageRows(30);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertNotNull($rows);
        $this->assertSame(-1, $rows[0]->rowId);
        $this->assertSame(30, $rows[0]->row['page_id']);
        $this->assertNull($rows[0]->row['position']);
        $this->assertSame(0, $rows[0]->row['sort_order']);
    }

    public function testRejectsSnapshotFromAnotherDomain(): void
    {
        $overlay = $this->overlay();
        $snapshot = new BlockRowSnapshot(1, 8, 1, [], []);

        $this->assertTrue($overlay->replacePageRows(7, 30, [$snapshot])->isFailure());
        $this->assertNull($overlay->pageRows(30));
    }

    private function overlay(): BlockReadOverlay
    {
        return new BlockReadOverlay(new BlockColumnPayloadNormalizer(
            new BlockContentSanitizer(),
            new BlockSkinService()
        ));
    }
}
