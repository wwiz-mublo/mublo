<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Enum\OptionMode;

/**
 * Product Entity
 *
 * 상품 엔티티 (shop_products 테이블)
 *
 * 책임:
 * - shop_products 테이블 데이터 표현
 * - 상품 상태 판단 메서드 제공
 * - 불변 객체 패턴
 */
final class Product
{
    // 기본 정보
    protected int $goodsId;
    protected int $domainId;
    protected ?string $categoryCode;
    protected ?string $categoryCodeExtra;
    protected string $itemCode;
    protected string $goodsName;
    protected ?string $goodsSlug;
    protected ?string $goodsOrigin;
    protected ?string $goodsManufacturer;
    protected ?string $goodsCode;
    protected ?string $goodsBadge;
    protected ?string $goodsIcon;
    protected ?string $goodsFilter;
    protected ?string $goodsTags;

    // 가격/할인/적립
    protected int $originPrice;
    protected int $displayPrice;
    protected DiscountType $discountType;
    protected float $discountValue;
    protected RewardType $rewardType;
    protected float $rewardValue;
    protected int $rewardReview;
    protected bool $allowedCoupon;

    // 재고/옵션
    protected ?int $stockQuantity;
    protected OptionMode $optionMode;

    // 배송
    protected ?int $shippingTemplateId;
    protected string $shippingApplyType;

    // 판매자/공급자
    protected ?string $sellerId;
    protected ?string $supplyId;

    // 통계
    protected int $hit;

    // 상태
    protected bool $isActive;

    // 시간
    protected string $createdAt;
    protected ?string $updatedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        // 기본 정보
        $entity->goodsId = (int) ($data['goods_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->categoryCode = $data['category_code'] ?? null;
        $entity->categoryCodeExtra = $data['category_code_extra'] ?? null;
        $entity->itemCode = $data['item_code'] ?? '';
        $entity->goodsName = $data['goods_name'] ?? '';
        $entity->goodsSlug = $data['goods_slug'] ?? null;
        $entity->goodsOrigin = $data['goods_origin'] ?? null;
        $entity->goodsManufacturer = $data['goods_manufacturer'] ?? null;
        $entity->goodsCode = $data['goods_code'] ?? null;
        $entity->goodsBadge = $data['goods_badge'] ?? null;
        $entity->goodsIcon = $data['goods_icon'] ?? null;
        $entity->goodsFilter = $data['goods_filter'] ?? null;
        $entity->goodsTags = $data['goods_tags'] ?? null;

        // 가격/할인/적립
        $entity->originPrice = (int) ($data['origin_price'] ?? 0);
        $entity->displayPrice = (int) ($data['display_price'] ?? 0);
        $entity->discountType = DiscountType::tryFrom($data['discount_type'] ?? 'NONE') ?? DiscountType::NONE;
        $entity->discountValue = (float) ($data['discount_value'] ?? 0);
        $entity->rewardType = RewardType::tryFrom($data['reward_type'] ?? 'NONE') ?? RewardType::NONE;
        $entity->rewardValue = (float) ($data['reward_value'] ?? 0);
        $entity->rewardReview = (int) ($data['reward_review'] ?? 0);
        $entity->allowedCoupon = (bool) ($data['allowed_coupon'] ?? true);

        // 재고/옵션
        $entity->stockQuantity = isset($data['stock_quantity']) && $data['stock_quantity'] !== null
            ? (int) $data['stock_quantity']
            : null;
        $entity->optionMode = OptionMode::tryFrom($data['option_mode'] ?? 'NONE') ?? OptionMode::NONE;

        // 배송
        $entity->shippingTemplateId = isset($data['shipping_template_id']) ? (int) $data['shipping_template_id'] : null;
        $entity->shippingApplyType = $data['shipping_apply_type'] ?? 'COMBINED';

        // 판매자/공급자
        $entity->sellerId = $data['seller_id'] ?? null;
        $entity->supplyId = $data['supply_id'] ?? null;

        // 통계
        $entity->hit = (int) ($data['hit'] ?? 0);

        // 상태
        $entity->isActive = (bool) ($data['is_active'] ?? true);

        // 시간
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
            'goods_id' => $this->goodsId,
            'domain_id' => $this->domainId,
            'category_code' => $this->categoryCode,
            'category_code_extra' => $this->categoryCodeExtra,
            'item_code' => $this->itemCode,
            'goods_name' => $this->goodsName,
            'goods_slug' => $this->goodsSlug,
            'goods_origin' => $this->goodsOrigin,
            'goods_manufacturer' => $this->goodsManufacturer,
            'goods_code' => $this->goodsCode,
            'goods_badge' => $this->goodsBadge,
            'goods_icon' => $this->goodsIcon,
            'goods_filter' => $this->goodsFilter,
            'goods_tags' => $this->goodsTags,
            'origin_price' => $this->originPrice,
            'display_price' => $this->displayPrice,
            'discount_type' => $this->discountType->value,
            'discount_value' => $this->discountValue,
            'reward_type' => $this->rewardType->value,
            'reward_value' => $this->rewardValue,
            'reward_review' => $this->rewardReview,
            'allowed_coupon' => $this->allowedCoupon,
            'stock_quantity' => $this->stockQuantity,
            'option_mode' => $this->optionMode->value,
            'shipping_template_id' => $this->shippingTemplateId,
            'shipping_apply_type' => $this->shippingApplyType,
            'seller_id' => $this->sellerId,
            'supply_id' => $this->supplyId,
            'hit' => $this->hit,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getGoodsId(): int
    {
        return $this->goodsId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getCategoryCode(): ?string
    {
        return $this->categoryCode;
    }

    public function getCategoryCodeExtra(): ?string
    {
        return $this->categoryCodeExtra;
    }

    public function getItemCode(): string
    {
        return $this->itemCode;
    }

    public function getGoodsName(): string
    {
        return $this->goodsName;
    }

    public function getGoodsSlug(): ?string
    {
        return $this->goodsSlug;
    }

    public function getGoodsOrigin(): ?string
    {
        return $this->goodsOrigin;
    }

    public function getGoodsManufacturer(): ?string
    {
        return $this->goodsManufacturer;
    }

    public function getGoodsCode(): ?string
    {
        return $this->goodsCode;
    }

    public function getGoodsBadge(): ?string
    {
        return $this->goodsBadge;
    }

    public function getGoodsIcon(): ?string
    {
        return $this->goodsIcon;
    }

    public function getGoodsFilter(): ?string
    {
        return $this->goodsFilter;
    }

    public function getGoodsTags(): ?string
    {
        return $this->goodsTags;
    }

    public function getOriginPrice(): int
    {
        return $this->originPrice;
    }

    public function getDisplayPrice(): int
    {
        return $this->displayPrice;
    }

    public function getDiscountType(): DiscountType
    {
        return $this->discountType;
    }

    public function getDiscountValue(): float
    {
        return $this->discountValue;
    }

    public function getRewardType(): RewardType
    {
        return $this->rewardType;
    }

    public function getRewardValue(): float
    {
        return $this->rewardValue;
    }

    public function getRewardReview(): int
    {
        return $this->rewardReview;
    }

    public function isAllowedCoupon(): bool
    {
        return $this->allowedCoupon;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function getOptionMode(): OptionMode
    {
        return $this->optionMode;
    }

    public function getShippingTemplateId(): ?int
    {
        return $this->shippingTemplateId;
    }

    public function getShippingApplyType(): string
    {
        return $this->shippingApplyType;
    }

    public function getSellerId(): ?string
    {
        return $this->sellerId;
    }

    public function getSupplyId(): ?string
    {
        return $this->supplyId;
    }

    public function getHit(): int
    {
        return $this->hit;
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
     * 할인 적용 여부
     */
    public function hasDiscount(): bool
    {
        return $this->discountType->isApplicable() && $this->discountValue > 0;
    }

    /**
     * 적립금 적용 여부
     */
    public function hasReward(): bool
    {
        return $this->rewardType->isApplicable() && $this->rewardValue > 0;
    }

    /**
     * 옵션 사용 여부
     */
    public function hasOptions(): bool
    {
        return $this->optionMode->hasOptions();
    }

    /**
     * 재고 있음 여부
     */
    public function isInStock(): bool
    {
        return $this->stockQuantity === null || $this->stockQuantity > 0;
    }
}
