<?php

namespace Tests\Board\Unit\Api;

use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Api\BoardProvisioningGateway;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardGroupService;
use PHPUnit\Framework\TestCase;

class BoardProvisioningGatewayTest extends TestCase
{
    private function gateway(
        ?BoardGroupService $groupService = null,
        ?BoardGroupRepository $groupRepo = null,
        ?BoardConfigService $boardService = null,
        ?BoardConfigRepository $boardRepo = null
    ): BoardProvisioningGateway {
        return new BoardProvisioningGateway(
            $groupService ?? $this->createMock(BoardGroupService::class),
            $groupRepo ?? $this->createMock(BoardGroupRepository::class),
            $boardService ?? $this->createMock(BoardConfigService::class),
            $boardRepo ?? $this->createMock(BoardConfigRepository::class)
        );
    }

    public function testReturnsExistingGroupWithoutCreating(): void
    {
        $groupRepo = $this->createMock(BoardGroupRepository::class);
        $groupRepo->method('findBySlug')->willReturn(BoardGroup::fromArray(['group_id' => 5, 'group_slug' => 'site']));

        $groupService = $this->createMock(BoardGroupService::class);
        $groupService->expects($this->never())->method('createGroup');

        $result = $this->gateway($groupService, $groupRepo)->ensureGroup(1, 'site', ['group_name' => '사이트']);

        $this->assertSame(5, $result->getData()['group_id']);
        $this->assertFalse($result->getData()['created']);
    }

    public function testCreatesGroupWithKeyAsSlug(): void
    {
        $groupRepo = $this->createMock(BoardGroupRepository::class);
        $groupRepo->method('findBySlug')->willReturn(null);

        $groupService = $this->createMock(BoardGroupService::class);
        $groupService->expects($this->once())
            ->method('createGroup')
            ->with(1, $this->callback(fn(array $d): bool => $d['group_slug'] === 'site' && $d['group_name'] === '사이트'))
            ->willReturn(Result::success('ok', ['group_id' => 9]));

        $result = $this->gateway($groupService, $groupRepo)->ensureGroup(1, 'site', ['group_name' => '사이트']);

        $this->assertSame(9, $result->getData()['group_id']);
        $this->assertTrue($result->getData()['created']);
    }

    public function testReturnsExistingBoardWithoutCreating(): void
    {
        $boardRepo = $this->createMock(BoardConfigRepository::class);
        $boardRepo->method('findBySlug')->willReturn(BoardConfig::fromArray(['board_id' => 3, 'board_slug' => 'notice']));

        $boardService = $this->createMock(BoardConfigService::class);
        $boardService->expects($this->never())->method('createBoard');

        $result = $this->gateway(null, null, $boardService, $boardRepo)
            ->ensureBoard(1, 'notice', ['board_name' => '공지사항', 'group_slug' => 'site']);

        $this->assertSame(3, $result->getData()['board_id']);
        $this->assertFalse($result->getData()['created']);
    }

    /** 게시판은 그룹에 속한다. 그룹이 없으면 만들지 않고 명확히 실패한다. */
    public function testFailsWhenGroupMissing(): void
    {
        $boardRepo = $this->createMock(BoardConfigRepository::class);
        $boardRepo->method('findBySlug')->willReturn(null);

        $groupRepo = $this->createMock(BoardGroupRepository::class);
        $groupRepo->method('findBySlug')->willReturn(null);

        $result = $this->gateway(null, $groupRepo, null, $boardRepo)
            ->ensureBoard(1, 'notice', ['group_slug' => 'site']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('ensureGroup', $result->getMessage());
    }

    public function testFailsWhenGroupSlugOmitted(): void
    {
        $boardRepo = $this->createMock(BoardConfigRepository::class);
        $boardRepo->method('findBySlug')->willReturn(null);

        $result = $this->gateway(null, null, null, $boardRepo)->ensureBoard(1, 'notice');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('group_slug', $result->getMessage());
    }

    /** 동시 호출로 슬러그 중복 실패가 나면 먼저 만들어진 게시판을 반환한다. */
    public function testResolvesRaceOnDuplicateSlug(): void
    {
        $boardRepo = $this->createMock(BoardConfigRepository::class);
        $boardRepo->method('findBySlug')->willReturnOnConsecutiveCalls(
            null,
            BoardConfig::fromArray(['board_id' => 21, 'board_slug' => 'notice'])
        );

        $groupRepo = $this->createMock(BoardGroupRepository::class);
        $groupRepo->method('findBySlug')->willReturn(BoardGroup::fromArray(['group_id' => 5, 'group_slug' => 'site']));

        $boardService = $this->createMock(BoardConfigService::class);
        $boardService->method('createBoard')->willReturn(Result::failure('이미 사용중인 슬러그입니다.'));

        $result = $this->gateway(null, $groupRepo, $boardService, $boardRepo)
            ->ensureBoard(1, 'notice', ['group_slug' => 'site']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(21, $result->getData()['board_id']);
        $this->assertFalse($result->getData()['created']);
    }
}
