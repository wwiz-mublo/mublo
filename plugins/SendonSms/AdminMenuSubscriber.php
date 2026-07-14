<?php

namespace Mublo\Plugin\SendonSms;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'SendonSms';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        $event->addPluginMenu('센드온 SMS', null, [
            [
                'label' => '연동 설정',
                'url' => '/admin/sendon-sms/settings',
                'code' => '001',
            ],
            [
                'label' => '채널/템플릿 관리',
                'url' => '/admin/sendon-sms/channels',
                'code' => '002',
            ],
            [
                'label' => '발송 이력',
                'url' => '/admin/sendon-sms/history',
                'code' => '003',
            ],
        ]);
    }
}
