<?php
namespace Mublo\Plugin\MemberPoint;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * MemberPoint 플러그인 관리자 메뉴 Subscriber
 *
 * 회원 관리 메뉴(003)에 회원포인트 설정 서브메뉴 추가
 */
class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'MemberPoint';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        // 회원 관리(003) > 회원 관리(003_001) 뒤에 삽입
        $event->insertSubmenuAfter('003', '003_001', [
            'label' => '회원포인트 설정',
            'url'   => '/admin/member-point/member-settings',
            'code'  => '002',  // → 003_P_MemberPoint_002
        ]);
    }
}
