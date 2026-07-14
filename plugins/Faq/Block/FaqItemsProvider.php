<?php
namespace Mublo\Plugin\Faq\Block;

use Mublo\Core\Block\BlockItemsProviderInterface;
use Mublo\Plugin\Faq\Repository\FaqRepository;

/**
 * FaqItemsProvider
 *
 * 블록 DualListbox에 FAQ 카테고리 목록을 제공
 * 관리자가 카테고리를 선택하면 해당 카테고리의 FAQ만 블록에 표시
 */
class FaqItemsProvider implements BlockItemsProviderInterface
{
    private FaqRepository $faqRepository;

    public function __construct(FaqRepository $faqRepository)
    {
        $this->faqRepository = $faqRepository;
    }

    public function getItems(int $domainId): array
    {
        $categories = $this->faqRepository->findCategories($domainId);

        return array_map(fn($cat) => [
            'id' => $cat['category_slug'],
            'label' => $cat['category_name'],
        ], $categories);
    }
}
