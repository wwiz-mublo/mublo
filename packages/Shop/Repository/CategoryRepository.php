<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\Shop\Entity\CategoryItem;
use Mublo\Repository\BaseRepository;

/**
 * Category Repository
 *
 * 쇼핑몰 카테고리 데이터베이스 접근 담당
 * shop_category_items + shop_category_tree 두 테이블 관리
 */
class CategoryRepository extends BaseRepository
{
    protected string $table = 'shop_category_items';
    protected string $entityClass = CategoryItem::class;
    protected string $primaryKey = 'category_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    // =============================================
    // 조회
    // =============================================

    /**
     * 카테고리 코드로 아이템 조회
     */
    public function findByCode(int $domainId, string $code): ?CategoryItem
    {
        $row = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $code)
            ->first();

        return $row ? CategoryItem::fromArray($row) : null;
    }

    /**
     * 카테고리 ID로 아이템 조회 (raw array)
     */
    public function findItemInDomain(int $domainId, int $categoryId): ?array
    {
        $row = $this->getDb()->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ?: null;
    }

    /**
     * 노드 ID로 트리 노드 조회 (raw array)
     */
    public function findNode(int $nodeId): ?array
    {
        $row = $this->getDb()->table('shop_category_tree')
            ->where('node_id', '=', $nodeId)
            ->first();

        return $row ?: null;
    }

    /**
     * 카테고리 코드 존재 여부
     */
    public function existsByCategoryCode(int $domainId, string $code): bool
    {
        $row = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $code)
            ->first();

        return $row !== null && $row !== false;
    }

    /**
     * path_code로 노드 조회
     */
    public function getNodeByPathCode(int $domainId, string $pathCode): ?array
    {
        $row = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId)
            ->where('path_code', '=', $pathCode)
            ->first();

        return $row ?: null;
    }

    /**
     * 트리+아이템 JOIN 조회
     */
    public function getTreeWithItems(int $domainId): array
    {
        $treeTable = $this->getDb()->prefixTable('shop_category_tree');
        $itemTable = $this->getDb()->prefixTable('shop_category_items');

        $sql = "SELECT
                    t.node_id,
                    i.category_id,
                    t.category_code,
                    t.path_code,
                    t.path_name,
                    t.parent_code,
                    t.depth,
                    t.sort_order,
                    i.name,
                    i.description,
                    i.image,
                    i.is_active
                FROM {$treeTable} AS t
                INNER JOIN {$itemTable} AS i
                    ON t.domain_id = i.domain_id AND t.category_code = i.category_code
                WHERE t.domain_id = ?
                ORDER BY t.depth ASC, t.sort_order ASC";

        return $this->getDb()->select($sql, [$domainId]);
    }

    /**
     * 자식 노드 조회
     */
    public function getChildren(int $domainId, ?string $parentCode = null): array
    {
        $query = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId);

        if ($parentCode === null) {
            $query->whereRaw('parent_code IS NULL');
        } else {
            $query->where('parent_code', '=', $parentCode);
        }

        return $query->orderBy('sort_order', 'ASC')->get();
    }

    /**
     * 다음 정렬 순서 조회
     */
    public function getNextSortOrder(int $domainId, ?string $parentCode): int
    {
        $query = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId);

        if ($parentCode === null) {
            $query->whereRaw('parent_code IS NULL');
        } else {
            $query->where('parent_code', '=', $parentCode);
        }

        $row = $query->orderBy('sort_order', 'DESC')->first();

        return $row ? ((int) $row['sort_order'] + 1) : 1;
    }

    /**
     * 카테고리에 연결된 상품 수
     */
    public function getProductCount(int $domainId, string $categoryCode): int
    {
        return $this->getDb()->table('shop_products')
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $categoryCode)
            ->count();
    }

    // =============================================
    // 생성
    // =============================================

    /**
     * 카테고리 아이템 생성
     */
    public function createItem(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');

        return $this->getDb()->table($this->table)->insert($data);
    }

    /**
     * 카테고리 트리 노드 생성
     */
    public function createNode(array $data): int
    {
        return $this->getDb()->table('shop_category_tree')->insert($data);
    }

    // =============================================
    // 수정
    // =============================================

    /**
     * 카테고리 아이템 수정
     */
    public function updateItemInDomain(int $domainId, int $categoryId, array $data): bool
    {
        unset($data['domain_id']);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->getDb()->table($this->table)
            ->where('category_id', '=', $categoryId)
            ->where('domain_id', '=', $domainId)
            ->update($data);

        return $affected >= 0;
    }

    /**
     * 카테고리 트리 노드 수정
     */
    public function updateNode(int $nodeId, array $data): bool
    {
        $affected = $this->getDb()->table('shop_category_tree')
            ->where('node_id', '=', $nodeId)
            ->update($data);

        return $affected >= 0;
    }

    /**
     * 하위 노드들의 path_name 일괄 갱신
     */
    public function updateChildrenPathName(int $domainId, string $oldPathName, string $newPathName): void
    {
        $treeTable = $this->getDb()->prefixTable('shop_category_tree');

        $sql = "UPDATE {$treeTable}
                SET path_name = CONCAT(?, SUBSTRING(path_name, ?))
                WHERE domain_id = ?
                AND path_name LIKE ?";

        $this->getDb()->statement($sql, [
            $newPathName,
            strlen($oldPathName) + 1,
            $domainId,
            $oldPathName . '>%',
        ]);
    }

    /**
     * 노드 정렬 순서 일괄 변경
     */
    public function updateSortOrders(int $domainId, array $orders): bool
    {
        foreach ($orders as $order) {
            $nodeId = (int) ($order['node_id'] ?? 0);
            $sortOrder = (int) ($order['sort_order'] ?? 0);

            if ($nodeId > 0) {
                $this->getDb()->table('shop_category_tree')
                    ->where('node_id', '=', $nodeId)
                    ->where('domain_id', '=', $domainId)
                    ->update(['sort_order' => $sortOrder]);
            }
        }

        return true;
    }

    // =============================================
    // 삭제
    // =============================================

    /**
     * 카테고리 코드로 트리 노드 삭제
     */
    public function deleteNodeByCategoryCode(int $domainId, string $categoryCode): bool
    {
        $affected = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $categoryCode)
            ->delete();

        return $affected > 0;
    }

    /**
     * 카테고리 코드로 아이템 삭제
     */
    public function deleteItemByCategoryCode(int $domainId, string $categoryCode): bool
    {
        $affected = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $categoryCode)
            ->delete();

        return $affected > 0;
    }

    /**
     * 도메인별 아이템 목록 (전체)
     */
    public function getItemsByDomain(int $domainId): array
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * 카테고리 코드로 트리 노드 수 조회
     */
    public function getNodeCountByCode(int $domainId, string $categoryCode): int
    {
        return $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $categoryCode)
            ->count();
    }

    /**
     * 아이템 이름 변경 시 트리 path_name 갱신
     */
    public function updatePathNameByCode(int $domainId, string $categoryCode, string $newName): void
    {
        // 해당 카테고리의 트리 노드들 조회
        $nodes = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $categoryCode)
            ->get();

        foreach ($nodes as $node) {
            $oldPathName = $node['path_name'] ?? '';
            $parts = explode('>', $oldPathName);
            $parts[count($parts) - 1] = $newName;
            $newPathName = implode('>', $parts);

            $this->updateNode((int) $node['node_id'], ['path_name' => $newPathName]);

            // 하위 노드 path_name 갱신
            if ($oldPathName !== $newPathName) {
                $this->updateChildrenPathName($domainId, $oldPathName, $newPathName);
            }
        }
    }

    /**
     * 도메인의 전체 트리 노드 삭제
     */
    public function deleteAllNodes(int $domainId): bool
    {
        $affected = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId)
            ->delete();

        return $affected >= 0;
    }

    /**
     * 특정 카테고리 + 하위 카테고리 코드 조회
     *
     * path_code LIKE 기반으로 모든 자손 카테고리 코드를 반환
     */
    public function getDescendantCodes(int $domainId, string $categoryCode): array
    {
        $nodes = $this->getDb()->table('shop_category_tree')
            ->where('domain_id', '=', $domainId)
            ->where('category_code', '=', $categoryCode)
            ->get();

        $codes = [$categoryCode];

        foreach ($nodes as $node) {
            $pathCode = $node['path_code'] ?? '';
            if ($pathCode === '') {
                continue;
            }

            $descendants = $this->getDb()->table('shop_category_tree')
                ->where('domain_id', '=', $domainId)
                ->where('path_code', 'LIKE', $pathCode . '>%')
                ->get();

            foreach ($descendants as $desc) {
                $codes[] = $desc['category_code'];
            }
        }

        return array_unique($codes);
    }

    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    protected function getUpdatedAtField(): ?string
    {
        return 'updated_at';
    }
}
