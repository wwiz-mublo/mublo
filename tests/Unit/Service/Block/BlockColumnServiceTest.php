<?php

namespace Tests\Unit\Service\Block;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockColumnService;
use PHPUnit\Framework\TestCase;

/**
 * 칸 조회의 도메인 격리. 칸 쓰기는 BlockRowService가 맡는다(정제·간격 제한은 BlockColumnPayloadNormalizerTest).
 */
class BlockColumnServiceTest extends TestCase
{
    public function testDomainScopedColumnLookupRejectsForeignRowWithoutReadingColumns(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('find')->willReturn($this->row(domainId: 2));
        $repository->expects($this->never())->method('findAllByRowForDomain');

        $service = new BlockColumnService($repository, $rowRepository);

        $this->assertNull($service->getColumnsByRowForDomain(10, 1));
    }

    public function testDomainScopedColumnLookupRejectsAColumnOfAnotherDomain(): void
    {
        $repository = $this->createMock(BlockColumnRepository::class);
        $repository->method('find')->willReturn(BlockColumn::fromArray([
            'column_id' => 20,
            'row_id' => 10,
            'domain_id' => 2,
            'column_index' => 0,
            'is_active' => 1,
        ]));

        $service = new BlockColumnService($repository, $this->createMock(BlockRowRepository::class));

        $this->assertNull($service->getColumnForDomain(20, 1));
        $this->assertSame(20, $service->getColumnForDomain(20, 2)?->getColumnId());
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
}
