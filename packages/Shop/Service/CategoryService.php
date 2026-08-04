<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Event\CategoryUpdatedEvent;
use Mublo\Packages\Shop\Event\CategoryDeletedEvent;

/**
 * Category Service
 *
 * 쇼핑몰 카테고리 비즈니스 로직
 * - 아이템 CRUD (shop_category_items)
 * - 트리 관리 (shop_category_tree)
 */
class CategoryService
{
    private CategoryRepository $categoryRepository;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        CategoryRepository $categoryRepository,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    // =============================================
    // 아이템 조회
    // =============================================

    /**
     * 도메인별 카테고리 아이템 목록
     */
    public function getItems(int $domainId): Result
    {
        $items = $this->categoryRepository->getItemsByDomain($domainId);

        return Result::success('', ['items' => $items]);
    }

    /**
     * 카테고리 아이템 단건 조회
     */
    public function getItem(int $domainId, int $categoryId): ?array
    {
        return $this->categoryRepository->findItemInDomain($domainId, $categoryId);
    }

    // =============================================
    // 아이템 CRUD
    // =============================================

    /**
     * 카테고리 아이템 생성
     */
    public function createItem(int $domainId, array $data): Result
    {
        if (empty($data['name'])) {
            return Result::failure('카테고리명을 입력해주세요.');
        }

        $categoryCode = $this->generateCode();

        // 중복 방지
        $retries = 0;
        while ($this->categoryRepository->existsByCategoryCode($domainId, $categoryCode)) {
            $categoryCode = $this->generateCode();
            if (++$retries >= 5) {
                return Result::failure('카테고리 코드 생성에 실패했습니다.');
            }
        }

        $itemData = [
            'domain_id' => $domainId,
            'category_code' => $categoryCode,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'allow_member_level' => (int) ($data['allow_member_level'] ?? 0),
            'allow_coupon' => isset($data['allow_coupon']) ? (int) (bool) $data['allow_coupon'] : 1,
            'is_adult' => isset($data['is_adult']) ? (int) (bool) $data['is_adult'] : 0,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ];

        $categoryId = $this->categoryRepository->createItem($itemData);

        if (!$categoryId) {
            return Result::failure('카테고리 생성에 실패했습니다.');
        }

        return Result::success('카테고리가 생성되었습니다.', [
            'category_id' => $categoryId,
            'category_code' => $categoryCode,
        ]);
    }

    /**
     * 카테고리 아이템 수정
     */
    public function updateItem(int $domainId, int $categoryId, array $data): Result
    {
        $item = $this->categoryRepository->findItemInDomain($domainId, $categoryId);
        if (!$item) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }
        if (array_key_exists('image', $data)) {
            $updateData['image'] = $data['image'];
        }
        if (isset($data['allow_member_level'])) {
            $updateData['allow_member_level'] = (int) $data['allow_member_level'];
        }
        if (isset($data['allow_coupon'])) {
            $updateData['allow_coupon'] = (int) (bool) $data['allow_coupon'];
        }
        if (isset($data['is_adult'])) {
            $updateData['is_adult'] = (int) (bool) $data['is_adult'];
        }
        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int) (bool) $data['is_active'];
        }

        if (!empty($updateData)) {
            $this->categoryRepository->updateItemInDomain($domainId, $categoryId, $updateData);
        }

        // 트리에서 이름이 변경되면 path_name도 갱신
        if (isset($data['name'])) {
            $this->categoryRepository->updatePathNameByCode(
                $domainId,
                $item['category_code'],
                $data['name']
            );

            // 메뉴 아이템 라벨 동기화 (있을 때만; 구독자가 처리)
            $this->dispatch(new CategoryUpdatedEvent(
                $domainId,
                $item['category_code'],
                (string) $data['name']
            ));
        }

        return Result::success('카테고리가 수정되었습니다.');
    }

    /**
     * 카테고리 아이템 삭제
     */
    public function deleteItem(int $domainId, int $categoryId): Result
    {
        $item = $this->categoryRepository->findItemInDomain($domainId, $categoryId);
        if (!$item) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        $categoryCode = $item['category_code'];

        // 트리에서 사용 중인지 확인
        $nodeCount = $this->categoryRepository->getNodeCountByCode($domainId, $categoryCode);
        if ($nodeCount > 0) {
            return Result::failure('트리에서 사용 중인 카테고리입니다. 트리에서 먼저 제거해주세요.');
        }

        // 상품 연결 확인
        $productCount = $this->categoryRepository->getProductCount($domainId, $categoryCode);
        if ($productCount > 0) {
            return Result::failure("상품({$productCount}개)이 연결되어 있어 삭제할 수 없습니다.");
        }

        $this->categoryRepository->deleteItemByCategoryCode($domainId, $categoryCode);

        // 메뉴 아이템 제거 (있을 때만; 구독자가 처리)
        $this->dispatch(new CategoryDeletedEvent($domainId, $categoryCode));

        return Result::success('카테고리가 삭제되었습니다.');
    }

    // =============================================
    // 트리 조회
    // =============================================

    /**
     * 카테고리 트리 (아이템 정보 JOIN)
     *
     * 부모-자식 DFS 순서로 정렬하여 반환
     * 패션의류 → 상의 → 하의 → 아우터 → 전자제품 → 노트북 → ...
     */
    public function getTree(int $domainId): Result
    {
        $tree = $this->categoryRepository->getTreeWithItems($domainId);
        $sorted = $this->sortTreeDfs($tree);

        return Result::success('', ['items' => $sorted]);
    }

    /**
     * flat 트리를 부모-자식 DFS 순서로 정렬
     *
     * parent_code → children 맵을 만들고,
     * 루트(parent_code=null)부터 재귀 순회
     */
    private function sortTreeDfs(array $items): array
    {
        // parent_code별 자식 그룹핑 (sort_order 유지)
        $childrenMap = [];
        foreach ($items as $item) {
            $parentKey = $item['parent_code'] ?? '__root__';
            $childrenMap[$parentKey][] = $item;
        }

        // 각 그룹 내 sort_order 정렬
        foreach ($childrenMap as &$group) {
            usort($group, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        }
        unset($group);

        // DFS 순회
        $result = [];
        $this->dfsWalk($childrenMap, '__root__', $result);

        return $result;
    }

    private function dfsWalk(array &$childrenMap, string $parentKey, array &$result): void
    {
        if (!isset($childrenMap[$parentKey])) {
            return;
        }
        foreach ($childrenMap[$parentKey] as $node) {
            $result[] = $node;
            // 이 노드의 path_code가 자식들의 parent_code
            $this->dfsWalk($childrenMap, $node['path_code'], $result);
        }
    }

    /**
     * 트리를 계층형 배열로 변환
     */
    public function getTreeHierarchy(int $domainId): array
    {
        $flatTree = $this->categoryRepository->getTreeWithItems($domainId);

        return $this->buildHierarchy($flatTree);
    }

    // =============================================
    // 트리 관리
    // =============================================

    /**
     * 트리 구조 저장 (전체 교체)
     *
     * JS에서 수집한 트리 데이터를 받아 shop_category_tree 재구성
     * $treeData = [
     *   ['category_code' => 'abc', 'children' => [
     *     ['category_code' => 'def', 'children' => []]
     *   ]]
     * ]
     */
    public function saveTree(int $domainId, array $treeData): Result
    {
        $db = $this->categoryRepository->getDb();

        try {
            $db->beginTransaction();

            // 기존 트리 전체 삭제
            $this->categoryRepository->deleteAllNodes($domainId);

            // 새 트리 재구성
            $this->insertTreeNodes($domainId, $treeData, null, '', '', 1);

            $db->commit();

            return Result::success('카테고리 트리가 저장되었습니다.');
        } catch (\Throwable $e) {
            $db->rollBack();
            return Result::failure('트리 저장 중 오류가 발생했습니다: ' . $e->getMessage());
        }
    }

    // =============================================
    // Private
    // =============================================

    /**
     * 트리 노드 재귀 삽입
     */
    private function insertTreeNodes(
        int $domainId,
        array $nodes,
        ?string $parentCode,
        string $parentPathCode,
        string $parentPathName,
        int $depth
    ): void {
        $sortOrder = 1;

        foreach ($nodes as $node) {
            $categoryCode = $node['category_code'] ?? '';
            if (empty($categoryCode)) {
                continue;
            }

            // 아이템에서 이름 조회
            $item = $this->categoryRepository->findByCode($domainId, $categoryCode);
            $name = $item ? $item->getName() : $categoryCode;

            $pathCode = $parentPathCode ? ($parentPathCode . '>' . $categoryCode) : $categoryCode;
            $pathName = $parentPathName ? ($parentPathName . '>' . $name) : $name;

            $this->categoryRepository->createNode([
                'domain_id' => $domainId,
                'category_code' => $categoryCode,
                'path_code' => $pathCode,
                'path_name' => $pathName,
                'parent_code' => $parentCode,
                'depth' => $depth,
                'sort_order' => $sortOrder++,
            ]);

            // 하위 노드 재귀
            if (!empty($node['children'])) {
                $this->insertTreeNodes($domainId, $node['children'], $categoryCode, $pathCode, $pathName, $depth + 1);
            }
        }
    }

    /**
     * flat 배열 → 계층형 배열
     */
    private function buildHierarchy(array $flatTree): array
    {
        $map = [];
        $roots = [];

        foreach ($flatTree as $node) {
            $code = $node['category_code'];
            $node['children'] = [];
            $map[$code] = $node;
        }

        foreach ($map as $code => &$node) {
            $parentCode = $node['parent_code'] ?? null;
            if ($parentCode && isset($map[$parentCode])) {
                $map[$parentCode]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }

        return $roots;
    }

    /**
     * 랜덤 8자리 영숫자 코드
     */
    private function generateCode(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
}
