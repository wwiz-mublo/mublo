<?php
declare(strict_types=1);

namespace Mublo\Service\Menu;

use Mublo\Contract\Menu\MenuPlacementDescriptor;
use Mublo\Contract\Menu\MenuTreeManagementInterface;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Repository\Menu\MenuTreeRepository;

final class MenuTreeManagementGateway implements MenuTreeManagementInterface
{
    public function __construct(
        private MenuService $menuService,
        private MenuTreeRepository $treeRepository
    ) {
    }

    public function listPlacements(int $domainId): array
    {
        return array_map(
            static fn (array $row): MenuPlacementDescriptor => new MenuPlacementDescriptor(
                (int) $row['node_id'],
                (string) $row['menu_code'],
                (string) $row['path_code'],
                $row['parent_code'] !== null ? (string) $row['parent_code'] : null,
                (int) $row['depth'],
                (int) $row['sort_order'],
                (string) $row['label'],
                isset($row['url']) ? (string) $row['url'] : null,
                isset($row['item_id']) ? (int) $row['item_id'] : null
            ),
            array_values($this->treeRepository->findTreeWithItems($domainId, false))
        );
    }

    public function movePlacement(
        int $domainId,
        int $placementId,
        ?int $newParentPlacementId,
        ?int $afterPlacementId = null,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        return $this->menuService->moveTreeNode(
            $domainId,
            $placementId,
            $newParentPlacementId,
            $afterPlacementId,
            $mutationContext
        );
    }

    public function reorderChildren(
        int $domainId,
        ?int $parentPlacementId,
        array $orderedPlacementIds,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        return $this->menuService->reorderTreeChildren(
            $domainId,
            $parentPlacementId,
            $orderedPlacementIds,
            $mutationContext
        );
    }

    public function removePlacement(
        int $domainId,
        int $placementId,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        return $this->menuService->removeFromTreeInDomain(
            $domainId,
            $placementId,
            $mutationContext
        );
    }
}
