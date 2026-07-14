<?php

namespace Mublo\Packages\Shop;

use Mublo\Contract\Category\CategoryProviderInterface;
use Mublo\Packages\Shop\Service\CategoryService;

/**
 * Shop 패키지 카테고리 Provider
 *
 * CategoryService의 트리 데이터를 CategoryProviderInterface 규격으로 변환한다.
 */
class ShopCategoryProvider implements CategoryProviderInterface
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    public function getTree(int $domainId, ?int $depth = null): array
    {
        $hierarchy = $this->categoryService->getTreeHierarchy($domainId);

        return $this->normalize($hierarchy, $depth, 1);
    }

    /**
     * Shop 트리 노드 → 표준 규격 변환 (재귀, depth 제한)
     */
    private function normalize(array $nodes, ?int $maxDepth, int $currentDepth): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (empty($node['is_active'])) {
                continue;
            }

            $children = [];
            if (!empty($node['children']) && ($maxDepth === null || $currentDepth < $maxDepth)) {
                $children = $this->normalize($node['children'], $maxDepth, $currentDepth + 1);
            }

            $result[] = [
                'icon'     => $node['image'] ?? '',
                'code'     => $node['category_code'] ?? '',
                'label'    => $node['name'] ?? '',
                'link'     => '/shop/category/' . ($node['category_code'] ?? ''),
                'children' => $children,
            ];
        }

        return $result;
    }
}
