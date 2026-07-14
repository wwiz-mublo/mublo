<?php
namespace Tests\Unit\Service\AI;

use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Repository\AI\AiAssetRepository;
use Mublo\Service\AI\CoreAiAssetCatalog;
use PHPUnit\Framework\TestCase;

class CoreAiAssetCatalogTest extends TestCase
{
    public function testReturnsDescriptorWithoutStoragePath(): void
    {
        $repository = $this->createMock(AiAssetRepository::class);
        $repository->method('listByDomain')->with(3, 10)->willReturn([$this->row()]);
        $catalog = new CoreAiAssetCatalog($repository, $this->createMock(SecureFileService::class));

        $assets = $catalog->list(3, 10);

        $this->assertCount(1, $assets);
        $this->assertSame(9, $assets[0]->getId());
        $this->assertSame('guide.pdf', $assets[0]->getOriginalName());
        $this->assertSame(['pages' => 2], $assets[0]->getMetadata());
    }

    public function testReadsOnlyBoundedExtractedText(): void
    {
        $repository = $this->createMock(AiAssetRepository::class);
        $repository->method('find')->willReturnCallback(
            fn (int $domainId, int $assetId): ?array => $domainId === 3 && $assetId === 9 ? $this->row() : null
        );
        $catalog = new CoreAiAssetCatalog($repository, $this->createMock(SecureFileService::class));

        $this->assertSame('문서 ', $catalog->readExtractedText(3, 9, 3));
        $this->assertNull($catalog->readExtractedText(4, 99));
    }

    private function row(): array
    {
        return [
            'asset_id' => 9, 'domain_id' => 3, 'parent_asset_id' => null,
            'kind' => 'document', 'title' => '가이드', 'original_name' => 'guide.pdf',
            'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 1200,
            'storage_path' => 'D3/ai-assets/file.pdf', 'extracted_text' => '문서 내용입니다.',
            'metadata_json' => '{"pages":2}', 'created_at' => '2026-07-13 12:00:00',
        ];
    }
}
