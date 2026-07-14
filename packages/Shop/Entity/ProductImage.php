<?php

namespace Mublo\Packages\Shop\Entity;

/**
 * ProductImage Entity
 *
 * 상품 이미지 엔티티 (shop_product_images 테이블)
 *
 * 책임:
 * - shop_product_images 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class ProductImage
{
    protected int $imageId;
    protected int $goodsId;
    protected string $imageUrl;
    protected ?string $thumbnailUrl;
    protected ?string $webpUrl;
    protected bool $isMain;
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

        $entity->imageId = (int) ($data['image_id'] ?? 0);
        $entity->goodsId = (int) ($data['goods_id'] ?? 0);
        $entity->imageUrl = $data['image_url'] ?? '';
        $entity->thumbnailUrl = $data['thumbnail_url'] ?? null;
        $entity->webpUrl = $data['webp_url'] ?? null;
        $entity->isMain = (bool) ($data['is_main'] ?? false);
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
            'image_id' => $this->imageId,
            'goods_id' => $this->goodsId,
            'image_url' => $this->imageUrl,
            'thumbnail_url' => $this->thumbnailUrl,
            'webp_url' => $this->webpUrl,
            'is_main' => $this->isMain,
            'sort_order' => $this->sortOrder,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getImageId(): int
    {
        return $this->imageId;
    }

    public function getGoodsId(): int
    {
        return $this->goodsId;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function getWebpUrl(): ?string
    {
        return $this->webpUrl;
    }

    public function isMain(): bool
    {
        return $this->isMain;
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
}
