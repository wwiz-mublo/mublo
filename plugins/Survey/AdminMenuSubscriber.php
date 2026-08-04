<?php
declare(strict_types=1);
namespace Mublo\Plugin\Survey;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'Survey';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);
        $event->addPluginMenu('설문 관리', null, [
            [
                'label' => '설문 관리',
                'url'   => '/admin/survey/surveys',
                'code'  => '101',
            ],
        ]);
    }
}
