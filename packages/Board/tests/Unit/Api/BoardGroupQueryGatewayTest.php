<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Api;

use Mublo\Contract\Board\BoardGroupDescriptor;
use Mublo\Packages\Board\Api\BoardGroupQueryGateway;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use PHPUnit\Framework\TestCase;

final class BoardGroupQueryGatewayTest extends TestCase
{
    public function testListsEveryGroupIncludingEmptyGroups(): void
    {
        $repository = $this->createMock(BoardGroupRepository::class);
        $repository->expects(self::once())->method('findByDomain')->with(7)->willReturn([
            $this->group(31, 7, 'support', '고객센터'),
            $this->group(32, 7, 'empty', '빈 그룹'),
        ]);

        $groups = (new BoardGroupQueryGateway($repository))->listGroups(7);

        self::assertCount(2, $groups);
        self::assertContainsOnlyInstancesOf(BoardGroupDescriptor::class, $groups);
        self::assertSame('empty', $groups[1]->slug);
    }

    public function testDomainScopedLookupAndSlugLookup(): void
    {
        $group = $this->group(31, 7, 'support', '고객센터');
        $repository = $this->createMock(BoardGroupRepository::class);
        $repository->expects(self::once())->method('findByIdForDomain')->with(7, 31)->willReturn($group);
        $repository->expects(self::once())->method('findBySlug')->with(7, 'support')->willReturn($group);
        $gateway = new BoardGroupQueryGateway($repository);

        self::assertSame(31, $gateway->getGroup(7, 31)?->groupId);
        self::assertSame('고객센터', $gateway->findGroupBySlug(7, 'support')?->name);
    }

    private function group(int $groupId, int $domainId, string $slug, string $name): BoardGroup
    {
        return BoardGroup::fromArray([
            'group_id' => $groupId,
            'domain_id' => $domainId,
            'group_slug' => $slug,
            'group_name' => $name,
            'is_active' => 1,
        ]);
    }
}
