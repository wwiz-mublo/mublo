<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Contract\Board\BoardGroupDescriptor;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Service\Board\BoardGroupReadOverlay;
use Mublo\Service\Board\BoardReadOverlay;
use PHPUnit\Framework\TestCase;

/**
 * 커뮤니티 그룹 탭·그룹 화면·게시판 그룹 블록은 그룹 저장소와 그룹별 게시판 조회를 거친다.
 * 미리보기 overlay를 저장소에서 반영해야 새 그룹·그룹을 옮긴 게시판이 적용 뒤와 같게 보인다.
 */
final class BoardGroupReadOverlayTest extends TestCase
{
    public function testGroupReadsUseOverlayWithDraftGroupsAndWithoutQueryingDatabase(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('table');
        $groups = new BoardGroupReadOverlay();
        $this->assertTrue($groups->replaceGroups(7, [
            new BoardGroupDescriptor(31, 'support', '고객센터', true, ['list_level' => 0]),
            new BoardGroupDescriptor(32, 'hidden', '숨김', false, []),
            new BoardGroupDescriptor(-1, 'community', '커뮤니티', true, []),
        ])->isSuccess());
        $repository = new BoardGroupRepository($db, $groups);

        $this->assertSame(['support', 'community'], array_map(
            static fn(BoardGroup $group): string => $group->getGroupSlug(),
            $repository->findActiveByDomain(7)
        ));
        $this->assertCount(3, $repository->findByDomain(7));
        $this->assertSame(-1, $repository->findBySlug(7, 'community')?->getGroupId());
        $this->assertSame('커뮤니티', $repository->findByIdForDomain(7, -1)?->getGroupName());
        $this->assertSame(3, $repository->findByIdForDomain(7, -1)?->getSortOrder(), '목록 순서가 표시 순서');
        $this->assertNull($repository->findBySlug(7, 'removed'), 'overlay가 있으면 overlay 목록이 최종 상태');
    }

    public function testBoardsPerGroupFollowMovedAndDraftBoards(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('table');
        $boards = new BoardReadOverlay();
        $this->assertTrue($boards->replaceBoards(7, [
            new BoardDescriptor(5, 'notice', '공지', 31, true, 3, ['sort_order' => 2], []),
            new BoardDescriptor(6, 'qna', '질문', 32, true, 0, ['sort_order' => 1], []),
            new BoardDescriptor(0, 'free', '자유', 32, true, 0, ['sort_order' => 2], []),
        ])->isSuccess());
        $repository = new BoardConfigRepository($db, $boards);

        $this->assertSame(['qna', 'free'], array_map(
            static fn(BoardConfig $board): string => $board->getBoardSlug(),
            $repository->findByGroupForDomain(7, 32)
        ), '그룹을 옮긴 기존 게시판과 새 게시판이 그 그룹에 함께 보인다');
        $this->assertSame(['notice'], array_map(
            static fn(BoardConfig $board): string => $board->getBoardSlug(),
            $repository->findByGroupForDomain(7, 31)
        ));
    }

    public function testCommunityGroupFeedFiltersByTheGroupsBoardsInsteadOfTheDatabaseJoin(): void
    {
        $boards = new BoardReadOverlay();
        $this->assertTrue($boards->replaceBoards(7, [
            new BoardDescriptor(5, 'notice', '공지', 31, true, 3, [], []),
            new BoardDescriptor(6, 'qna', '질문', 32, true, 2, [], []),
        ])->isSuccess());
        $articles = $this->createMock(\Mublo\Packages\Board\Repository\BoardArticleRepository::class);
        $articles->expects($this->once())->method('getAllByDomain')
            ->with(7, 1, 20, $this->callback(static fn(array $filters): bool =>
                $filters['board_ids'] === [6] && !array_key_exists('group_id', $filters)))
            ->willReturn(['items' => [], 'pagination' => []]);
        $permissions = $this->createMock(\Mublo\Packages\Board\Service\BoardPermissionService::class);
        $permissions->method('canList')->willReturn(true);
        $service = new \Mublo\Packages\Board\Service\CommunityService(
            $articles,
            new BoardConfigRepository($this->createMock(Database::class), $boards),
            $this->createMock(BoardGroupRepository::class),
            $permissions
        );

        $this->assertTrue($service->getFeed(7, 1, 20, ['group_id' => 32], $this->createMock(\Mublo\Core\Context\Context::class))->isSuccess());
    }

    public function testInvalidOverlaysAreRejected(): void
    {
        $groups = new BoardGroupReadOverlay();
        foreach ([
            [new BoardGroupDescriptor(0, 'x', '이름', true, [])],
            [new BoardGroupDescriptor(1, 'Bad Slug', '이름', true, [])],
            [new BoardGroupDescriptor(1, 'a', '가', true, []), new BoardGroupDescriptor(2, 'a', '나', true, [])],
            [new BoardGroupDescriptor(-1, 'a', '가', true, []), new BoardGroupDescriptor(-1, 'b', '나', true, [])],
        ] as $list) {
            $this->assertFalse($groups->replaceGroups(7, $list)->isSuccess());
        }
        $this->assertNull($groups->groups(7), '거절된 목록은 등록하지 않는다');
    }
}
