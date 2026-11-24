<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Block;

use Mublo\Contract\Block\BlockRowSnapshot;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockColumnContentService;
use Mublo\Service\Block\BlockColumnWriteContext;
use Mublo\Service\Block\BlockMutationGateway;
use Mublo\Service\Block\BlockRowService;
use PHPUnit\Framework\TestCase;

final class BlockMutationGatewayTest extends TestCase
{
    public function testReturnsDomainScopedPortableSnapshot(): void
    {
        $row = $this->row(10, 7, 3);
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->method('find')->with(10)->willReturn($row);
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->expects($this->once())->method('findAllByRowForDomain')->with(10, 7)->willReturn([]);
        $contents = new BlockColumnContentService(
            $columns,
            $this->createStub(BlockColumnContentRepository::class)
        );
        $gateway = new BlockMutationGateway(
            $this->createStub(BlockRowService::class),
            $rows,
            $columns,
            $contents
        );

        $snapshot = $gateway->getRow(7, 10);

        $this->assertInstanceOf(BlockRowSnapshot::class, $snapshot);
        $this->assertSame(3, $snapshot->revision);
        $this->assertSame([], $snapshot->columns);
        $this->assertNull($gateway->getRow(8, 10));
    }

    public function testUpdateUsesAllowlistAndCanExplicitlyRemoveAllColumns(): void
    {
        $service = $this->createMock(BlockRowService::class);
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->method('find')->with(10)->willReturn($this->row(10, 7, 3));
        $columns = $this->createStub(BlockColumnRepository::class);
        $context = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION);
        $updated = Result::success('updated');
        $service->expects($this->once())->method('updateRow')->with(
            10,
            $this->callback(static fn (array $row): bool =>
                $row['admin_title'] === '새 제목'
                && $row['position'] === 'index'
                && $row['column_count'] === 1
                && !array_key_exists('domain_id', $row)
                && !array_key_exists('evil_column', $row)
                && $row['columns'] === true
            ),
            [],
            7,
            $this->callback(static fn (BlockColumnWriteContext $write): bool =>
                $write->domainId === 7 && $write->source === BlockColumnWriteContext::SOURCE_INTERACTIVE
            ),
            3,
            null,
            $context
        )->willReturn($updated);
        $gateway = new BlockMutationGateway(
            $service,
            $rows,
            $columns,
            new BlockColumnContentService($columns, $this->createStub(BlockColumnContentRepository::class))
        );

        $result = $gateway->updateRow(
            7,
            10,
            ['admin_title' => '새 제목', 'domain_id' => 99, 'evil_column' => 'x'],
            [],
            3,
            $context
        );

        $this->assertSame($updated, $result);
    }

    /**
     * 편집 쓰기는 앱·AI 편집 정책(스크립트·include 금지)을 따르고, 복원은 몰이 기록한 원래 내용을
     * 리비전 복구와 같은 정책으로 되살린다.
     */
    public function testRestoreWritesUseTheRevisionRestorePolicyButEditsStayInteractive(): void
    {
        $service = $this->createMock(BlockRowService::class);
        $writes = [];
        $capture = static function (BlockColumnWriteContext $write) use (&$writes): bool {
            $writes[] = [$write->allowRawJs, $write->allowInclude, $write->allowUnresolvedExtension];
            return true;
        };
        $service->expects($this->exactly(2))->method('createRow')
            ->with(7, $this->anything(), [], $this->callback($capture), null)
            ->willReturn(Result::success('created'));
        $service->expects($this->once())->method('updateRow')
            ->with(10, $this->anything(), [], 7, $this->callback($capture), 3, null, null)
            ->willReturn(Result::success('updated'));
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->method('find')->with(10)->willReturn($this->row(10, 7, 3));
        $columns = $this->createStub(BlockColumnRepository::class);
        $gateway = new BlockMutationGateway(
            $service,
            $rows,
            $columns,
            new BlockColumnContentService($columns, $this->createStub(BlockColumnContentRepository::class))
        );
        $row = ['position' => 'index', 'admin_title' => '배너'];

        $this->assertTrue($gateway->createRow(7, $row, [])->isSuccess());
        $this->assertTrue($gateway->restoreRow(7, $row, [])->isSuccess());
        $this->assertTrue($gateway->restoreRowContent(7, 10, ['admin_title' => '배너'], [], 3)->isSuccess());

        $this->assertSame([
            [false, false, false],
            [true, true, true],
            [true, true, true],
        ], $writes);
    }

    public function testRestoreContentRejectsRowsOfAnotherDomain(): void
    {
        $service = $this->createMock(BlockRowService::class);
        $service->expects($this->never())->method('updateRow');
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->method('find')->with(10)->willReturn($this->row(10, 8, 3));
        $columns = $this->createStub(BlockColumnRepository::class);
        $gateway = new BlockMutationGateway(
            $service,
            $rows,
            $columns,
            new BlockColumnContentService($columns, $this->createStub(BlockColumnContentRepository::class))
        );

        $this->assertTrue($gateway->restoreRowContent(7, 10, [], [], 3)->isFailure());
    }

    public function testReorderRejectsPartialSlotBeforeMutation(): void
    {
        $service = $this->createMock(BlockRowService::class);
        $service->expects($this->never())->method('updateOrder');
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->method('find')->with(10)->willReturn($this->row(10, 7, 1));
        $rows->method('findByPositionScope')->willReturn([
            $this->row(10, 7, 1),
            $this->row(11, 7, 1),
        ]);
        $columns = $this->createStub(BlockColumnRepository::class);
        $gateway = new BlockMutationGateway(
            $service,
            $rows,
            $columns,
            new BlockColumnContentService($columns, $this->createStub(BlockColumnContentRepository::class))
        );

        $this->assertTrue($gateway->reorderRows(7, [10])->isFailure());
    }

    private function row(int $rowId, int $domainId, int $revision): BlockRow
    {
        return BlockRow::fromArray([
            'row_id' => $rowId,
            'domain_id' => $domainId,
            'position' => 'index',
            'section_id' => 'section-' . $rowId,
            'revision_no' => $revision,
            'sort_order' => $rowId,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
