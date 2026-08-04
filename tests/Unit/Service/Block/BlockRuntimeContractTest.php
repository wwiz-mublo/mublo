<?php

namespace Tests\Unit\Service\Block;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Contract\Block\BlockPreviewRendererInterface;
use Mublo\Contract\Block\BlockRenderContextInterface;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockContentCacheInvalidator;
use Mublo\Service\Block\BlockPreviewRenderer;
use Mublo\Service\Block\BlockRenderContext;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

final class BlockRuntimeContractTest extends TestCase
{
    public function testContentTypeInvalidationDeduplicatesRows(): void
    {
        $first = BlockColumn::fromArray(['column_id' => 1, 'row_id' => 7, 'domain_id' => 3]);
        $second = BlockColumn::fromArray(['column_id' => 2, 'row_id' => 7, 'domain_id' => 3]);
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->expects($this->once())
            ->method('findByContentType')
            ->with(3, 'faq')
            ->willReturn([$first, $second]);
        $renderer = $this->createMock(BlockRenderService::class);
        $renderer->expects($this->once())->method('invalidateRowCache')->with(7);

        $contract = new BlockContentCacheInvalidator($columns, $renderer);

        $this->assertInstanceOf(BlockContentCacheInvalidatorInterface::class, $contract);
        $contract->invalidateByContentType(3, 'faq');
    }

    public function testContentItemAndDomainInvalidationDelegateWithoutExposingRepositories(): void
    {
        $column = BlockColumn::fromArray(['column_id' => 1, 'row_id' => 9, 'domain_id' => 4]);
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->expects($this->once())
            ->method('findByContentItem')
            ->with(4, 'product', 55)
            ->willReturn([$column]);
        $renderer = $this->createMock(BlockRenderService::class);
        $renderer->expects($this->once())->method('invalidateRowCache')->with(9);
        $renderer->expects($this->once())->method('invalidateDomainCache')->with(4);

        $contract = new BlockContentCacheInvalidator($columns, $renderer);
        $contract->invalidateByContentItem(4, 'product', 55);
        $contract->invalidateDomain(4);
    }

    public function testPreviewRendererUsesTheProductionEntityRenderer(): void
    {
        $row = BlockRow::fromArray(['row_id' => 11, 'domain_id' => 4]);
        $column = BlockColumn::fromArray(['column_id' => 2, 'row_id' => 11, 'domain_id' => 4]);
        $renderer = $this->createMock(BlockRenderService::class);
        $renderer->expects($this->once())
            ->method('renderRowFromEntities')
            ->with($row, [$column])
            ->willReturn('<section>preview</section>');

        $contract = new BlockPreviewRenderer($renderer);

        $this->assertInstanceOf(BlockPreviewRendererInterface::class, $contract);
        $this->assertSame('<section>preview</section>', $contract->renderRow($row, [$column]));
    }

    public function testRenderContextSetsOnlyTheRequestVariant(): void
    {
        $renderer = $this->createMock(BlockRenderService::class);
        $renderer->expects($this->once())->method('setCacheVariant')->with('brand-a');

        $contract = new BlockRenderContext($renderer);

        $this->assertInstanceOf(BlockRenderContextInterface::class, $contract);
        $contract->setVariant('brand-a');
    }
}
