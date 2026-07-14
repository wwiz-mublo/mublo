<?php

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Board\Event\BoardConfigCreatedEvent;
use Mublo\Packages\Board\Event\BoardConfigDeletedEvent;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Service\Menu\MenuService;
use Mublo\Repository\Menu\MenuItemRepository;

/**
 * 프론트 메뉴 자동 등록/삭제 구독자
 *
 * 게시판 생성 → menu_items에 자동 등록 (menu_tree 배치는 관리자 수동)
 * 게시판 삭제 → 해당 menu_item 자동 삭제
 *
 * 등록: ServiceProvider.bootSubscribers()
 *
 * Note: boot() 시점에는 Context가 없어 MenuService를 즉시 resolve 불가.
 *       DependencyContainer를 주입받아 이벤트 발생 시점(run 이후)에 lazy resolve.
 */
class MenuAutoRegistrationSubscriber implements EventSubscriberInterface
{
    private DependencyContainer $container;

    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BoardConfigCreatedEvent::class => 'onBoardCreated',
            BoardConfigDeletedEvent::class => 'onBoardDeleted',
        ];
    }

    /**
     * 게시판 생성 → 메뉴 아이템 자동 등록
     */
    public function onBoardCreated(BoardConfigCreatedEvent $event): void
    {
        $menuService = $this->container->get(MenuService::class);

        $menuService->createItem($event->getDomainId(), [
            'label' => $event->getBoardName(),
            'url' => '/board/' . $event->getBoardSlug(),
            'provider_type' => 'package',
            'provider_name' => 'Board',
        ]);
    }

    /**
     * 게시판 삭제 → 해당 메뉴 아이템 자동 삭제
     */
    public function onBoardDeleted(BoardConfigDeletedEvent $event): void
    {
        $menuService = $this->container->get(MenuService::class);
        $menuItemRepository = $this->container->get(MenuItemRepository::class);

        $targetUrl = '/board/' . $event->getBoardSlug();

        // provider_type='package', provider_name='Board'인 메뉴 중 URL 매칭
        $items = $menuItemRepository->findByProvider(
            $event->getDomainId(),
            'package',
            'Board'
        );

        foreach ($items as $item) {
            if (($item['url'] ?? '') === $targetUrl) {
                // MenuService.deleteItem이 tree + unique_codes 정리까지 처리
                $menuService->deleteItem((int) $item['item_id']);
                break;
            }
        }
    }
}
