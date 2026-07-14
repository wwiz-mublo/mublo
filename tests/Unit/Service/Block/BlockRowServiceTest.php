<?php

namespace Tests\Unit\Service\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Entity\Block\BlockPage;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockSkinService;
use Mublo\Service\Block\BlockRowService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BlockRowServiceTest extends TestCase
{
    public function testCreateRowRejectsUnknownColumnTypeBeforeWriting(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $pageRepository = $this->createMock(BlockPageRepository::class);
        $db = $this->createMock(Database::class);
        $renderService = $this->createMock(BlockRenderService::class);

        $rowRepository->expects($this->never())->method('create');
        $columnRepository->expects($this->never())->method('replaceByRow');
        $db->expects($this->never())->method('beginTransaction');

        $service = new BlockRowService(
            $rowRepository,
            $columnRepository,
            $pageRepository,
            $db,
            $renderService,
            $this->normalizer()
        );

        $result = $service->createRow(1, ['position' => 'index'], [[
            'content_type' => 'missing_extension_block',
            'content_kind' => 'PLUGIN',
        ]]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('등록되지 않은 콘텐츠 타입', $result->getMessage());
    }

    public function testCreateRowSanitizesHtmlBeforeReplacingColumns(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $pageRepository = $this->createMock(BlockPageRepository::class);
        $db = $this->createMock(Database::class);
        $renderService = $this->createMock(BlockRenderService::class);

        $rowRepository->method('getNextSortOrderByPosition')->willReturn(0);
        $rowRepository->method('create')->willReturn(10);
        $rowRepository->method('find')->willReturn(null);
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->once())->method('commit')->willReturn(true);
        $db->expects($this->never())->method('rollBack');
        $columnRepository->expects($this->once())
            ->method('replaceByRow')
            ->with(10, 1, $this->callback(function (array $columns): bool {
                $html = $columns[0]['content_config']['html'];
                return str_contains($html, 'safe')
                    && !str_contains(strtolower($html), '<script')
                    && !str_contains(strtolower($html), 'onclick');
            }))
            ->willReturn(true);

        $service = new BlockRowService(
            $rowRepository,
            $columnRepository,
            $pageRepository,
            $db,
            $renderService,
            $this->normalizer()
        );

        $result = $service->createRow(1, ['position' => 'index'], [[
            'content_type' => 'html',
            'content_kind' => 'CORE',
            'content_config' => [
                'html' => '<p onclick="alert(1)">safe</p><script>alert(1)</script>',
            ],
        ]]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }

    public function testCreateRowDoesNotStartTransactionWhenAnyColumnIsInvalid(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $pageRepository = $this->createMock(BlockPageRepository::class);
        $db = $this->createMock(Database::class);

        $rowRepository->expects($this->never())->method('create');
        $columnRepository->expects($this->never())->method('replaceByRow');
        $db->expects($this->never())->method('beginTransaction');

        $service = new BlockRowService(
            $rowRepository,
            $columnRepository,
            $pageRepository,
            $db,
            $this->createMock(BlockRenderService::class),
            $this->normalizer()
        );

        $result = $service->createRow(1, ['position' => 'index'], [
            ['content_type' => 'html', 'content_kind' => 'CORE'],
            ['content_type' => 'html', 'content_kind' => 'PLUGIN'],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertSame('content_kind_mismatch', $result->getData()['errors'][0]['code']);
    }

    public function testCreateRowRejectsPageFromAnotherDomainBeforeTransaction(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $pageRepository = $this->createMock(BlockPageRepository::class);
        $db = $this->createMock(Database::class);
        $pageRepository->method('find')->with(30)->willReturn($this->page(30, 2));
        $rowRepository->expects($this->never())->method('create');
        $db->expects($this->never())->method('beginTransaction');

        $service = new BlockRowService(
            $rowRepository,
            $columnRepository,
            $pageRepository,
            $db,
            $this->createMock(BlockRenderService::class),
            $this->normalizer()
        );

        $result = $service->createRow(1, ['page_id' => 30]);

        $this->assertTrue($result->isFailure());
        $this->assertSame('선택한 페이지를 찾을 수 없습니다.', $result->getMessage());
    }

    public function testUpdateRowRejectsPageFromAnotherDomainBeforeTransaction(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $pageRepository = $this->createMock(BlockPageRepository::class);
        $db = $this->createMock(Database::class);
        $rowRepository->method('find')->with(10)->willReturn($this->row(10, 1));
        $pageRepository->method('find')->with(30)->willReturn($this->page(30, 2));
        $rowRepository->expects($this->never())->method('update');
        $db->expects($this->never())->method('beginTransaction');

        $service = new BlockRowService(
            $rowRepository,
            $columnRepository,
            $pageRepository,
            $db,
            $this->createMock(BlockRenderService::class),
            $this->normalizer()
        );

        $result = $service->updateRow(10, ['page_id' => 30], [], 1);

        $this->assertTrue($result->isFailure());
        $this->assertSame('선택한 페이지를 찾을 수 없습니다.', $result->getMessage());
    }

    public function testUpdateRowReturnsConflictWhenRevisionChanged(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $db = $this->createMock(Database::class);
        $rowRepository->method('find')->willReturnOnConsecutiveCalls(
            $this->row(10, 1, 3),
            $this->row(10, 1, 4)
        );
        $rowRepository->expects($this->once())
            ->method('updateIfRevision')
            ->with(10, 3, $this->isType('array'))
            ->willReturn(0);
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->once())->method('rollBack')->willReturn(true);
        $db->expects($this->never())->method('commit');

        $service = new BlockRowService(
            $rowRepository,
            $columnRepository,
            $this->createMock(BlockPageRepository::class),
            $db,
            $this->createMock(BlockRenderService::class),
            $this->normalizer()
        );

        $result = $service->updateRow(10, ['admin_title' => '충돌'], [], 1, null, 3);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->get('conflict'));
        $this->assertSame(4, $result->get('current_revision'));
    }

    public function testPostCommitCacheFailureDoesNotReportSavedUpdateAsFailure(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('find')->willReturn($this->row(10, 1, 2));
        $rowRepository->method('updateIfRevision')->willReturn(1);
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('beginTransaction')->willReturn(true);
        $db->expects($this->once())->method('commit')->willReturn(true);
        $db->expects($this->never())->method('rollBack');
        $render = $this->createMock(BlockRenderService::class);
        $render->method('invalidateRowRelatedCache')
            ->willThrowException(new \RuntimeException('cache unavailable'));

        $service = new BlockRowService(
            $rowRepository,
            $this->createMock(BlockColumnRepository::class),
            $this->createMock(BlockPageRepository::class),
            $db,
            $render,
            $this->normalizer()
        );

        $result = $service->updateRow(10, ['admin_title' => '저장됨'], [], 1, null, 2);

        $this->assertTrue($result->isSuccess(), '커밋 후 후처리 장애는 저장 실패로 응답하면 안 된다.');
    }

    public function testNormalizeDataStoresEmptyPositionMenuAsNull(): void
    {
        $normalized = $this->normalize([
            'position' => 'right',
            'position_menu' => '',
            'column_count' => 1,
        ]);

        $this->assertArrayHasKey('position_menu', $normalized);
        $this->assertNull($normalized['position_menu']);
    }

    public function testNormalizeDataClearsPositionMenuForIndexRows(): void
    {
        $normalized = $this->normalize([
            'position' => 'index',
            'position_menu' => 'main-menu',
            'column_count' => 1,
        ]);

        $this->assertNull($normalized['position_menu']);
    }

    private function normalize(array $data): array
    {
        $service = new BlockRowService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $this->createMock(BlockPageRepository::class),
            $this->createMock(Database::class),
            $this->createMock(BlockRenderService::class),
            $this->normalizer()
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeData');
        $method->setAccessible(true);

        return $method->invoke($service, $data);
    }

    private function normalizer(): BlockColumnPayloadNormalizer
    {
        return new BlockColumnPayloadNormalizer(new BlockContentSanitizer(), new BlockSkinService());
    }

    private function page(int $pageId, int $domainId): BlockPage
    {
        return BlockPage::fromArray([
            'page_id' => $pageId,
            'domain_id' => $domainId,
            'page_code' => 'page-' . $pageId,
            'page_title' => 'Page',
        ]);
    }

    private function row(int $rowId, int $domainId, int $revisionNo = 1): BlockRow
    {
        return BlockRow::fromArray([
            'row_id' => $rowId,
            'domain_id' => $domainId,
            'position' => 'index',
            'column_count' => 1,
            'revision_no' => $revisionNo,
            'is_active' => 1,
        ]);
    }
}
