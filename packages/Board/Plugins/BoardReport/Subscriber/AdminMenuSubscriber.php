<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Plugins\BoardReport\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * 관리자 메뉴 — 부모 패키지(Mublo Board) 메뉴 아래에 종속으로 들어간다.
 * 패키지 루트 컨테이너 코드는 "K_{PackageName}" (AdminMenuBuildingEvent 참고).
 */
class AdminMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AdminMenuBuildingEvent::class => 'onAdminMenuBuilding'];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', 'BoardReport');

        $event->addSubmenuTo('K_Board', [
            'label' => '신고 관리',
            'url'   => '/admin/board/report/list',
            'code'  => '001',
        ]);
    }
}
