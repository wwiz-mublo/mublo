<?php
declare(strict_types=1);

namespace Mublo\Plugin\EmailNotify;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'EmailNotify';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        $event->addPluginMenu('이메일 알림', null, [
            [
                'label' => '발신 설정',
                'url' => '/admin/email-notify/settings',
                'code' => '001',
            ],
            [
                'label' => '템플릿 관리',
                'url' => '/admin/email-notify/templates',
                'code' => '002',
            ],
            [
                'label' => '발송 이력',
                'url' => '/admin/email-notify/history',
                'code' => '003',
            ],
        ]);
    }
}
