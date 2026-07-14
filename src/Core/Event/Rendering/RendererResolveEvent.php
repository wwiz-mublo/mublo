<?php

namespace Mublo\Core\Event\Rendering;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Rendering\ViewRendererInterface;

/**
 * RendererResolveEvent
 *
 * Application이 ViewResponse를 렌더링하기 직전에 발행.
 * Package/Plugin이 커스텀 렌더러를 지정할 수 있는 확장점.
 *
 * - setRenderer()로 커스텀 렌더러를 지정하면 기본 Admin/Front 분기를 대체
 * - 아무도 setRenderer()를 호출하지 않으면 기존 isAdmin() 분기 로직 유지
 *
 * 사용 예:
 * ```php
 * // Subscriber/RenderResolveSubscriber.php
 * class RenderResolveSubscriber implements EventSubscriberInterface
 * {
 *     public static function getSubscribedEvents(): array
 *     {
 *         return [RendererResolveEvent::class => 'onRendererResolve'];
 *     }
 *
 *     public function onRendererResolve(RendererResolveEvent $event): void
 *     {
 *         $path = $event->getContext()->getRequest()->getPath();
 *         if (str_starts_with($path, '/dashboard')) {
 *             $event->setRenderer($this->dashboardRenderer);
 *             // stopPropagation()은 setRenderer() 내부에서 자동 호출
 *         }
 *     }
 * }
 * ```
 *
 * @see ViewRendererInterface 렌더러 인터페이스
 */
class RendererResolveEvent extends AbstractEvent
{
    private ?ViewRendererInterface $renderer = null;

    public function __construct(
        private readonly ViewResponse $response,
        private readonly Context $context
    ) {}

    public function getResponse(): ViewResponse
    {
        return $this->response;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * 커스텀 렌더러 지정
     *
     * 호출하면 기본 Admin/Front 분기를 대체하고,
     * 이벤트 전파를 자동 중단하여 렌더러 충돌을 방지한다.
     * (렌더러는 항상 하나만 선택되어야 한다)
     */
    public function setRenderer(ViewRendererInterface $renderer): void
    {
        $this->renderer = $renderer;
        $this->stopPropagation();
    }

    /**
     * 지정된 커스텀 렌더러 반환
     *
     * null이면 기존 기본 분기(Admin/Front) 사용
     */
    public function getRenderer(): ?ViewRendererInterface
    {
        return $this->renderer;
    }
}
