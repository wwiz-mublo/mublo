<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

final class AdminMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AdminMenuBuildingEvent::class => 'onAdminMenuBuilding'];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('package', 'AiAssistant');
        $event->addPackageMenu('Mublo AI 비서', null, [[
            'label' => '운영 대시보드',
            'url' => '/admin/mublo-ai/dashboard',
            'code' => '000',
        ]]);
    }
}
