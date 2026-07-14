<?php

namespace Mublo\Packages\Shop\Entity;

/**
 * ProductOptionValue Entity
 *
 * 상품 옵션값 엔티티 (shop_product_option_values 테이블)
 *
 * 책임:
 * - shop_product_option_values 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class ProductOptionValue
{
    protected int $valueId;
    protected int $optionId;
    protected string $valueName;
    protected int $extraPrice;
    protected int $stockQuantity;
    protected bool $isActive;
    protected int $sortOrder;
    protected string $createdAt;
    protected ?string $updatedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->valueId = (int) ($data['value_id'] ?? 0);
        $entity->optionId = (int) ($data['option_id'] ?? 0);
        $entity->valueName = $data['value_name'] ?? '';
        $entity->extraPrice = (int) ($data['extra_price'] ?? 0);
        $entity->stockQuantity = (int) ($data['stock_quantity'] ?? 0);
        $entity->isActive = (bool) ($data['is_active'] ?? true);
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'value_id' => $this->valueId,
            'option_id' => $this->optionId,
            'value_name' => $this->valueName,
            'extra_price' => $this->extraPrice,
            'stock_quantity' => $this->stockQuantity,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getValueId(): int
    {
        return $this->valueId;
    }

    public function getOptionId(): int
    {
        return $this->optionId;
    }

    public function getValueName(): string
    {
        return $this->valueName;
    }

    public function getExtraPrice(): int
    {
        return $this->extraPrice;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // === 상태 판단 메서드 ===

    /**
     * 추가 금액 여부
     */
    public function hasExtraPrice(): bool
    {
        return $this->extraPrice !== 0;
    }

    /**
     * 재고 있음 여부
     */
    public function isInStock(): bool
    {
        return $this->stockQuantity > 0;
    }
}
