<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardCategoryRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardCategory;
use Mublo\Service\Board\BoardReadOverlay;
use PHPUnit\Framework\TestCase;

final class BoardConfigReadOverlayTest extends TestCase
{
    public function testFrontReadMethodsUseOverlayWithoutReadingConfigRepository(): void
    {
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->expects($this->never())->method('find');
        $repository->expects($this->never())->method('findBySlug');
        $repository->expects($this->never())->method('findActiveByDomain');
        $overlay = new BoardReadOverlay();
        $result = $overlay->replaceBoards(7, [new BoardDescriptor(
            0,
            'preview-notice',
            '미리보기 공지',
            3,
            true,
            0,
            ['board_skin' => 'basic', 'per_page' => 15],
            []
        )]);
        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $service = new BoardConfigService(
            $repository,
            $this->createMock(BoardGroupRepository::class),
            $this->createMock(BoardCategoryMappingRepository::class),
            $this->createMock(BoardArticleRepository::class),
            readOverlay: $overlay
        );

        $bySlug = $service->getBoardBySlug(7, 'preview-notice');
        $this->assertNotNull($bySlug);
        $this->assertSame(-1, $bySlug->getBoardId());
        $this->assertSame(7, $bySlug->getDomainId());
        $this->assertSame('미리보기 공지', $bySlug->getBoardName());
        $this->assertSame($bySlug->getBoardId(), $service->getBoard(-1)?->getBoardId());
        $this->assertSame([-1], array_map(
            static fn(BoardConfig $board): int => $board->getBoardId(),
            $service->getActiveBoards(7)
        ));
        $this->assertNull($service->getBoardBySlug(7, 'deleted-from-preview'));
    }

    public function testMissingOverlayKeepsRepositoryPath(): void
    {
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->expects($this->once())->method('findBySlug')->with(7, 'notice')->willReturn(null);
        $service = new BoardConfigService(
            $repository,
            $this->createMock(BoardGroupRepository::class),
            $this->createMock(BoardCategoryMappingRepository::class),
            $this->createMock(BoardArticleRepository::class),
            readOverlay: new BoardReadOverlay()
        );

        $this->assertNull($service->getBoardBySlug(7, 'notice'));
    }

    public function testCategoryReadUsesOverlayMappings(): void
    {
        $overlay = new BoardReadOverlay();
        $this->assertTrue($overlay->replaceBoards(7, [new BoardDescriptor(
            -10,
            'preview',
            '미리보기',
            3,
            true,
            0,
            [],
            [5]
        )])->isSuccess());
        $categoryRepository = $this->createMock(BoardCategoryRepository::class);
        $categoryRepository->expects($this->once())->method('find')->with(5)->willReturn(
            BoardCategory::fromArray([
                'category_id' => 5,
                'domain_id' => 7,
                'category_name' => '일반',
                'category_slug' => 'general',
                'is_active' => 1,
            ])
        );
        $mappingRepository = $this->createMock(BoardCategoryMappingRepository::class);
        $mappingRepository->expects($this->never())->method('findByBoardWithCategory');
        $service = new BoardCategoryService($categoryRepository, $mappingRepository, $overlay);

        $this->assertSame([[
            'category_id' => 5,
            'category_name' => '일반',
            'category_slug' => 'general',
        ]], $service->getCategoriesByBoard(-10));
    }
}
