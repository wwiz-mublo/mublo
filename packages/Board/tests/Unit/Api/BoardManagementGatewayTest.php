<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Api;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Api\BoardManagementGateway;
use Mublo\Packages\Board\Entity\BoardCategoryMapping;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Service\BoardConfigService;
use Tests\Board\TestCase;

final class BoardManagementGatewayTest extends TestCase
{
    private function board(int $domainId = 7): BoardConfig
    {
        return BoardConfig::fromArray([
            'board_id' => 12,
            'domain_id' => $domainId,
            'group_id' => 3,
            'board_slug' => 'notice',
            'board_name' => '공지사항',
            'is_active' => 1,
        ]);
    }

    public function testListsStableDescriptorsWithUsageAndCategories(): void
    {
        $boards = $this->createMock(BoardConfigRepository::class);
        $boards->method('findByDomain')->with(7)->willReturn([$this->board()]);
        $boards->method('getArticleCount')->with(12)->willReturn(4);
        $mappings = $this->createMock(BoardCategoryMappingRepository::class);
        $mappings->method('findByBoard')->with(12)->willReturn([
            BoardCategoryMapping::fromArray([
                'mapping_id' => 1,
                'board_id' => 12,
                'category_id' => 9,
                'sort_order' => 0,
                'is_active' => 1,
            ]),
        ]);

        $gateway = new BoardManagementGateway(
            $this->createStub(BoardConfigService::class),
            $boards,
            $mappings
        );
        $result = $gateway->listBoards(7);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(BoardDescriptor::class, $result[0]);
        $this->assertSame([9], $result[0]->categoryIds);
        $this->assertSame(4, $result[0]->articleCount);
        $this->assertTrue($result[0]->isInUse());
        $this->assertFalse($result[0]->canDelete());
    }

    public function testUpdateEnforcesOwnershipAndFiltersUnsafeFields(): void
    {
        $boards = $this->createMock(BoardConfigRepository::class);
        $boards->method('find')->with(12)->willReturn($this->board());
        $boards->method('getArticleCount')->willReturn(0);
        $service = $this->createMock(BoardConfigService::class);
        $context = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');
        $updated = Result::success('updated');
        $service->expects($this->once())->method('updateBoard')->with(
            12,
            ['board_name' => '새 이름'],
            $context
        )->willReturn($updated);

        $gateway = new BoardManagementGateway(
            $service,
            $boards,
            $this->createMock(BoardCategoryMappingRepository::class)
        );
        $result = $gateway->updateBoard(7, 12, [
            'board_name' => '새 이름',
            'board_slug' => 'unsafe-change',
            'domain_id' => 99,
        ], $context);

        $this->assertSame($updated, $result);
    }

    public function testMutationRejectsBoardFromAnotherDomain(): void
    {
        $boards = $this->createMock(BoardConfigRepository::class);
        $boards->method('find')->with(12)->willReturn($this->board(8));
        $service = $this->createMock(BoardConfigService::class);
        $service->expects($this->never())->method('updateBoard');
        $service->expects($this->never())->method('deleteBoard');
        $gateway = new BoardManagementGateway(
            $service,
            $boards,
            $this->createMock(BoardCategoryMappingRepository::class)
        );

        $this->assertTrue($gateway->updateBoard(7, 12, ['board_name' => 'x'])->isFailure());
        $this->assertTrue($gateway->removeBoard(7, 12)->isFailure());
    }
}
