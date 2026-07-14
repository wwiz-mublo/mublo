<?php

namespace Mublo\Plugin\Manual\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Entity\Block\BlockColumn;

final class ManualBooksRenderer extends AbstractManualRenderer implements RendererInterface
{
    protected function getSkinType(): string
    {
        return 'manual_books';
    }

    public function render(BlockColumn $column): string
    {
        $books = $this->manualService->getActiveBooks($column->getDomainId());
        $selected = $this->selectedReferences($column->getContentItems());

        if ($selected !== []) {
            $bySlug = [];
            foreach ($books as $book) {
                $bySlug[$book->slug] = $book;
            }
            $books = array_values(array_filter(array_map(
                static fn (string $slug) => $bySlug[$slug] ?? null,
                $selected
            )));
        }

        $config = $column->getContentConfig() ?? [];
        $layout = (string) ($config['layout'] ?? 'grid');
        $config['layout'] = in_array($layout, ['grid', 'list'], true) ? $layout : 'grid';
        $config['show_description'] = filter_var($config['show_description'] ?? true, FILTER_VALIDATE_BOOL);
        $config['show_link'] = filter_var($config['show_link'] ?? true, FILTER_VALIDATE_BOOL);
        [$pcCount, $moCount] = $this->responsiveCounts($config);

        return $this->renderSkin($column, $column->getContentSkin() ?: 'basic', [
            'books' => $books,
            'config' => $config,
            'pcCount' => $pcCount,
            'moCount' => $moCount,
        ]);
    }
}
