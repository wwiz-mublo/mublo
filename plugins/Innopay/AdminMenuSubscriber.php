<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay;

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
        $event->setSource('plugin', 'Innopay');
        $event->addPluginMenu('이노페이', null, [
            ['label' => '결제 설정', 'url' => '/admin/innopay/settings', 'code' => '001'],
            ['label' => '결제 로그', 'url' => '/admin/innopay/logs', 'code' => '002'],
        ]);
    }
}
