<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Packages\Shop\Event\ExhibitionCreatedEvent;
use Mublo\Packages\Shop\Event\ExhibitionUpdatedEvent;
use Mublo\Packages\Shop\Event\ExhibitionDeletedEvent;
use Mublo\Packages\Shop\Helper\ExhibitionUrl;

/**
 * 기획전 → 메뉴 아이템 자동 등록/수정/삭제
 *
 * 기획전 생성 → menu_items에 자동 등록 (menu_tree 배치는 관리자 수동)
 * 기획전 수정 → 기존 menu_item의 라벨·URL 갱신 (있을 때만; 관리자가 지운 메뉴는 되살리지 않음)
 * 기획전 삭제 → 해당 menu_item 자동 삭제
 */
class ExhibitionMenuSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SiteProvisioningInterface $menuProvisioning,
        private MenuManagementInterface $menuManagement
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ExhibitionCreatedEvent::class => 'onCreated',
            ExhibitionUpdatedEvent::class => 'onUpdated',
            ExhibitionDeletedEvent::class => 'onDeleted',
        ];
    }

    public function onCreated(ExhibitionCreatedEvent $event): void
    {
        $this->menuProvisioning->createMenuItem($event->getDomainId(), [
            'label'         => $event->getTitle(),
            'url'           => $event->getUrl(),
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);
    }

    /**
     * 기획전 수정 → 변경 전 URL로 기존 메뉴 아이템을 찾아 라벨·URL 갱신.
     * 매칭되는 메뉴가 없으면(관리자가 이미 삭제) 아무것도 하지 않는다 — 재생성 금지.
     */
    public function onUpdated(ExhibitionUpdatedEvent $event): void
    {
        $newUrl = $event->getNewUrl();

        // 변경 전 URL 후보: 슬러그 기반 + id 기반(슬러그 지원 이전에 등록된 레거시 메뉴 포함)
        $oldUrls = [
            $event->getOldUrl(),
            ExhibitionUrl::detail($event->getExhibitionId()),
        ];

        $items = $this->menuManagement->findProviderMenus(
            $event->getDomainId(),
            'package',
            'Shop'
        );

        foreach ($items as $item) {
            if (in_array($item->url, $oldUrls, true)) {
                $this->menuManagement->updateMenu($event->getDomainId(), $item->itemId, [
                    'label' => $event->getTitle(),
                    'url'   => $newUrl,
                ]);
            }
        }
    }

    public function onDeleted(ExhibitionDeletedEvent $event): void
    {
        // 삭제 대상 URL 후보: 슬러그 기반 + id 기반(레거시 메뉴 포함)
        $targetUrls = [
            $event->getUrl(),
            ExhibitionUrl::detail($event->getExhibitionId()),
        ];

        $items = $this->menuManagement->findProviderMenus(
            $event->getDomainId(),
            'package',
            'Shop'
        );

        foreach ($items as $item) {
            if (in_array($item->url, $targetUrls, true)) {
                $this->menuManagement->removeMenu($event->getDomainId(), $item->itemId);
            }
        }
    }
}
