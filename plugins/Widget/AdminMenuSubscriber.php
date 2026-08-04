<?php
declare(strict_types=1);

namespace Mublo\Plugin\Widget;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'Widget';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        $event->addPluginMenu('위젯 관리', null, [
            [
                'label' => '위젯 목록',
                'url' => '/admin/widget/list',
                'code' => '001',
            ],
        ]);
    }
}
