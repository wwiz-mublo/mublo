<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Entity\BaseEntity;

/**
 * CategoryNode Entity
 *
 * 쇼핑몰 카테고리 트리 노드 엔티티 (shop_category_tree 테이블)
 *
 * 책임:
 * - shop_category_tree 테이블의 데이터를 객체로 표현
 * - 트리 구조/경로 관련 메서드 제공
 *
 * 금지:
 * - DB 직접 접근
 */
class CategoryNode extends BaseEntity
{
    // ========================================
    // 기본 정보
    // ========================================
    protected int $nodeId = 0;
    protected int $domainId = 0;
    protected string $categoryCode = '';

    // ========================================
    // 경로 정보
    // ========================================
    protected string $pathCode = '';
    protected string $pathName = '';
    protected ?string $parentCode = null;
    protected int $depth = 1;

    // ========================================
    // 정렬
    // ========================================
    protected int $sortOrder = 0;

    /**
     * Private constructor - fromArray() 사용
     */
    private function __construct()
    {
    }

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
        $entity->categoryCode = $data['category_code'] ?? '';

        // 경로 정보
        $entity->pathCode = $data['path_code'] ?? '';
        $entity->pathName = $data['path_name'] ?? '';
        $entity->parentCode = $data['parent_code'] ?? null;
        $entity->depth = (int) ($data['depth'] ?? 1);

        // 정렬
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);

        return $entity;
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'domain_id' => $this->domainId,
            'category_code' => $this->categoryCode,
            'path_code' => $this->pathCode,
            'path_name' => $this->pathName,
            'parent_code' => $this->parentCode,
            'depth' => $this->depth,
            'sort_order' => $this->sortOrder,
        ];
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

    public function getCategoryCode(): string
    {
        return $this->categoryCode;
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
    // 경로/트리 관련 메서드
    // ========================================

    /**
     * 루트 노드인지 여부 (depth === 1)
     */
    public function isRoot(): bool
    {
        return $this->depth === 1;
    }

    /**
     * 브레드크럼 배열 반환
     *
     * @return string[] ['전자제품', '컴퓨터', '노트북']
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
     * @return string[] ['aB3cD5eF', 'xK9mL3nR']
     */
    public function getPathCodes(): array
    {
        if (empty($this->pathCode)) {
            return [];
        }

        return explode('>', $this->pathCode);
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
     * 현재 경로에 특정 카테고리 코드가 포함되어 있는지
     */
    public function containsCategoryCode(string $categoryCode): bool
    {
        return in_array($categoryCode, $this->getPathCodes(), true);
    }
}
