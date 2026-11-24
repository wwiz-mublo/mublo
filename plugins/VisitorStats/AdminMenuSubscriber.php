<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'VisitorStats';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        $event->addMenu('plugin', [
            'label' => '방문통계',
            'url' => '/admin/visitor-stats/dashboard',
            'icon' => 'bi-bar-chart-line',
            'code' => '001',
        ]);
    }
}
