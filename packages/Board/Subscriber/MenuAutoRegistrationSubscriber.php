<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Packages\Board\Event\BoardConfigCreatedEvent;
use Mublo\Packages\Board\Event\BoardConfigDeletedEvent;

/**
 * 프론트 메뉴 자동 등록/삭제 구독자
 *
 * 게시판 생성 → menu_items에 자동 등록 (menu_tree 배치는 관리자 수동)
 * 게시판 삭제 → 해당 menu_item 자동 삭제
 *
 * 등록: ServiceProvider.bootSubscribers()
 *
 */
class MenuAutoRegistrationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SiteProvisioningInterface $menuProvisioning,
        private MenuManagementInterface $menuManagement
    ) {
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
        $this->menuProvisioning->createMenuItem($event->getDomainId(), [
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
        $targetUrl = '/board/' . $event->getBoardSlug();

        // provider_type='package', provider_name='Board'인 메뉴 중 URL 매칭
        $items = $this->menuManagement->findProviderMenus(
            $event->getDomainId(),
            'package',
            'Board'
        );

        foreach ($items as $item) {
            if ($item->url === $targetUrl) {
                $this->menuManagement->removeMenu($event->getDomainId(), $item->itemId);
                break;
            }
        }
    }
}
