<?php
namespace Mublo\Plugin\SnsLogin;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'SnsLogin';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        // 회원 관리(003) 하위에 추가
        $event->addSubmenusTo('003', [
            [
                'label' => 'SNS 로그인 설정',
                'url'   => '/admin/sns-login/settings',
                'code'  => '001',  // → P_SnsLogin_001
            ],
            [
                'label' => 'SNS 연동 내역',
                'url'   => '/admin/sns-login/accounts',
                'code'  => '002',  // → P_SnsLogin_002
            ],
        ]);
    }
}
