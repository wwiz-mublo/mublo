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
        $event->addPackageMenu('Mublo AI 비서', null, [
            [
                'label' => '운영 대시보드',
                'url' => '/admin/mublo-ai/dashboard',
                'code' => '000',
            ],
            [
                'label' => '고객·동기화',
                'url' => '/admin/mublo-ai/customers',
                'code' => '010',
            ],
            [
                'label' => 'AI 분석',
                'url' => '/admin/mublo-ai/analysis',
                'code' => '020',
            ],
            [
                'label' => '일정·FCM',
                'url' => '/admin/mublo-ai/schedules',
                'code' => '030',
            ],
            [
                'label' => '앱·기기',
                'url' => '/admin/mublo-ai/devices',
                'code' => '040',
            ],
            [
                'label' => 'Worker',
                'url' => '/admin/mublo-ai/workers',
                'code' => '050',
            ],
            [
                'label' => '회사·구독',
                'url' => '/admin/mublo-ai/company',
                'code' => '060',
            ],
            [
                'label' => '플랫폼 통합 현황',
                'url' => '/admin/mublo-ai/platform',
                'code' => '900',
                'super_only' => true,
            ],
            [
                'label' => '플랫폼 회사·회원',
                'url' => '/admin/mublo-ai/platform/companies',
                'code' => '910',
                'super_only' => true,
            ],
            [
                'label' => '플랫폼 발신 모니터',
                'url' => '/admin/mublo-ai/platform/delivery',
                'code' => '920',
                'super_only' => true,
            ],
            [
                'label' => '플랫폼 분석 인프라',
                'url' => '/admin/mublo-ai/platform/infrastructure',
                'code' => '930',
                'super_only' => true,
            ],
        ]);
    }
}
