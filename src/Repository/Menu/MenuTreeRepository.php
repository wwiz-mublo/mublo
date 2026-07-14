<?php
namespace Mublo\Repository\Menu;

use Mublo\Entity\Menu\MenuNode;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * MenuTreeRepository
 *
 * 메뉴 트리 구조 (menu_tree) 데이터베이스 접근
 *
 * 책임:
 * - menu_tree 테이블 CRUD
 * - 트리 구조 조회 (부모-자식 관계)
 * - 경로 기반 조회 (브레드크럼)
 */
class MenuTreeRepository extends BaseRepository
{
    protected string $table = 'menu_tree';
    protected string $entityClass = MenuNode::class;
    protected string $primaryKey = 'node_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 도메인별 전체 트리 조회
     */
    public function findByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('depth', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 루트 노드 조회 (depth = 1)
     */
    public function findRootNodes(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('depth', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 특정 부모의 자식 노드 조회
     */
    public function findChildren(int $domainId, string $parentCode): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('parent_code', '=', $parentCode)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 특정 깊이의 노드 조회
     */
    public function findByDepth(int $domainId, int $depth): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('depth', '=', $depth)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * path_code로 노드 조회
     */
    public function findByPathCode(int $domainId, string $pathCode): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('path_code', '=', $pathCode)
            ->first();
    }

    /**
     * menu_code로 노드 목록 조회 (같은 메뉴가 여러 위치에 있을 수 있음)
     */
    public function findByMenuCode(int $domainId, string $menuCode): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('menu_code', '=', $menuCode)
            ->get();
    }

    /**
     * 트리와 메뉴 아이템 조인 조회
     */
    public function findTreeWithItems(int $domainId, bool $activeOnly = true): array
    {
        $sql = "SELECT mt.*, mi.label, mi.url, mi.icon, mi.target, mi.visibility,
                       mi.min_level, mi.required_permission, mi.show_on_pc, mi.show_on_mobile,
                       mi.is_active, mi.provider_type, mi.provider_name, mi.pair_code
                FROM {$this->db->getTablePrefix()}menu_tree mt
                JOIN {$this->db->getTablePrefix()}menu_items mi
                    ON mt.domain_id = mi.domain_id AND mt.menu_code = mi.menu_code
                WHERE mt.domain_id = ?";

        $params = [$domainId];

        if ($activeOnly) {
            $sql .= " AND mi.is_active = 1";
        }

        $sql .= " ORDER BY mt.depth ASC, mt.sort_order ASC";

        return $this->db->select($sql, $params);
    }

    /**
     * 특정 깊이의 트리와 메뉴 아이템 조인 조회
     */
    public function findTreeWithItemsByDepth(int $domainId, int $depth, bool $activeOnly = true): array
    {
        $sql = "SELECT mt.*, mi.label, mi.url, mi.icon, mi.target, mi.visibility,
                       mi.min_level, mi.show_on_pc, mi.show_on_mobile,
                       mi.provider_type, mi.provider_name
                FROM {$this->db->getTablePrefix()}menu_tree mt
                JOIN {$this->db->getTablePrefix()}menu_items mi
                    ON mt.domain_id = mi.domain_id AND mt.menu_code = mi.menu_code
                WHERE mt.domain_id = ? AND mt.depth = ?";

        $params = [$domainId, $depth];

        if ($activeOnly) {
            $sql .= " AND mi.is_active = 1";
        }

        $sql .= " ORDER BY mt.sort_order ASC";

        return $this->db->select($sql, $params);
    }

    /**
     * 자식 노드와 메뉴 아이템 조인 조회
     */
    public function findChildrenWithItems(int $domainId, string $parentCode, bool $activeOnly = true): array
    {
        $sql = "SELECT mt.*, mi.label, mi.url, mi.icon, mi.target, mi.visibility,
                       mi.min_level, mi.show_on_pc, mi.show_on_mobile,
                       mi.provider_type, mi.provider_name
                FROM {$this->db->getTablePrefix()}menu_tree mt
                JOIN {$this->db->getTablePrefix()}menu_items mi
                    ON mt.domain_id = mi.domain_id AND mt.menu_code = mi.menu_code
                WHERE mt.domain_id = ? AND mt.parent_code = ?";

        $params = [$domainId, $parentCode];

        if ($activeOnly) {
            $sql .= " AND mi.is_active = 1";
        }

        $sql .= " ORDER BY mt.sort_order ASC";

        return $this->db->select($sql, $params);
    }

    /**
     * 정렬 순서 업데이트
     */
    public function updateSortOrder(int $nodeId, int $domainId, int $sortOrder): int
    {
        return $this->db->table($this->table)
            ->where('node_id', '=', $nodeId)
            ->where('domain_id', '=', $domainId)
            ->update(['sort_order' => $sortOrder]);
    }

    /**
     * 특정 부모 아래의 최대 정렬 순서 조회
     */
    public function getMaxSortOrder(int $domainId, ?string $parentCode = null): int
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($parentCode === null) {
            $query->whereRaw('parent_code IS NULL');
        } else {
            $query->where('parent_code', '=', $parentCode);
        }

        return $query->max('sort_order') ?? 0;
    }

    /**
     * 도메인의 모든 트리 노드 삭제
     */
    public function deleteByDomain(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 특정 path_code로 시작하는 노드 삭제 (자식 포함)
     */
    public function deleteByPathPrefix(int $domainId, string $pathCodePrefix): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereRaw('path_code LIKE ?', [$pathCodePrefix . '%'])
            ->delete();
    }

    /**
     * 메뉴 코드로 트리 노드 삭제
     */
    public function deleteByMenuCode(int $domainId, string $menuCode): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('menu_code', '=', $menuCode)
            ->delete();
    }

    /**
     * 배열을 MenuNode Entity로 변환
     */
    protected function toEntity(array $row): MenuNode
    {
        return MenuNode::fromArray($row);
    }

    /**
     * 생성 타임스탬프 없음
     */
    protected function getCreatedAtField(): ?string
    {
        return null;
    }

    /**
     * 수정 타임스탬프 없음
     */
    protected function getUpdatedAtField(): ?string
    {
        return null;
    }
}
