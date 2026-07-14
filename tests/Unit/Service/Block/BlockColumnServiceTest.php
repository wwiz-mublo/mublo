<?php

namespace Tests\Unit\Service\Block;

use Mublo\Entity\Block\BlockRow;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockColumnService;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockSkinService;
use PHPUnit\Framework\TestCase;

class BlockColumnServiceTest extends TestCase
{
    public function testCreateColumnUsesSharedNormalizerForHtml(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('find')->willReturn($this->row());
        $repository->method('countByRowForDomain')->willReturn(0);
        $repository->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data): bool {
                $config = json_decode($data['content_config'], true);
                return is_array($config)
                    && str_contains($config['html'], 'safe')
                    && !str_contains(strtolower($config['html']), '<script')
                    && !str_contains(strtolower($config['html']), 'onclick');
            }))
            ->willReturn(20);

        $service = new BlockColumnService(
            $repository,
            $rowRepository,
            $this->normalizer()
        );

        $result = $service->createColumn(1, 10, [
            'content_type' => 'html',
            'content_config' => [
                'html' => '<p onclick="alert(1)">safe</p><script>alert(1)</script>',
            ],
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }

    public function testCreateColumnRejectsUnknownTypeBeforeRepositoryWrite(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('find')->willReturn($this->row());
        $repository->method('countByRowForDomain')->willReturn(0);
        $repository->expects($this->never())->method('create');

        $service = new BlockColumnService(
            $repository,
            $rowRepository,
            $this->normalizer()
        );

        $result = $service->createColumn(1, 10, [
            'content_type' => 'missing_extension',
            'content_kind' => 'PLUGIN',
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateColumnRejectsRowFromAnotherDomainBeforeCountingOrWriting(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('find')->willReturn($this->row(domainId: 2));
        $repository->expects($this->never())->method('countByRowForDomain');
        $repository->expects($this->never())->method('create');

        $service = new BlockColumnService($repository, $rowRepository, $this->normalizer());

        $result = $service->createColumn(1, 10, ['content_type' => 'html']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('행을 찾을 수 없습니다.', $result->getMessage());
    }

    public function testDomainScopedColumnLookupRejectsForeignRowWithoutReadingColumns(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('find')->willReturn($this->row(domainId: 2));
        $repository->expects($this->never())->method('findAllByRowForDomain');

        $service = new BlockColumnService($repository, $rowRepository, $this->normalizer());

        $this->assertNull($service->getColumnsByRowForDomain(10, 1));
    }

    public function testUpdateColumnRejectsColumnFromAnotherDomainBeforeWriting(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $repository->method('find')->willReturn(BlockColumn::fromArray([
            'column_id' => 20,
            'row_id' => 10,
            'domain_id' => 2,
            'column_index' => 0,
            'is_active' => 1,
        ]));
        $repository->expects($this->never())->method('update');

        $service = new BlockColumnService($repository, $rowRepository, $this->normalizer());

        $result = $service->updateColumn(20, 1, ['content_type' => 'html']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('칸을 찾을 수 없습니다.', $result->getMessage());
    }

    private function row(int $domainId = 1): BlockRow
    {
        return BlockRow::fromArray([
            'row_id' => 10,
            'domain_id' => $domainId,
            'position' => 'index',
            'column_count' => 1,
            'is_active' => 1,
        ]);
    }

    private function normalizer(): BlockColumnPayloadNormalizer
    {
        return new BlockColumnPayloadNormalizer(new BlockContentSanitizer(), new BlockSkinService());
    }
}
