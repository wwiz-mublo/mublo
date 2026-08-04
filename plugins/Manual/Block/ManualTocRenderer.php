<?php
declare(strict_types=1);

namespace Mublo\Plugin\Manual\Block;

use Mublo\Contract\Manual\ManualPageNode;
use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Contract\Block\BlockColumnView;

final class ManualTocRenderer extends AbstractManualRenderer implements RendererInterface
{
    protected function getSkinType(): string
    {
        return 'manual_toc';
    }

    public function render(BlockColumnView $column): string
    {
        $reference = $this->selectedReferences($column->getContentItems())[0] ?? '';
        $book = preg_match('/^[a-z0-9-]+$/', $reference) === 1
            ? $this->manualService->getBookBySlug($column->getDomainId(), $reference)
            : null;

        $config = $column->getContentConfig() ?? [];
        $maxDepth = max(0, min(12, (int) ($config['max_depth'] ?? 0)));
        $config['show_description'] = filter_var($config['show_description'] ?? true, FILTER_VALIDATE_BOOL);
        $config['show_root_link'] = filter_var($config['show_root_link'] ?? true, FILTER_VALIDATE_BOOL);
        [$pcCount, $moCount] = $this->responsiveCounts($config, 12);

        $entries = [];
        if ($book !== null) {
            $this->flatten($entries, $this->manualService->getPageTree($book->bookId), $maxDepth);
        }

        return $this->renderSkin($column, $column->getContentSkin() ?: 'basic', [
            'book' => $book,
            'entries' => $entries,
            'config' => $config,
            'pcCount' => $pcCount,
            'moCount' => $moCount,
        ]);
    }

    /**
     * @param array<int, array{node:ManualPageNode,depth:int}> $entries
     * @param list<ManualPageNode> $nodes
     */
    private function flatten(array &$entries, array $nodes, int $maxDepth, int $depth = 0): void
    {
        foreach ($nodes as $node) {
            if ($maxDepth > 0 && $depth >= $maxDepth) {
                continue;
            }
            $entries[] = ['node' => $node, 'depth' => $depth];
            $this->flatten($entries, $node->children, $maxDepth, $depth + 1);
        }
    }
}
