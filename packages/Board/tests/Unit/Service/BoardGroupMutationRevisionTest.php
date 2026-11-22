<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Packages\Board\Service\BoardGroupService;
use PHPUnit\Framework\TestCase;

final class BoardGroupMutationRevisionTest extends TestCase
{
    public function testSharedMutationContextCollectsCreatedGroupWithoutFlushing(): void
    {
        $groups = $this->createMock(BoardGroupRepository::class);
        $groups->method('existsBySlug')->with(7, 'support')->willReturn(false);
        $groups->method('getNextSortOrder')->with(7)->willReturn(1);
        $groups->method('create')->willReturn(9);
        $groups->method('find')->with(9)->willReturn(BoardGroup::fromArray([
            'group_id' => 9,
            'domain_id' => 7,
            'group_slug' => 'support',
            'group_name' => '고객센터',
            'sort_order' => 1,
        ]));
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $context = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');
        $service = new BoardGroupService(
            $groups,
            $this->createStub(BoardPermissionRepository::class),
            $recorder
        );

        $result = $service->createGroup(7, [
            'group_slug' => 'support',
            'group_name' => '고객센터',
        ], $context);

        self::assertTrue($result->isSuccess(), $result->getMessage());
        self::assertSame(['board_group:9'], array_column($context->resources(), 'resourceId'));
    }

    public function testOrderUpdateRecordsAffectedGroupsInOneAdminRevision(): void
    {
        $groups = $this->createMock(BoardGroupRepository::class);
        $groups->method('updateOrder')->with([9, 10])->willReturn(true);
        $groups->method('find')->willReturnCallback(static fn(int $groupId): BoardGroup => BoardGroup::fromArray([
            'group_id' => $groupId,
            'domain_id' => 7,
            'group_slug' => 'group-' . $groupId,
            'group_name' => '그룹 ' . $groupId,
            'sort_order' => $groupId,
        ]));
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with(
            $this->callback(static fn(SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['board_group:10', 'board_group:9'])
        )->willReturn(new SiteMutationReceipt(7, 1, 1, SiteMutationContext::SOURCE_ADMIN, null, []));
        $service = new BoardGroupService(
            $groups,
            $this->createStub(BoardPermissionRepository::class),
            $recorder
        );

        $result = $service->updateOrder(7, [9, 10]);

        self::assertTrue($result->isSuccess(), $result->getMessage());
    }
}
