<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Api;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Api\BoardGroupManagementGateway;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardGroupService;
use PHPUnit\Framework\TestCase;

final class BoardGroupManagementGatewayTest extends TestCase
{
    public function testRemovesOnlyGroupsOwnedByTheDomainWithSharedMutationContext(): void
    {
        $context = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION, 'cs-1');
        $repository = $this->createMock(BoardGroupRepository::class);
        $repository->expects(self::once())->method('findByIdForDomain')->with(7, 31)->willReturn(
            BoardGroup::fromArray([
                'group_id' => 31,
                'domain_id' => 7,
                'group_slug' => 'support',
                'group_name' => '고객센터',
                'is_active' => 1,
            ])
        );
        $service = $this->createMock(BoardGroupService::class);
        $service->expects(self::once())->method('deleteGroup')->with(31, $context)
            ->willReturn(Result::success('삭제'));

        self::assertTrue((new BoardGroupManagementGateway($service, $repository))->removeGroup(7, 31, $context)->isSuccess());
    }

    public function testRejectsGroupsFromAnotherDomain(): void
    {
        $repository = $this->createMock(BoardGroupRepository::class);
        $repository->method('findByIdForDomain')->with(8, 31)->willReturn(null);
        $service = $this->createMock(BoardGroupService::class);
        $service->expects(self::never())->method('deleteGroup');

        self::assertTrue((new BoardGroupManagementGateway($service, $repository))->removeGroup(8, 31)->isFailure());
    }
}
