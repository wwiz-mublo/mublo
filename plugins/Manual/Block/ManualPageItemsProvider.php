<?php

namespace Mublo\Plugin\Manual\Block;

use Mublo\Contract\Manual\ManualPageNode;
use Mublo\Core\Block\BlockItemsProviderInterface;
use Mublo\Plugin\Manual\Service\ManualService;

final class ManualPageItemsProvider implements BlockItemsProviderInterface
{
    public function __construct(private readonly ManualService $manualService)
    {
    }

    public function getItems(int $domainId): array
    {
        $items = [];
        foreach ($this->manualService->getActiveBooks($domainId) as $book) {
            $this->appendNodes($items, $book->slug, $book->title, $this->manualService->getPageTree($book->bookId));
        }
        return $items;
    }

    /**
     * @param array<int, array{id:string,label:string}> $items
     * @param list<ManualPageNode> $nodes
     * @param list<string> $parents
     */
    private function appendNodes(
        array &$items,
        string $bookSlug,
        string $bookTitle,
        array $nodes,
        array $parents = [],
    ): void {
        foreach ($nodes as $node) {
            $path = [...$parents, $node->title];
            $items[] = [
                'id' => $bookSlug . '/' . $node->slug,
                'label' => $bookTitle . ' › ' . implode(' › ', $path),
            ];
            $this->appendNodes($items, $bookSlug, $bookTitle, $node->children, $path);
        }
    }
}
