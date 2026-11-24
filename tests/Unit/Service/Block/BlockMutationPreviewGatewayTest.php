<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Block;

use Mublo\Service\Block\BlockColumnWriteContext;
use Mublo\Service\Block\BlockMutationPreviewGateway;
use Mublo\Service\Block\BlockPreviewService;
use PHPUnit\Framework\TestCase;

final class BlockMutationPreviewGatewayTest extends TestCase
{
    public function testNewRowPreviewAlwaysDeletesTemporaryToken(): void
    {
        $previews = $this->createMock(BlockPreviewService::class);
        $previews->expects($this->once())->method('createPreview')->with(
            ['admin_title' => '미리보기', 'domain_id' => 7],
            [],
            $this->callback(static fn (BlockColumnWriteContext $context): bool =>
                $context->domainId === 7 && $context->source === BlockColumnWriteContext::SOURCE_PREVIEW
            )
        )->willReturn('token');
        $previews->expects($this->once())->method('renderPreview')->with('token')->willReturn('<section/>');
        $previews->expects($this->once())->method('deletePreview')->with('token');

        $html = (new BlockMutationPreviewGateway($previews))->renderNewRow(
            7,
            ['admin_title' => '미리보기', 'domain_id' => 99, 'evil_column' => 'x'],
            []
        );

        $this->assertSame('<section/>', $html);
    }

    public function testEmptyColumnsMeansExplicitEmptyPreview(): void
    {
        $previews = $this->createMock(BlockPreviewService::class);
        $previews->expects($this->once())->method('renderExistingRowPreview')->with(
            10,
            7,
            ['admin_title' => '빈 행'],
            [],
            $this->isInstanceOf(BlockColumnWriteContext::class),
            true
        )->willReturn('<section/>');

        $html = (new BlockMutationPreviewGateway($previews))->renderExistingRow(
            7,
            10,
            ['admin_title' => '빈 행'],
            []
        );

        $this->assertSame('<section/>', $html);
    }
}
