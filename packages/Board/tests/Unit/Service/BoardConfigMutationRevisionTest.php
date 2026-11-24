<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardConfigService;
use Tests\Board\TestCase;

final class BoardConfigMutationRevisionTest extends TestCase
{
    /** @param list<callable> $afterCommit */
    private function db(array &$afterCommit): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $db->method('afterCommit')->willReturnCallback(
            static function (callable $callback) use (&$afterCommit): void {
                $afterCommit[] = $callback;
            }
        );
        return $db;
    }

    private function board(string $name = '공지사항'): BoardConfig
    {
        return BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => 1,
            'group_id' => 3,
            'board_slug' => 'notice',
            'board_name' => $name,
        ]);
    }

    private function receipt(): SiteMutationReceipt
    {
        return new SiteMutationReceipt(1, 1, 1, SiteMutationContext::SOURCE_ADMIN, null, []);
    }

    public function testCreateRecordsBoardAndDispatchesEventOnlyAfterCommit(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('existsBySlug')->willReturn(false);
        $repository->method('getNextSortOrder')->willReturn(1);
        $repository->method('create')->willReturn(20);
        $repository->method('find')->with(20)->willReturn($this->board());
        $groups = $this->createMock(BoardGroupRepository::class);
        $groups->method('find')->with(3)->willReturn(BoardGroup::fromArray([
            'group_id' => 3,
            'domain_id' => 1,
            'group_slug' => 'community',
            'group_name' => '커뮤니티',
        ]));
        $mappings = $this->createMock(BoardCategoryMappingRepository::class);
        $mappings->method('findByBoard')->willReturn([]);
        $events = $this->createMock(EventDispatcher::class);
        $events->expects($this->once())->method('dispatch')->willReturnArgument(0);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['board:20'])
        )->willReturn($this->receipt());

        $service = new BoardConfigService(
            $repository,
            $groups,
            $mappings,
            $this->createMock(BoardArticleRepository::class),
            $events,
            null,
            $recorder
        );
        $result = $service->createBoard(1, [
            'board_slug' => 'notice',
            'board_name' => '공지사항',
            'group_id' => 3,
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertCount(1, $afterCommit);
        foreach ($afterCommit as $callback) {
            $callback();
        }
    }

    public function testSharedContextCollectsChangedBoardWithoutFlushing(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('find')->willReturnOnConsecutiveCalls(
            $this->board(),
            $this->board(),
            $this->board('새 공지사항')
        );
        $repository->method('update')->willReturn(1);
        $mappings = $this->createMock(BoardCategoryMappingRepository::class);
        $mappings->method('findByBoard')->willReturn([]);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->never())->method('record');
        $context = SiteMutationContext::create(1, SiteMutationContext::SOURCE_EXTENSION, 'change-set-9');

        $service = new BoardConfigService(
            $repository,
            $this->createMock(BoardGroupRepository::class),
            $mappings,
            $this->createMock(BoardArticleRepository::class),
            null,
            null,
            $recorder
        );
        $result = $service->updateBoard(20, ['board_name' => '새 공지사항'], $context);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(['board:20'], array_column($context->resources(), 'resourceId'));
    }

    public function testDeleteRecordsTombstoneAndDefersEvent(): void
    {
        $afterCommit = [];
        $repository = $this->createMock(BoardConfigRepository::class);
        $repository->method('getDb')->willReturn($this->db($afterCommit));
        $repository->method('find')->with(20)->willReturn($this->board());
        $repository->method('getArticleCount')->willReturn(0);
        $repository->method('delete')->willReturn(1);
        $events = $this->createMock(EventDispatcher::class);
        $events->expects($this->once())->method('dispatch')->willReturnArgument(0);
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects($this->once())->method('record')->with(
            $this->callback(static fn (SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['board:20'])
        )->willReturn($this->receipt());

        $service = new BoardConfigService(
            $repository,
            $this->createMock(BoardGroupRepository::class),
            $this->createMock(BoardCategoryMappingRepository::class),
            $this->createMock(BoardArticleRepository::class),
            $events,
            null,
            $recorder
        );
        $result = $service->deleteBoard(20);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertCount(1, $afterCommit);
        foreach ($afterCommit as $callback) {
            $callback();
        }
    }
}
