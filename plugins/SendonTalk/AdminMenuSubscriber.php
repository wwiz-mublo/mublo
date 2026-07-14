<?php

namespace Mublo\Plugin\SendonTalk;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'SendonTalk';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);
        $event->addPluginMenu('센드온 알림톡', null, [
            ['label' => '연동 설정',       'url' => '/admin/sendon-talk/settings', 'code' => '001'],
            ['label' => '채널/템플릿 관리', 'url' => '/admin/sendon-talk/channels', 'code' => '002'],
            ['label' => '발송 이력',       'url' => '/admin/sendon-talk/history',  'code' => '003'],
        ]);
    }
}
