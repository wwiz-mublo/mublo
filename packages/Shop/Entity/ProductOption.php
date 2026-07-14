<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Packages\Shop\Enum\OptionType;

/**
 * ProductOption Entity
 *
 * 상품 옵션 엔티티 (shop_product_options 테이블)
 *
 * 책임:
 * - shop_product_options 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class ProductOption
{
    protected int $optionId;
    protected int $goodsId;
    protected string $optionName;
    protected OptionType $optionType;
    protected bool $isRequired;
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

        $entity->optionId = (int) ($data['option_id'] ?? 0);
        $entity->goodsId = (int) ($data['goods_id'] ?? 0);
        $entity->optionName = $data['option_name'] ?? '';
        $entity->optionType = OptionType::tryFrom($data['option_type'] ?? 'BASIC') ?? OptionType::BASIC;
        $entity->isRequired = (bool) ($data['is_required'] ?? true);
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
            'option_id' => $this->optionId,
            'goods_id' => $this->goodsId,
            'option_name' => $this->optionName,
            'option_type' => $this->optionType->value,
            'is_required' => $this->isRequired,
            'sort_order' => $this->sortOrder,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getOptionId(): int
    {
        return $this->optionId;
    }

    public function getGoodsId(): int
    {
        return $this->goodsId;
    }

    public function getOptionName(): string
    {
        return $this->optionName;
    }

    public function getOptionType(): OptionType
    {
        return $this->optionType;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
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
     * 기본 옵션 여부
     */
    public function isBasic(): bool
    {
        return $this->optionType === OptionType::BASIC;
    }

    /**
     * 추가 옵션 여부
     */
    public function isExtra(): bool
    {
        return $this->optionType === OptionType::EXTRA;
    }
}
