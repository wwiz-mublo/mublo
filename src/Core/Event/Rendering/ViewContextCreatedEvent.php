<?php

namespace Mublo\Core\Event\Rendering;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Rendering\ViewContext;

/**
 * ViewContextCreatedEvent
 *
 * FrontViewRenderer에서 ViewContext 생성 직후 발행.
 * Plugin/Package가 자체 ViewHelper를 등록할 수 있는 확장점.
 *
 * 사용 예 (ShopProvider):
 * ```php
 * $eventDispatcher->addSubscriber(new ShopViewSubscriber());
 *
 * // ShopViewSubscriber
 * public static function getSubscribedEvents(): array
 * {
 *     return [ViewContextCreatedEvent::class => 'onViewContextCreated'];
 * }
 *
 * public function onViewContextCreated(ViewContextCreatedEvent $event): void
 * {
 *     $event->getViewContext()->setHelper('shop', new ShopViewHelper());
 * }
 * ```
 *
 * 결과: Shop 스킨에서 $this->shop->price(10000) 사용 가능
 */
class ViewContextCreatedEvent extends AbstractEvent
{
    public function __construct(
        private readonly ViewContext $viewContext
    ) {}

    public function getViewContext(): ViewContext
    {
        return $this->viewContext;
    }
}
