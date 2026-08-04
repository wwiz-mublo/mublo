<?php
declare(strict_types=1);

namespace Mublo\Plugin\Popup;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Rendering\FrontFootRenderEvent;
use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Plugin\Popup\Service\PopupService;

class FrontRenderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PopupService $popupService,
        private FrontViewRuntime $viewRuntime
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FrontFootRenderEvent::class => 'onFrontFootRender',
        ];
    }

    public function onFrontFootRender(FrontFootRenderEvent $event): void
    {
        // 블록 에디터 미리보기에서는 팝업이 편집 화면을 덮으면 안 된다(블록 에디터 설계 5.3)
        if (is_editor_preview()) {
            return;
        }

        $skinPath = $this->popupService->getSkinPath($event->getDomainId());

        if (!file_exists($skinPath)) {
            return;
        }

        $html = $this->viewRuntime->render($skinPath);

        $event->addHtml($html);
    }
}
