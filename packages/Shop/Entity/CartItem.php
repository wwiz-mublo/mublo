<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Entity\BaseEntity;
use Mublo\Packages\Shop\Enum\OptionMode;
use Mublo\Packages\Shop\Enum\OptionType;

/**
 * CartItem Entity
 *
 * 쇼핑몰 장바구니 아이템 엔티티 (shop_carts 테이블)
 *
 * 책임:
 * - shop_carts 테이블의 데이터를 객체로 표현
 * - 장바구니 상태/가격 판단 메서드 제공
 *
 * 금지:
 * - DB 직접 접근
 */
class CartItem extends BaseEntity
{
    // ========================================
    // 기본 정보
    // ========================================
    protected int $cartItemId = 0;
    protected string $cartSessionId = '';
    protected int $memberId = 0;
    protected int $goodsId = 0;

    // ========================================
    // 옵션 정보
    // ========================================
    protected OptionMode $optionMode;
    protected int $optionId = 0;
    protected ?string $optionCode = null;
    protected OptionType $optionType;

    // ========================================
    // 가격/수량
    // ========================================
    protected int $goodsPrice = 0;
    protected int $optionPrice = 0;
    protected int $totalPrice = 0;
    protected int $quantity = 1;
    protected int $pointAmount = 0;

    // ========================================
    // 상태
    // ========================================
    protected string $cartStatus = 'PENDING';

    // ========================================
    // 상수
    // ========================================
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ORDERED = 'ORDERED';

    /**
     * Private constructor - fromArray() 사용
     */
    private function __construct()
    {
        $this->optionMode = OptionMode::NONE;
        $this->optionType = OptionType::BASIC;
    }

    /**
     * 기본키 필드명
     */
    protected function getPrimaryKeyField(): string
    {
        return 'cartItemId';
    }

    /**
     * DB 로우 데이터로부터 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        // 기본 정보
        $entity->cartItemId = (int) ($data['cart_item_id'] ?? 0);
        $entity->cartSessionId = $data['cart_session_id'] ?? '';
        $entity->memberId = (int) ($data['member_id'] ?? 0);
        $entity->goodsId = (int) ($data['goods_id'] ?? 0);

        // 옵션 정보
        $entity->optionMode = OptionMode::tryFrom($data['option_mode'] ?? 'NONE') ?? OptionMode::NONE;
        $entity->optionId = (int) ($data['option_id'] ?? 0);
        $entity->optionCode = $data['option_code'] ?? null;
        $entity->optionType = OptionType::tryFrom($data['option_type'] ?? 'BASIC') ?? OptionType::BASIC;

        // 가격/수량
        $entity->goodsPrice = (int) ($data['goods_price'] ?? 0);
        $entity->optionPrice = (int) ($data['option_price'] ?? 0);
        $entity->totalPrice = (int) ($data['total_price'] ?? 0);
        $entity->quantity = (int) ($data['quantity'] ?? 1);
        $entity->pointAmount = (int) ($data['point_amount'] ?? 0);

        // 상태
        $entity->cartStatus = $data['cart_status'] ?? 'PENDING';

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
            'cart_item_id' => $this->cartItemId,
            'cart_session_id' => $this->cartSessionId,
            'member_id' => $this->memberId,
            'goods_id' => $this->goodsId,
            'option_mode' => $this->optionMode->value,
            'option_id' => $this->optionId,
            'option_code' => $this->optionCode,
            'option_type' => $this->optionType->value,
            'goods_price' => $this->goodsPrice,
            'option_price' => $this->optionPrice,
            'total_price' => $this->totalPrice,
            'quantity' => $this->quantity,
            'point_amount' => $this->pointAmount,
            'cart_status' => $this->cartStatus,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // ========================================
    // Getter 메서드
    // ========================================

    public function getCartItemId(): int
    {
        return $this->cartItemId;
    }

    public function getCartSessionId(): string
    {
        return $this->cartSessionId;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getGoodsId(): int
    {
        return $this->goodsId;
    }

    public function getOptionMode(): OptionMode
    {
        return $this->optionMode;
    }

    public function getOptionId(): int
    {
        return $this->optionId;
    }

    public function getOptionCode(): ?string
    {
        return $this->optionCode;
    }

    public function getOptionType(): OptionType
    {
        return $this->optionType;
    }

    public function getGoodsPrice(): int
    {
        return $this->goodsPrice;
    }

    public function getOptionPrice(): int
    {
        return $this->optionPrice;
    }

    public function getTotalPrice(): int
    {
        return $this->totalPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getPointAmount(): int
    {
        return $this->pointAmount;
    }

    public function getCartStatus(): string
    {
        return $this->cartStatus;
    }

    // ========================================
    // 상태 판단 메서드
    // ========================================

    /**
     * 대기 상태 여부
     */
    public function isPending(): bool
    {
        return $this->cartStatus === self::STATUS_PENDING;
    }

    /**
     * 주문 완료 상태 여부
     */
    public function isOrdered(): bool
    {
        return $this->cartStatus === self::STATUS_ORDERED;
    }

    /**
     * 회원 장바구니인지 여부
     */
    public function isMemberCart(): bool
    {
        return $this->memberId > 0;
    }

    /**
     * 비회원 장바구니인지 여부
     */
    public function isGuestCart(): bool
    {
        return $this->memberId === 0;
    }

    /**
     * 옵션이 있는지 여부
     */
    public function hasOption(): bool
    {
        return $this->optionMode->hasOptions();
    }

    /**
     * 추가 옵션인지 여부
     */
    public function isExtraOption(): bool
    {
        return $this->optionType === OptionType::EXTRA;
    }

    /**
     * 옵션 가격이 있는지 여부
     */
    public function hasOptionPrice(): bool
    {
        return $this->optionPrice !== 0;
    }

    /**
     * 적립 포인트가 있는지 여부
     */
    public function hasPoint(): bool
    {
        return $this->pointAmount > 0;
    }
}
