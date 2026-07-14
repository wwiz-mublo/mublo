<?php

namespace Mublo\Packages\Shop\Entity;

/**
 * ExhibitionItem (기획전 아이템)
 *
 * 기획전에 연결된 상품 또는 카테고리 단위 엔티티
 */
class ExhibitionItem
{
    private int $itemId;
    private int $exhibitionId;
    private string $targetType;   // 'goods' | 'category'
    private ?int $goodsId;
    private ?string $categoryCode;
    private int $sortOrder;
    private string $createdAt;

    private function __construct() {}

    public static function fromArray(array $data): self
    {
        $self = new self();
        $self->itemId        = (int) ($data['item_id'] ?? 0);
        $self->exhibitionId  = (int) ($data['exhibition_id'] ?? 0);
        $self->targetType    = (string) ($data['target_type'] ?? 'goods');
        $self->goodsId       = isset($data['goods_id']) ? (int) $data['goods_id'] : null;
        $self->categoryCode  = $data['category_code'] ?? null;
        $self->sortOrder     = (int) ($data['sort_order'] ?? 0);
        $self->createdAt     = (string) ($data['created_at'] ?? '');
        return $self;
    }

    public function toArray(): array
    {
        return [
            'item_id'       => $this->itemId,
            'exhibition_id' => $this->exhibitionId,
            'target_type'   => $this->targetType,
            'goods_id'      => $this->goodsId,
            'category_code' => $this->categoryCode,
            'sort_order'    => $this->sortOrder,
            'created_at'    => $this->createdAt,
        ];
    }

    public function isGoods(): bool    { return $this->targetType === 'goods'; }
    public function isCategory(): bool { return $this->targetType === 'category'; }

    public function getItemId(): int          { return $this->itemId; }
    public function getExhibitionId(): int    { return $this->exhibitionId; }
    public function getTargetType(): string   { return $this->targetType; }
    public function getGoodsId(): ?int        { return $this->goodsId; }
    public function getCategoryCode(): ?string { return $this->categoryCode; }
}
