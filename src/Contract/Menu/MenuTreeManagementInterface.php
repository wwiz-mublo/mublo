<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;

/**
 * 기존 메뉴 배치의 이동·정렬·제거를 위한 안정 계약.
 *
 * 생성과 최초 배치는 SiteProvisioningInterface를 사용한다. 이 계약의 표적 변경은
 * 배치 ID를 유지하므로 한 change set 안의 뒤 작업이 앞 작업의 결과를 계속 참조할
 * 수 있다. removePlacement()는 선택한 배치와 그 하위 배치를 함께 제거한다.
 */
interface MenuTreeManagementInterface
{
    /** @return list<MenuPlacementDescriptor> */
    public function listPlacements(int $domainId): array;

    /**
     * 배치를 새 부모의 첫 항목 또는 afterPlacementId 바로 뒤로 옮긴다.
     * newParentPlacementId가 null이면 루트로 옮긴다.
     */
    public function movePlacement(
        int $domainId,
        int $placementId,
        ?int $newParentPlacementId,
        ?int $afterPlacementId = null,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    /**
     * 한 부모의 모든 직계 자식을 주어진 순서로 정렬한다.
     * 누락·중복·다른 부모의 ID가 있으면 변경하지 않는다.
     *
     * @param list<int> $orderedPlacementIds
     */
    public function reorderChildren(
        int $domainId,
        ?int $parentPlacementId,
        array $orderedPlacementIds,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    public function removePlacement(
        int $domainId,
        int $placementId,
        ?SiteMutationContext $mutationContext = null
    ): Result;
}
