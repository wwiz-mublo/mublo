<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Infrastructure\Database\Database;
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
    /**
     * 글 목록·글 보기·댓글·파일·커뮤니티·게시판 블록은 모두 저장소 조회를 거친다.
     * overlay를 저장소에서 반영해야 한 미리보기 요청 안에서 게시판 설정이 어긋나지 않는다.
     */
    public function testRepositoryFrontReadsUseOverlayWithoutQueryingDatabase(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('table');
        $repository = new BoardConfigRepository($db, $this->overlay());

        $bySlug = $repository->findBySlug(7, 'preview-notice');
        $this->assertNotNull($bySlug);
        $this->assertSame(-1, $bySlug->getBoardId());
        $this->assertSame(7, $bySlug->getDomainId());
        $this->assertSame(3, $bySlug->getGroupId());
        $this->assertSame('미리보기 공지', $bySlug->getBoardName());
        $this->assertSame(0, $bySlug->getListLevel());
        $this->assertSame(-1, $repository->find(-1)?->getBoardId());
        $this->assertSame(-1, $repository->findAccessibleById(7, -1)?->getBoardId());
        $this->assertSame([-1], array_map(
            static fn(BoardConfig $board): int => $board->getBoardId(),
            $repository->findActiveByDomain(7)
        ));
        // overlay가 있는 도메인에서는 overlay 목록이 최종 상태다.
        $this->assertNull($repository->findBySlug(7, 'deleted-from-preview'));
        $this->assertNull($repository->findAccessibleById(7, 99));
    }

    public function testServiceReadsGoThroughOverlayAwareRepository(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('table');
        $service = new BoardConfigService(
            new BoardConfigRepository($db, $this->overlay()),
            $this->createMock(BoardGroupRepository::class),
            $this->createMock(BoardCategoryMappingRepository::class),
            $this->createMock(BoardArticleRepository::class)
        );

        $this->assertSame(-1, $service->getBoardBySlug(7, 'preview-notice')?->getBoardId());
        $this->assertSame(-1, $service->getBoard(-1)?->getBoardId());
        $this->assertSame([-1], array_map(
            static fn(BoardConfig $board): int => $board->getBoardId(),
            $service->getActiveBoards(7)
        ));
    }

    public function testMissingOverlayKeepsRepositoryPath(): void
    {
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->expects($this->once())->method('findBySlug')->with(7, 'notice')->willReturn(null);
        $service = new BoardConfigService(
            $repository,
            $this->createMock(BoardGroupRepository::class),
            $this->createMock(BoardCategoryMappingRepository::class),
            $this->createMock(BoardArticleRepository::class)
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

    private function overlay(): BoardReadOverlay
    {
        $overlay = new BoardReadOverlay();
        $result = $overlay->replaceBoards(7, [new BoardDescriptor(
            0,
            'preview-notice',
            '미리보기 공지',
            3,
            true,
            0,
            ['board_skin' => 'basic', 'per_page' => 15, 'list_level' => 0],
            []
        )]);
        $this->assertTrue($result->isSuccess(), $result->getMessage());
        return $overlay;
    }
}
