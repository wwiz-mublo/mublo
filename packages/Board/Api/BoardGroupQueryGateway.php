<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Api;

use Mublo\Contract\Board\BoardGroupDescriptor;
use Mublo\Contract\Board\BoardGroupQueryInterface;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Repository\BoardGroupRepository;

final class BoardGroupQueryGateway implements BoardGroupQueryInterface
{
    public function __construct(private readonly BoardGroupRepository $groups)
    {
    }

    public function listGroups(int $domainId): array
    {
        return array_map(
            fn(BoardGroup $group): BoardGroupDescriptor => $this->describe($group),
            $this->groups->findByDomain($domainId)
        );
    }

    public function getGroup(int $domainId, int $groupId): ?BoardGroupDescriptor
    {
        $group = $this->groups->findByIdForDomain($domainId, $groupId);
        return $group !== null ? $this->describe($group) : null;
    }

    public function findGroupBySlug(int $domainId, string $slug): ?BoardGroupDescriptor
    {
        $group = $this->groups->findBySlug($domainId, $slug);
        return $group !== null ? $this->describe($group) : null;
    }

    private function describe(BoardGroup $group): BoardGroupDescriptor
    {
        return new BoardGroupDescriptor(
            $group->getGroupId(),
            $group->getGroupSlug(),
            $group->getGroupName(),
            $group->isActive(),
            $group->toArray()
        );
    }
}
