<?php
declare(strict_types=1);

namespace Mublo\Service\Menu;

use Mublo\Contract\Menu\MenuDescriptor;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Core\Result\Result;
use Mublo\Repository\Menu\MenuItemRepository;

final class MenuManagementGateway implements MenuManagementInterface
{
    public function __construct(
        private MenuService $menuService,
        private MenuItemRepository $menuItems
    ) {
    }

    public function findProviderMenus(
        int $domainId,
        string $providerType,
        string $providerName
    ): array {
        return $this->map($this->menuItems->findByProvider($domainId, $providerType, $providerName));
    }

    public function findMenusByUrlPrefix(int $domainId, string $urlPrefix): array
    {
        return $this->map($this->menuItems->findByUrlPrefix($domainId, $urlPrefix));
    }

    public function updateMenu(int $domainId, int $itemId, array $values): Result
    {
        return $this->menuService->updateItem($itemId, $values, $domainId);
    }

    public function removeMenu(int $domainId, int $itemId): Result
    {
        return $this->menuService->deleteItem($itemId, $domainId);
    }

    /** @return list<MenuDescriptor> */
    private function map(array $rows): array
    {
        return array_map(
            static fn (array $row): MenuDescriptor => new MenuDescriptor(
                (int) $row['item_id'],
                (string) $row['menu_code'],
                (string) $row['label'],
                isset($row['url']) ? (string) $row['url'] : null
            ),
            array_values($rows)
        );
    }
}
