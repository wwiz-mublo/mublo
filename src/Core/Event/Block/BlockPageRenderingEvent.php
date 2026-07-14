<?php

namespace Mublo\Core\Event\Block;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Block\BlockPage;

/**
 * BlockPageRenderingEvent
 *
 * 블록 페이지(/p/코드) 렌더링 시 패키지가 추가 HTML을 주입할 수 있는 확장점.
 * PageController.view()에서 발행.
 *
 * 사용 예:
 * ```php
 * public function onBlockPageRendering(BlockPageRenderingEvent $event): void
 * {
 *     $config = $event->getPage()->getPageConfig();
 *     if (empty($config['show_company_info'])) return;
 *
 *     $event->addHtml('<footer class="page-company-info">...</footer>', 900);
 * }
 * ```
 */
class BlockPageRenderingEvent extends AbstractEvent
{
    /** @var array<int, array{html: string, order: int}> */
    private array $htmlBlocks = [];

    public function __construct(
        private readonly BlockPage $page,
        private readonly Context $context
    ) {}

    public function getPage(): BlockPage
    {
        return $this->page;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * HTML 블록 추가
     *
     * @param string $html 주입할 HTML
     * @param int $order 정렬 순서 (낮을수록 먼저 표시)
     */
    public function addHtml(string $html, int $order = 500): void
    {
        $this->htmlBlocks[] = ['html' => $html, 'order' => $order];
    }

    /**
     * order 정렬된 HTML 문자열 배열 반환
     *
     * @return string[]
     */
    public function getHtmlSorted(): array
    {
        $blocks = $this->htmlBlocks;
        usort($blocks, fn($a, $b) => $a['order'] <=> $b['order']);

        return array_column($blocks, 'html');
    }

    public function hasHtml(): bool
    {
        return !empty($this->htmlBlocks);
    }
}
