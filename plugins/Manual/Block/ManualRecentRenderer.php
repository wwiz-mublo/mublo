<?php

namespace Mublo\Plugin\Manual\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Entity\Block\BlockColumn;

final class ManualRecentRenderer extends AbstractManualRenderer implements RendererInterface
{
    protected function getSkinType(): string
    {
        return 'manual_recent';
    }

    public function render(BlockColumn $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $layout = (string) ($config['layout'] ?? 'list');
        $config['layout'] = in_array($layout, ['list', 'cards'], true) ? $layout : 'list';
        $config['show_book_title'] = filter_var($config['show_book_title'] ?? true, FILTER_VALIDATE_BOOL);
        $config['show_updated_at'] = filter_var($config['show_updated_at'] ?? true, FILTER_VALIDATE_BOOL);
        $config['show_excerpt'] = filter_var($config['show_excerpt'] ?? false, FILTER_VALIDATE_BOOL);
        $config['excerpt_length'] = max(40, min(1000, (int) ($config['excerpt_length'] ?? 160)));
        [$pcCount, $moCount] = $this->responsiveCounts($config, 6);

        $items = $this->manualService->getRecentPages(
            $column->getDomainId(),
            $this->selectedReferences($column->getContentItems()),
            max($pcCount, $moCount)
        );

        $excerpts = [];
        foreach ($items as $item) {
            $excerpts[$item->pageId] = $this->excerpt($item->content, $config['excerpt_length']);
        }

        return $this->renderSkin($column, $column->getContentSkin() ?: 'basic', [
            'items' => $items,
            'excerpts' => $excerpts,
            'config' => $config,
            'pcCount' => $pcCount,
            'moCount' => $moCount,
        ]);
    }
}
