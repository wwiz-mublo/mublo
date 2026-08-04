<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

use Mublo\Core\Result\Result;

/**
 * 운영 중 확장 메뉴를 조회·동기화하기 위한 안정 경계.
 *
 * 메뉴 생성과 트리 배치는 SiteProvisioningInterface를 사용한다. 이 계약은
 * 이미 생성된 메뉴의 조회·수정·삭제만 제공하며, 모든 변경에 도메인 소유권을
 * 강제한다.
 */
interface MenuManagementInterface
{
    /** @return list<MenuDescriptor> */
    public function findProviderMenus(
        int $domainId,
        string $providerType,
        string $providerName
    ): array;

    /** @return list<MenuDescriptor> */
    public function findMenusByUrlPrefix(int $domainId, string $urlPrefix): array;

    public function updateMenu(int $domainId, int $itemId, array $values): Result;

    public function removeMenu(int $domainId, int $itemId): Result;
}
