<?php

namespace Mublo\Plugin\Manual\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Entity\Block\BlockColumn;

final class ManualPageRenderer extends AbstractManualRenderer implements RendererInterface
{
    protected function getSkinType(): string
    {
        return 'manual_page';
    }

    public function render(BlockColumn $column): string
    {
        $reference = $this->selectedReferences($column->getContentItems())[0] ?? '';
        [$bookSlug, $pageSlug] = $this->parseReference($reference);

        $book = $bookSlug !== ''
            ? $this->manualService->getBookBySlug($column->getDomainId(), $bookSlug)
            : null;
        $page = $book !== null && $pageSlug !== ''
            ? $this->manualService->getPageBySlug($book->bookId, $pageSlug)
            : null;

        $config = $column->getContentConfig() ?? [];
        $displayMode = (string) ($config['display_mode'] ?? 'full');
        $config['display_mode'] = in_array($displayMode, ['full', 'excerpt', 'card'], true)
            ? $displayMode : 'full';
        $config['excerpt_length'] = max(40, min(1000, (int) ($config['excerpt_length'] ?? 240)));
        $config['show_book_title'] = filter_var($config['show_book_title'] ?? true, FILTER_VALIDATE_BOOL);
        $config['show_more_link'] = filter_var($config['show_more_link'] ?? true, FILTER_VALIDATE_BOOL);

        return $this->renderSkin($column, $column->getContentSkin() ?: 'basic', [
            'book' => $book,
            'page' => $page,
            'excerpt' => $page !== null ? $this->excerpt($page->content, $config['excerpt_length']) : '',
            'config' => $config,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function parseReference(string $reference): array
    {
        $parts = explode('/', $reference, 2);
        if (count($parts) !== 2
            || preg_match('/^[a-z0-9-]+$/', $parts[0]) !== 1
            || preg_match('/^[a-z0-9-]+$/', $parts[1]) !== 1
        ) {
            return ['', ''];
        }
        return [$parts[0], $parts[1]];
    }
}
