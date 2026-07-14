<?php

namespace Mublo\Packages\Shop\Api\DTO;

/**
 * Extension API용 readonly 상품 값 객체.
 *
 * 내부 Product Entity를 노출하지 않아 Shop의 영속 구조를 독립적으로 변경할 수 있다.
 */
final readonly class ProductSnapshot
{
    public function __construct(
        private int $goodsId,
        private int $domainId,
        private string $itemCode,
        private string $name,
        private ?string $slug,
        private ?string $categoryCode,
        private int $displayPrice,
        private ?int $stockQuantity,
        private bool $active,
        private string $createdAt
    ) {
    }

    public function getGoodsId(): int
    {
        return $this->goodsId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getItemCode(): string
    {
        return $this->itemCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getCategoryCode(): ?string
    {
        return $this->categoryCode;
    }

    public function getDisplayPrice(): int
    {
        return $this->displayPrice;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
}
