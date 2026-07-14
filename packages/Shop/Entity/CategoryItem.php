<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Entity\BaseEntity;

/**
 * CategoryItem Entity
 *
 * 쇼핑몰 카테고리 아이템 엔티티 (shop_category_items 테이블)
 *
 * 책임:
 * - shop_category_items 테이블의 데이터를 객체로 표현
 * - 카테고리 상태/접근 권한 판단 메서드 제공
 *
 * 금지:
 * - DB 직접 접근
 */
class CategoryItem extends BaseEntity
{
    // ========================================
    // 기본 정보
    // ========================================
    protected int $categoryId = 0;
    protected int $domainId = 0;
    protected string $categoryCode = '';
    protected string $name = '';
    protected ?string $description = null;
    protected ?string $image = null;

    // ========================================
    // 접근 제어
    // ========================================
    protected int $allowMemberLevel = 0;

    // ========================================
    // 설정
    // ========================================
    protected bool $allowCoupon = true;
    protected bool $isAdult = false;
    protected bool $isActive = true;

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
        return 'categoryId';
    }

    /**
     * DB 로우 데이터로부터 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        // 기본 정보
        $entity->categoryId = (int) ($data['category_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->categoryCode = $data['category_code'] ?? '';
        $entity->name = $data['name'] ?? '';
        $entity->description = $data['description'] ?? null;
        $entity->image = $data['image'] ?? null;

        // 접근 제어
        $entity->allowMemberLevel = (int) ($data['allow_member_level'] ?? 0);

        // 설정
        $entity->allowCoupon = (bool) ($data['allow_coupon'] ?? true);
        $entity->isAdult = (bool) ($data['is_adult'] ?? false);
        $entity->isActive = (bool) ($data['is_active'] ?? true);

        // 타임스탬프
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'domain_id' => $this->domainId,
            'category_code' => $this->categoryCode,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image,
            'allow_member_level' => $this->allowMemberLevel,
            'allow_coupon' => $this->allowCoupon,
            'is_adult' => $this->isAdult,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // ========================================
    // Getter 메서드
    // ========================================

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getCategoryCode(): string
    {
        return $this->categoryCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getAllowMemberLevel(): int
    {
        return $this->allowMemberLevel;
    }

    // ========================================
    // 상태 판단 메서드
    // ========================================

    /**
     * 활성 상태 여부
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * 쿠폰 허용 여부
     */
    public function isCouponAllowed(): bool
    {
        return $this->allowCoupon;
    }

    /**
     * 성인인증 필요 여부
     */
    public function isAdult(): bool
    {
        return $this->isAdult;
    }

    /**
     * 이미지가 있는지 여부
     */
    public function hasImage(): bool
    {
        return $this->image !== null && $this->image !== '';
    }

    /**
     * 설명이 있는지 여부
     */
    public function hasDescription(): bool
    {
        return $this->description !== null && $this->description !== '';
    }

    /**
     * 특정 레벨의 회원이 접근 가능한지 여부
     *
     * @param int $memberLevel 회원 레벨 (0: 비회원)
     */
    public function canAccessByLevel(int $memberLevel): bool
    {
        return $memberLevel >= $this->allowMemberLevel;
    }
}
