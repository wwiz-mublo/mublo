<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\Site\SiteMutationRecorderInterface;
use Mublo\Contract\Site\SiteMutationReceipt;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Packages\Board\Service\BoardGroupService;
use PHPUnit\Framework\TestCase;

final class BoardGroupMutationRevisionTest extends TestCase
{
    private bool $inTransaction = false;

    public function testSharedMutationContextCollectsCreatedGroupWithoutFlushing(): void
    {
        $groups = $this->repository();
        $groups->method('existsBySlug')->with(7, 'support')->willReturn(false);
        $groups->method('getNextSortOrder')->with(7)->willReturn(1);
        $groups->method('create')->willReturn(9);
        $groups->method('find')->with(9)->willReturn($this->group(9, 1));
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects(self::never())->method('record');
        $context = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');
        $service = new BoardGroupService($groups, $this->createStub(BoardPermissionRepository::class), $recorder);

        $result = $service->createGroup(7, [
            'group_slug' => 'support',
            'group_name' => '고객센터',
        ], $context);

        self::assertTrue($result->isSuccess(), $result->getMessage());
        self::assertSame(['board_group:9'], array_column($context->resources(), 'resourceId'));
    }

    /**
     * 관리자 화면은 바깥 문맥 없이 저장한다. 기록 장치는 활성 트랜잭션 안에서만 기록하므로
     * 서비스가 쓰기와 기록을 한 트랜잭션으로 묶어야 한다(묶지 않으면 저장 뒤 예외가 나고 revision이 남지 않는다).
     */
    public function testAdminCreateWritesAndRecordsInsideOneTransaction(): void
    {
        $groups = $this->repository();
        $groups->method('existsBySlug')->willReturn(false);
        $groups->method('getNextSortOrder')->willReturn(1);
        $groups->method('create')->willReturnCallback(function (): int {
            self::assertTrue($this->inTransaction, '그룹 저장은 트랜잭션 안에서 한다');
            return 9;
        });
        $groups->method('find')->willReturn($this->group(9, 1));
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willReturnCallback(function (SiteMutationContext $context): SiteMutationReceipt {
            self::assertTrue($this->inTransaction, 'revision 기록은 같은 트랜잭션 안에서 한다');
            self::assertSame(SiteMutationContext::SOURCE_ADMIN, $context->source);
            self::assertSame(['board_group:9'], array_column($context->resources(), 'resourceId'));
            return new SiteMutationReceipt(7, 1, 1, SiteMutationContext::SOURCE_ADMIN, null, []);
        });
        $service = new BoardGroupService($groups, $this->createStub(BoardPermissionRepository::class), $recorder);

        $result = $service->createGroup(7, ['group_slug' => 'support', 'group_name' => '고객센터']);

        self::assertTrue($result->isSuccess(), $result->getMessage());
    }

    public function testAWriteInsideAnExtensionScopeIsRecordedAsThatExtension(): void
    {
        $groups = $this->repository();
        $groups->method('existsBySlug')->willReturn(false);
        $groups->method('getNextSortOrder')->willReturn(1);
        $groups->method('create')->willReturn(9);
        $groups->method('find')->willReturn($this->group(9, 1));
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willReturnCallback(function (SiteMutationContext $context): SiteMutationReceipt {
            self::assertSame(SiteMutationContext::SOURCE_EXTENSION, $context->source, '확장 설치 훅의 변경은 관리자 변경이 아니다');
            self::assertSame('extension:package:Board:install', $context->correlationId);
            return new SiteMutationReceipt(7, 1, 1, $context->source, $context->correlationId, []);
        });
        $service = new BoardGroupService($groups, $this->createStub(BoardPermissionRepository::class), $recorder);

        $result = \Mublo\Contract\Site\SiteMutationScope::run(
            SiteMutationContext::SOURCE_EXTENSION,
            'extension:package:Board:install',
            fn () => $service->createGroup(7, ['group_slug' => 'support', 'group_name' => '고객센터'])
        );

        self::assertTrue($result->isSuccess(), $result->getMessage());
    }

    public function testOrderUpdateRecordsOnlyGroupsWhoseOrderChangedInOneAdminRevision(): void
    {
        $order = [9 => 1, 10 => 2, 11 => 3];
        $groups = $this->repository();
        $groups->method('updateOrder')->with([10, 9, 11])->willReturnCallback(function () use (&$order): bool {
            $order = [10 => 1, 9 => 2, 11 => 3];
            return true;
        });
        $groups->method('find')->willReturnCallback(function (int $groupId) use (&$order): BoardGroup {
            return $this->group($groupId, $order[$groupId]);
        });
        $recorder = $this->createMock(SiteMutationRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with(
            $this->callback(static fn(SiteMutationContext $context): bool =>
                array_column($context->resources(), 'resourceId') === ['board_group:10', 'board_group:9'])
        )->willReturn(new SiteMutationReceipt(7, 1, 1, SiteMutationContext::SOURCE_ADMIN, null, []));
        $service = new BoardGroupService($groups, $this->createStub(BoardPermissionRepository::class), $recorder);

        $result = $service->updateOrder(7, [10, 9, 11]);

        self::assertTrue($result->isSuccess(), $result->getMessage());
    }

    public function testOrderUpdateRejectsGroupsOfAnotherDomainBeforeWriting(): void
    {
        $groups = $this->repository();
        $groups->expects(self::never())->method('updateOrder');
        $groups->method('find')->willReturnCallback(fn(int $groupId): BoardGroup =>
            BoardGroup::fromArray(['group_id' => $groupId, 'domain_id' => $groupId === 12 ? 8 : 7, 'group_slug' => 'g' . $groupId, 'group_name' => 'g']));
        $service = new BoardGroupService($groups, $this->createStub(BoardPermissionRepository::class), $this->createStub(SiteMutationRecorderInterface::class));

        self::assertFalse($service->updateOrder(7, [9, 12])->isSuccess());
    }

    /** @return BoardGroupRepository&\PHPUnit\Framework\MockObject\MockObject */
    private function repository(): BoardGroupRepository
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(function (callable $work): mixed {
            $this->inTransaction = true;
            try {
                return $work();
            } finally {
                $this->inTransaction = false;
            }
        });
        $groups = $this->createMock(BoardGroupRepository::class);
        $groups->method('getDb')->willReturn($db);
        return $groups;
    }

    private function group(int $groupId, int $sortOrder): BoardGroup
    {
        return BoardGroup::fromArray([
            'group_id' => $groupId,
            'domain_id' => 7,
            'group_slug' => 'group-' . $groupId,
            'group_name' => '그룹 ' . $groupId,
            'sort_order' => $sortOrder,
        ]);
    }
}
