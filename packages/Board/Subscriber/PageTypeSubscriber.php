<?php

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Rendering\PageTypeResolveEvent;

/**
 * 페이지 타입 판별 구독자
 *
 * Board 패키지의 뷰 경로를 인식하여 페이지 타입을 결정
 */
class PageTypeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PageTypeResolveEvent::class => 'onPageTypeResolve',
        ];
    }

    public function onPageTypeResolve(PageTypeResolveEvent $event): void
    {
        $viewPath = $event->getViewPath();

        if (str_starts_with($viewPath, 'Board/')) {
            $event->setPageType(
                str_contains($viewPath, 'View') ? 'board_view' : 'board_list'
            );
        }

        if (str_starts_with($viewPath, 'Community/')) {
            $event->setPageType('community');
        }
    }
}
