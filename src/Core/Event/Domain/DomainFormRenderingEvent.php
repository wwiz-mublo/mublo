<?php

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;

/**
 * DomainFormRenderingEvent
 *
 * 도메인 편집 폼 렌더링 시 발행.
 * Plugin/Package가 도메인 편집 폼에 추가 섹션(HTML)을 주입할 수 있는 확장점.
 *
 * 사용 예 (CloudflareDns Plugin):
 * ```php
 * public function onDomainFormRendering(DomainFormRenderingEvent $event): void
 * {
 *     $info = $this->connectService->getInfo($event->getDomainId());
 *     $event->addSection($this->renderCloudflareSection($info));
 * }
 * ```
 */
class DomainFormRenderingEvent extends AbstractEvent
{
    private array $sections = [];

    public function __construct(
        private readonly int $domainId,
        private readonly array $domainData = []
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getDomainData(): array
    {
        return $this->domainData;
    }

    /**
     * HTML 섹션 추가 (순서대로 출력)
     */
    public function addSection(string $html): void
    {
        if ($html !== '') {
            $this->sections[] = $html;
        }
    }

    /**
     * 수집된 섹션 반환
     */
    public function getSections(): array
    {
        return $this->sections;
    }
}
