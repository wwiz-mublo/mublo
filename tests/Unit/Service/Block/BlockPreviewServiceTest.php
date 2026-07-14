<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Session\SessionInterface;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockPreviewService;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockSkinService;
use PHPUnit\Framework\TestCase;

class BlockPreviewServiceTest extends TestCase
{
    public function testCreatePreviewStoresNormalizedColumns(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $stored = null;
        $session->method('get')->willReturn([]);
        $session->expects($this->once())
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$stored): void {
                $stored = $value;
            });

        $service = $this->makeService($session);
        $token = $service->createPreview(['domain_id' => 1], [[
            'content_type' => 'html',
            'content_config' => [
                'html' => '<p onmouseover="alert(1)">safe</p><script>alert(1)</script>',
            ],
        ]]);

        $this->assertNotNull($token);
        $html = $stored[$token]['columns'][0]['content_config']['html'];
        $this->assertStringContainsString('safe', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onmouseover', $html);
    }

    public function testCreatePreviewRejectsInvalidColumnWithoutWritingSession(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->never())->method('set');

        $service = $this->makeService($session);
        $token = $service->createPreview(['domain_id' => 1], [[
            'content_type' => 'missing_extension',
            'content_kind' => 'PLUGIN',
        ]]);

        $this->assertNull($token);
    }

    public function testExistingRowPreviewRejectsRowFromAnotherDomainBeforeReadingColumnsOrRendering(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $renderService = $this->createMock(BlockRenderService::class);
        $rowRepository->method('find')->with(10)->willReturn(BlockRow::fromArray([
            'row_id' => 10,
            'domain_id' => 2,
            'position' => 'index',
            'column_count' => 1,
            'is_active' => 1,
        ]));
        $columnRepository->expects($this->never())->method('findByRowForDomain');
        $renderService->expects($this->never())->method('renderRowFromEntities');

        $service = new BlockPreviewService(
            $rowRepository,
            $columnRepository,
            $renderService,
            $session,
            new BlockColumnPayloadNormalizer(new BlockContentSanitizer(), new BlockSkinService())
        );

        $this->assertNull($service->renderExistingRowPreview(10, 1, [], []));
    }

    public function testExistingRowPreviewCannotOverrideStoredDomain(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $renderService = $this->createMock(BlockRenderService::class);
        $rowRepository->method('find')->willReturn(BlockRow::fromArray([
            'row_id' => 10,
            'domain_id' => 1,
            'position' => 'index',
            'column_count' => 1,
            'is_active' => 1,
        ]));
        $columnRepository->method('findByRowForDomain')->willReturn([]);
        $renderService->expects($this->once())
            ->method('renderRowFromEntities')
            ->with($this->callback(fn (BlockRow $row): bool => $row->getDomainId() === 1))
            ->willReturn('<div></div>');

        $service = new BlockPreviewService(
            $rowRepository,
            $columnRepository,
            $renderService,
            $session,
            new BlockColumnPayloadNormalizer(new BlockContentSanitizer(), new BlockSkinService())
        );

        $this->assertSame('<div></div>', $service->renderExistingRowPreview(
            10,
            1,
            ['domain_id' => 2, 'row_id' => 999],
            []
        ));
    }

    private function makeService(SessionInterface $session): BlockPreviewService
    {
        return new BlockPreviewService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $this->createMock(BlockRenderService::class),
            $session,
            new BlockColumnPayloadNormalizer(new BlockContentSanitizer(), new BlockSkinService())
        );
    }
}
