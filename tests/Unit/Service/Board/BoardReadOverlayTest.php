<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Board;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Service\Board\BoardReadOverlay;
use PHPUnit\Framework\TestCase;

final class BoardReadOverlayTest extends TestCase
{
    public function testEmptyOverlayIsDifferentFromNoOverlay(): void
    {
        $overlay = new BoardReadOverlay();
        $this->assertNull($overlay->boards(7));
        $this->assertTrue($overlay->replaceBoards(7, [])->isSuccess());
        $this->assertSame([], $overlay->boards(7));
        $overlay->clear();
        $this->assertNull($overlay->boards(7));
    }

    public function testNormalizesDraftBoardAndFiltersInactiveBoards(): void
    {
        $overlay = new BoardReadOverlay();
        $result = $overlay->replaceBoards(7, [
            $this->board(0, 'notice', true),
            $this->board(9, 'hidden', false),
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $draft = $overlay->findBySlug(7, 'notice');
        $this->assertNotNull($draft);
        $this->assertSame(-1, $draft->boardId);
        $this->assertSame(7, $draft->values['domain_id']);
        $this->assertSame(['notice'], array_column($overlay->boards(7, true), 'slug'));
        $this->assertSame($draft, $overlay->findById(-1));
    }

    public function testRejectsDuplicateSlugWithoutReplacingExistingBoards(): void
    {
        $overlay = new BoardReadOverlay();
        $this->assertTrue($overlay->replaceBoards(7, [$this->board(1, 'notice')])->isSuccess());
        $this->assertTrue($overlay->replaceBoards(7, [
            $this->board(2, 'same'),
            $this->board(3, 'same'),
        ])->isFailure());
        $this->assertSame(['notice'], array_column($overlay->boards(7), 'slug'));
    }

    private function board(int $id, string $slug, bool $active = true): BoardDescriptor
    {
        return new BoardDescriptor($id, $slug, ucfirst($slug), 3, $active, 0, ['sort_order' => $id], []);
    }
}
