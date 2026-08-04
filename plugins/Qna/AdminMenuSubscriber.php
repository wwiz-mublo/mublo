<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * QnA 플러그인 관리자 메뉴 Subscriber
 */
class AdminMenuSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_NAME = 'Qna';

    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('plugin', self::PLUGIN_NAME);

        // 아이콘은 null → manifest.json icon 단일 소스 사용
        $event->addPluginMenu('Q&A 관리', null, [
            ['label' => 'Q&A 관리', 'url' => '/admin/qna', 'code' => '001'],
        ]);
    }
}
