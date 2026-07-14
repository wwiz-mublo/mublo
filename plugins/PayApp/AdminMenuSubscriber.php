<?php

namespace Mublo\Plugin\PayApp;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', 'PayApp');
        $event->addPluginMenu('페이앱', null, [
            ['label' => '결제 설정', 'url' => '/admin/pay-app/settings', 'code' => '001'],
        ]);
    }
}
