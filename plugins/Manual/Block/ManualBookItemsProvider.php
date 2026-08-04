<?php
declare(strict_types=1);

namespace Mublo\Plugin\Manual\Block;

use Mublo\Core\Block\BlockItemsProviderInterface;
use Mublo\Plugin\Manual\Service\ManualService;

final class ManualBookItemsProvider implements BlockItemsProviderInterface
{
    public function __construct(private readonly ManualService $manualService)
    {
    }

    public function getItems(int $domainId): array
    {
        return array_map(
            static fn ($book): array => ['id' => $book->slug, 'label' => $book->title],
            $this->manualService->getActiveBooks($domainId)
        );
    }
}
