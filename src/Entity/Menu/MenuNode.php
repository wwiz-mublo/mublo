<?php
namespace Mublo\Entity\Menu;

use Mublo\Entity\BaseEntity;

/**
 * MenuNode Entity
 *
 * 메뉴 트리 노드 엔티티 (menu_tree 테이블)
 *
 * 책임:
 * - menu_tree 테이블의 데이터를 객체로 표현
 * - 트리 구조/경로 관련 메서드 제공
 * - MenuItem과의 연결 관리
 *
 * 금지:
 * - DB 직접 접근
 */
class MenuNode extends BaseEntity
{
    // ========================================
    // 기본 정보
    // ========================================
    protected int $nodeId = 0;
    protected int $domainId = 0;
    protected string $menuCode = '';

    // ========================================
    // 경로 정보
    // ========================================
    protected string $pathCode = '';      // 'mN7kP2xQ>xK9mL3nR'
    protected string $pathName = '';      // '홈>회사소개'
    protected ?string $parentCode = null;
    protected int $depth = 1;

    // ========================================
    // 정렬
    // ========================================
    protected int $sortOrder = 0;

    // ========================================
    // 연결된 MenuItem (JOIN 결과)
    // ========================================
    protected ?MenuItem $menuItem = null;

    // ========================================
    // 자식 노드 (계층 구조 빌드 시)
    // ========================================
    protected array $children = [];

    /**
     * 기본키 필드명
     */
    protected function getPrimaryKeyField(): string
    {
        return 'nodeId';
    }

    /**
     * DB 로우 데이터로부터 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        // 기본 정보
        $entity->nodeId = (int) ($data['node_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->menuCode = $data['menu_code'] ?? '';

        // 경로 정보
        $entity->pathCode = $data['path_code'] ?? '';
        $entity->pathName = $data['path_name'] ?? '';
        $entity->parentCode = $data['parent_code'] ?? null;
        $entity->depth = (int) ($data['depth'] ?? 1);

        // 정렬
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);

        // MenuItem 정보가 JOIN되어 있으면 생성
        if (isset($data['label'])) {
            $entity->menuItem = MenuItem::fromArray($data);
        }

        return $entity;
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        $result = [
            'node_id' => $this->nodeId,
            'domain_id' => $this->domainId,
            'menu_code' => $this->menuCode,
            'path_code' => $this->pathCode,
            'path_name' => $this->pathName,
            'parent_code' => $this->parentCode,
            'depth' => $this->depth,
            'sort_order' => $this->sortOrder,
        ];

        // MenuItem 정보 병합
        if ($this->menuItem !== null) {
            $result = array_merge($result, $this->menuItem->toArray());
        }

        // 자식 노드
        if (!empty($this->children)) {
            $result['children'] = array_map(fn($child) => $child->toArray(), $this->children);
        }

        return $result;
    }

    // ========================================
    // Getter 메서드
    // ========================================

    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getMenuCode(): string
    {
        return $this->menuCode;
    }

    public function getPathCode(): string
    {
        return $this->pathCode;
    }

    public function getPathName(): string
    {
        return $this->pathName;
    }

    public function getParentCode(): ?string
    {
        return $this->parentCode;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    // ========================================
    // MenuItem 연결
    // ========================================

    /**
     * 연결된 MenuItem 반환
     */
    public function getMenuItem(): ?MenuItem
    {
        return $this->menuItem;
    }

    /**
     * MenuItem 설정
     */
    public function setMenuItem(MenuItem $item): self
    {
        $this->menuItem = $item;
        return $this;
    }

    /**
     * MenuItem이 연결되어 있는지 여부
     */
    public function hasMenuItem(): bool
    {
        return $this->menuItem !== null;
    }

    // ========================================
    // 자식 노드 관리
    // ========================================

    /**
     * 자식 노드 목록 반환
     *
     * @return MenuNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * 자식 노드 추가
     */
    public function addChild(MenuNode $child): self
    {
        $this->children[] = $child;
        return $this;
    }

    /**
     * 자식 노드 설정
     *
     * @param MenuNode[] $children
     */
    public function setChildren(array $children): self
    {
        $this->children = $children;
        return $this;
    }

    /**
     * 자식 노드가 있는지 여부
     */
    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    // ========================================
    // 경로/트리 관련 메서드
    // ========================================

    /**
     * 브레드크럼 배열 반환
     *
     * @return string[] ['홈', '회사소개', '오시는길']
     */
    public function getBreadcrumb(): array
    {
        if (empty($this->pathName)) {
            return [];
        }

        return explode('>', $this->pathName);
    }

    /**
     * 경로 코드 배열 반환
     *
     * @return string[] ['mN7kP2xQ', 'xK9mL3nR', 'aB3cD5eF']
     */
    public function getPathCodes(): array
    {
        if (empty($this->pathCode)) {
            return [];
        }

        return explode('>', $this->pathCode);
    }

    /**
     * 루트 노드인지 여부
     */
    public function isRoot(): bool
    {
        return $this->parentCode === null;
    }

    /**
     * 부모가 있는지 여부
     */
    public function hasParent(): bool
    {
        return $this->parentCode !== null;
    }

    /**
     * 특정 깊이인지 여부
     */
    public function isDepth(int $depth): bool
    {
        return $this->depth === $depth;
    }

    /**
     * 현재 경로에 특정 메뉴 코드가 포함되어 있는지
     */
    public function containsMenuCode(string $menuCode): bool
    {
        return in_array($menuCode, $this->getPathCodes(), true);
    }

    // ========================================
    // MenuItem 위임 메서드 (편의용)
    // ========================================

    /**
     * 메뉴 라벨 (MenuItem에서 가져옴)
     */
    public function getLabel(): string
    {
        return $this->menuItem?->getLabel() ?? '';
    }

    /**
     * 메뉴 URL (MenuItem에서 가져옴)
     */
    public function getUrl(): ?string
    {
        return $this->menuItem?->getUrl();
    }

    /**
     * 메뉴 아이콘 (MenuItem에서 가져옴)
     */
    public function getIcon(): ?string
    {
        return $this->menuItem?->getIcon();
    }

    /**
     * 활성 상태 여부 (MenuItem에서 가져옴)
     */
    public function isActive(): bool
    {
        return $this->menuItem?->isActive() ?? false;
    }

    /**
     * URL이 있는지 여부 (MenuItem에서 가져옴)
     */
    public function hasUrl(): bool
    {
        return $this->menuItem?->hasUrl() ?? false;
    }

    /**
     * 특정 디바이스에서 표시 가능한지 (MenuItem에서 가져옴)
     */
    public function isShowOnDevice(string $device): bool
    {
        return $this->menuItem?->isShowOnDevice($device) ?? true;
    }

    /**
     * 특정 사용자 타입에게 표시 가능한지 (MenuItem에서 가져옴)
     */
    public function isVisibleFor(string $userType): bool
    {
        return $this->menuItem?->isVisibleFor($userType) ?? true;
    }

    /**
     * 특정 레벨의 회원이 접근 가능한지 (MenuItem에서 가져옴)
     */
    public function canAccessByLevel(int $memberLevel): bool
    {
        return $this->menuItem?->canAccessByLevel($memberLevel) ?? true;
    }
}
