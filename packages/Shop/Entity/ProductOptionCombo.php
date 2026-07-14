<?php

namespace Mublo\Packages\Shop\Entity;

/**
 * ProductOptionCombo Entity
 *
 * 상품 옵션 조합 엔티티 (shop_product_option_combos 테이블)
 *
 * 책임:
 * - shop_product_option_combos 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class ProductOptionCombo
{
    protected int $comboId;
    protected int $goodsId;
    protected string $combinationKey;
    protected int $extraPrice;
    protected int $stockQuantity;
    protected bool $isActive;
    protected string $createdAt;
    protected ?string $updatedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->comboId = (int) ($data['combo_id'] ?? 0);
        $entity->goodsId = (int) ($data['goods_id'] ?? 0);
        $entity->combinationKey = $data['combination_key'] ?? '';
        $entity->extraPrice = (int) ($data['extra_price'] ?? 0);
        $entity->stockQuantity = (int) ($data['stock_quantity'] ?? 0);
        $entity->isActive = (bool) ($data['is_active'] ?? true);
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
            'combo_id' => $this->comboId,
            'goods_id' => $this->goodsId,
            'combination_key' => $this->combinationKey,
            'extra_price' => $this->extraPrice,
            'stock_quantity' => $this->stockQuantity,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getComboId(): int
    {
        return $this->comboId;
    }

    public function getGoodsId(): int
    {
        return $this->goodsId;
    }

    public function getCombinationKey(): string
    {
        return $this->combinationKey;
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
