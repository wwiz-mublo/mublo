<?php
declare(strict_types=1);

namespace Mublo\Plugin\Widget;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Rendering\FrontFootRenderEvent;
use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Plugin\Widget\Service\WidgetService;

class FrontRenderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WidgetService $widgetService,
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
        $domainId = $event->getDomainId();
        $data = $this->widgetService->getActiveWidgets($domainId);
        $config = $data['config'];

        $html = '';

        // 각 포지션별 스킨 렌더링
        foreach (['left', 'right', 'mobile'] as $position) {
            if (empty($data[$position])) {
                continue;
            }

            $items = $data[$position];
            $skinPath = $this->widgetService->getSkinPath($domainId, $position);

            if (!file_exists($skinPath)) {
                continue;
            }

            $html .= $this->viewRuntime->render($skinPath, [
                'items' => $items,
                'position' => $position,
                'config' => $config,
            ]);
        }

        if ($html !== '') {
            $event->addHtml($html);
        }
    }
}
