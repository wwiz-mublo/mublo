<?php

namespace Mublo\Core\Event\Rendering;

use Mublo\Core\Event\AbstractEvent;

/**
 * FrontFootRenderEvent
 *
 * FrontViewRenderer에서 Footer 출력 후, Foot(</body>) 출력 전 발행.
 * Plugin/Package가 프론트 HTML을 주입할 수 있는 확장점.
 *
 * 사용 예 (PopupProvider):
 * ```php
 * // PopupFrontSubscriber
 * public static function getSubscribedEvents(): array
 * {
 *     return [FrontFootRenderEvent::class => 'onFrontFootRender'];
 * }
 *
 * public function onFrontFootRender(FrontFootRenderEvent $event): void
 * {
 *     $event->addHtml($this->renderPopups());
 * }
 * ```
 */
class FrontFootRenderEvent extends AbstractEvent
{
    private array $htmlParts = [];

    public function __construct(
        private readonly int $domainId
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /**
     * HTML 조각 추가 (순서대로 출력)
     */
    public function addHtml(string $html): void
    {
        if ($html !== '') {
            $this->htmlParts[] = $html;
        }
    }

    /**
     * 수집된 HTML 반환
     */
    public function getHtml(): string
    {
        return implode("\n", $this->htmlParts);
    }
}
