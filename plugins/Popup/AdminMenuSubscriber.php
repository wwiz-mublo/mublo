<?php
declare(strict_types=1);

namespace Mublo\Plugin\Popup;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'Popup';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        $event->addPluginMenu('팝업 관리', null, [
            [
                'label' => '팝업 목록',
                'url' => '/admin/popup/list',
                'code' => '001',
            ],
        ]);
    }
}
