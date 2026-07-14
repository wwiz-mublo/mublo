<?php

namespace Mublo\Core\Event\Rendering;

use Mublo\Core\Event\AbstractEvent;

/**
 * 페이지 타입 판별 이벤트
 *
 * Core FrontViewRenderer에서 발행 → Package가 구독하여 자체 페이지 타입 등록
 * 첫 응답자가 결정 (stopPropagation)
 */
class PageTypeResolveEvent extends AbstractEvent
{
    private ?string $pageType = null;

    public function __construct(
        private readonly string $viewPath,
    ) {}

    public function getViewPath(): string { return $this->viewPath; }

    public function setPageType(string $type): void
    {
        $this->pageType = $type;
        $this->stopPropagation();
    }

    public function getPageType(): ?string { return $this->pageType; }
}
