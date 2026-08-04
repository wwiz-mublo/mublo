<?php

namespace Tests\Unit\Service\Block;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Entity\Block\BlockRow;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Repository\Block\BlockRowRevisionRepository;
use Mublo\Service\Block\BlockColumnContentService;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Service\Block\BlockSkinService;
use Mublo\Service\Block\MainScreenComposition;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\System\InstallIdProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BlockKitSnapshotStackTest extends TestCase
{
    public function testReplaceSnapshotIncludesStackContents(): void
    {
        $row = BlockRow::fromArray([
            'row_id' => 7,
            'domain_id' => 1,
            'position' => 'index',
            'revision_no' => 3,
        ]);
        $column = BlockColumn::fromArray([
            'column_id' => 11,
            'row_id' => 7,
            'domain_id' => 1,
            'content_mode' => 'stack',
        ]);
        $content = BlockColumnContent::fromArray([
            'content_id' => 21,
            'column_id' => 11,
            'domain_id' => 1,
            'content_type' => 'html',
            'content_config' => ['html' => '<p>revision child</p>'],
            'is_active' => 0,
        ]);

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->with(7, 1)->willReturn([$column]);
        $contentRepository = $this->createMock(BlockColumnContentRepository::class);
        $contentRepository->method('findByColumnsForDomain')->with([11], 1, true)->willReturn([11 => [$content]]);
        $columnWriter = new BlockColumnContentService($columnRepository, $contentRepository);

        $snapshotJson = null;
        $query = $this->createMock(QueryBuilder::class);
        $query->method('insert')->willReturnCallback(
            static function (array $record) use (&$snapshotJson): int {
                $snapshotJson = $record['snapshot_json'] ?? null;
                return 1;
            }
        );
        $query->method('select')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('orderBy')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('block_row_revisions')->willReturn($query);
        $revisionRepository = new BlockRowRevisionRepository($db);

        $sanitizer = new BlockContentSanitizer();
        $applier = new BlockKitApplier(
            $sanitizer,
            $this->createMock(BlockRowRepository::class),
            $columnRepository,
            $this->createMock(BlockPageRepository::class),
            $this->createMock(BlockRenderService::class),
            $this->createMock(DomainSettingsService::class),
            $this->createMock(InstallIdProvider::class),
            new ExtensionCompatibility(),
            new BlockColumnPayloadNormalizer($sanitizer, new BlockSkinService()),
            new MainScreenComposition(),
            null,
            $revisionRepository,
            null,
            $columnWriter
        );

        (new ReflectionMethod(BlockKitApplier::class, 'snapshotRowsForReplace'))->invoke($applier, [$row]);

        $this->assertIsString($snapshotJson);
        $snapshot = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(21, $snapshot['columns'][0]['contents'][0]['content_id']);
        $this->assertFalse($snapshot['columns'][0]['contents'][0]['is_active']);
    }
}
