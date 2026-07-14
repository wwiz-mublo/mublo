<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Packages\Shop\Event\CategoryUpdatedEvent;
use Mublo\Packages\Shop\Event\CategoryDeletedEvent;
use Mublo\Packages\Shop\Helper\CategoryUrl;
use Mublo\Service\Menu\MenuService;
use Mublo\Repository\Menu\MenuItemRepository;

/**
 * 카테고리 → 메뉴 아이템 동기화 (수정/삭제)
 *
 * 카테고리 이름 변경 → 메뉴 라벨 갱신 (있을 때만)
 * 카테고리 삭제 → 메뉴 아이템 제거 (있을 때만)
 *
 * 생성 자동등록은 하지 않는다 — 관리자가 고른 카테고리만 메뉴로 올리는 수동 모델이므로,
 * 관리자가 지운 메뉴가 카테고리 수정만으로 되살아나선 안 된다.
 */
class CategoryMenuSubscriber implements EventSubscriberInterface
{
    private DependencyContainer $container;

    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CategoryUpdatedEvent::class => 'onUpdated',
            CategoryDeletedEvent::class => 'onDeleted',
        ];
    }

    /** 이름 변경 → 해당 카테고리 메뉴 아이템 라벨 갱신 (있을 때만) */
    public function onUpdated(CategoryUpdatedEvent $event): void
    {
        $menuService = $this->container->get(MenuService::class);
        $menuItemRepository = $this->container->get(MenuItemRepository::class);

        $url = CategoryUrl::menu($event->getCategoryCode());

        $items = $menuItemRepository->findByUrlPrefix($event->getDomainId(), CategoryUrl::MENU_PREFIX);
        foreach ($items as $item) {
            if (($item['url'] ?? '') === $url) {
                $menuService->updateItem((int) $item['item_id'], [
                    'label' => $event->getCategoryName(),
                ], $event->getDomainId());
            }
        }
    }

    /** 카테고리 삭제 → 해당 메뉴 아이템 제거 (있을 때만; 관리자가 이미 지웠으면 no-op) */
    public function onDeleted(CategoryDeletedEvent $event): void
    {
        $menuService = $this->container->get(MenuService::class);
        $menuItemRepository = $this->container->get(MenuItemRepository::class);

        $url = CategoryUrl::menu($event->getCategoryCode());

        $items = $menuItemRepository->findByUrlPrefix($event->getDomainId(), CategoryUrl::MENU_PREFIX);
        foreach ($items as $item) {
            if (($item['url'] ?? '') === $url) {
                $menuService->deleteItem((int) $item['item_id'], $event->getDomainId());
            }
        }
    }
}
