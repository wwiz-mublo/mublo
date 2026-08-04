<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * Board 패키지 관리자 메뉴 Subscriber
 *
 * Board는 기본 패키지(default: true)이므로 platform 그룹에 배치.
 * 블록 관리(004) 아래에 삽입.
 *
 * 코드 규칙: K_Board_{code}
 * (core source로 등록하되 K_ prefix를 수동 적용하여 패키지 코드 체계 유지)
 */
class AdminMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        $event->setSource('package', 'Board');

        // 아이콘은 manifest.json(icon) 단일 소스 — null 전달로 자동 해석
        $event->addPackageMenu('Mublo Board', null, [
            ['label' => '대시보드',      'url' => '/admin/board/dashboard', 'code' => '000'],
            ['label' => '게시판 그룹',   'url' => '/admin/board/group',    'code' => '002'],
            ['label' => '카테고리 관리', 'url' => '/admin/board/category', 'code' => '003'],
            ['label' => '게시판 관리',   'url' => '/admin/board/config',   'code' => '004'],
            ['label' => '게시글 관리',   'url' => '/admin/board/article',  'code' => '005'],
            ['label' => '포인트 설정',   'url' => '/admin/board/point',    'code' => '006'],
        ]);
    }
}
