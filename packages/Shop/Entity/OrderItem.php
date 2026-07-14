<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Packages\Shop\Enum\OptionMode;
use Mublo\Packages\Shop\Enum\OptionType;
use Mublo\Packages\Shop\Enum\OrderAction;

/**
 * OrderItem Entity
 *
 * 주문 상세(주문 항목) 엔티티
 *
 * 책임:
 * - shop_order_details 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class OrderItem
{
    protected int $orderDetailId;
    protected string $orderNo;
    protected int $goodsId;

    // 옵션
    protected OptionMode $optionMode;
    protected int $optionId;
    protected ?string $optionCode;
    protected OptionType $optionType;

    // 금액
    protected int $goodsPrice;
    protected int $optionPrice;
    protected int $supplyPrice;
    protected int $totalPrice;
    protected int $quantity;
    protected int $pointAmount;
    protected int $couponDiscount;
    protected ?int $couponId;

    // 담당
    protected ?int $supplyId;
    protected ?int $staffId;

    // 상태
    protected ?OrderAction $status;
    protected ?string $statusRaw;
    protected bool $isPaid;
    protected bool $isPreparing;
    protected bool $isShipped;
    protected bool $isCompleted;

    // 반품/교환
    protected string $returnType;
    protected string $returnStatus;

    // 시간
    protected ?string $paidAt;
    protected string $createdAt;
    protected ?string $updatedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->orderDetailId = (int) ($data['order_detail_id'] ?? 0);
        $entity->orderNo = $data['order_no'] ?? '';
        $entity->goodsId = (int) ($data['goods_id'] ?? 0);

        // 옵션
        $entity->optionMode = OptionMode::tryFrom($data['option_mode'] ?? 'NONE') ?? OptionMode::NONE;
        $entity->optionId = (int) ($data['option_id'] ?? 0);
        $entity->optionCode = $data['option_code'] ?? null;
        $entity->optionType = OptionType::tryFrom($data['option_type'] ?? 'BASIC') ?? OptionType::BASIC;

        // 금액
        $entity->goodsPrice = (int) ($data['goods_price'] ?? 0);
        $entity->optionPrice = (int) ($data['option_price'] ?? 0);
        $entity->supplyPrice = (int) ($data['supply_price'] ?? 0);
        $entity->totalPrice = (int) ($data['total_price'] ?? 0);
        $entity->quantity = (int) ($data['quantity'] ?? 1);
        $entity->pointAmount = (int) ($data['point_amount'] ?? 0);
        $entity->couponDiscount = (int) ($data['coupon_discount'] ?? 0);
        $entity->couponId = isset($data['coupon_id']) ? (int) $data['coupon_id'] : null;

        // 담당
        $entity->supplyId = isset($data['supply_id']) ? (int) $data['supply_id'] : null;
        $entity->staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;

        // 상태
        $entity->statusRaw = $data['status'] ?? null;
        $entity->status = isset($data['status'])
            ? (OrderAction::tryFrom($data['status']) ?? null)
            : null;
        $entity->isPaid = (bool) ($data['is_paid'] ?? false);
        $entity->isPreparing = (bool) ($data['is_preparing'] ?? false);
        $entity->isShipped = (bool) ($data['is_shipped'] ?? false);
        $entity->isCompleted = (bool) ($data['is_completed'] ?? false);

        // 반품/교환
        $entity->returnType = $data['return_type'] ?? 'NONE';
        $entity->returnStatus = $data['return_status'] ?? 'NONE';

        // 시간
        $entity->paidAt = $data['paid_at'] ?? null;
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
            'order_detail_id' => $this->orderDetailId,
            'order_no' => $this->orderNo,
            'goods_id' => $this->goodsId,
            'option_mode' => $this->optionMode->value,
            'option_id' => $this->optionId,
            'option_code' => $this->optionCode,
            'option_type' => $this->optionType->value,
            'goods_price' => $this->goodsPrice,
            'option_price' => $this->optionPrice,
            'supply_price' => $this->supplyPrice,
            'total_price' => $this->totalPrice,
            'quantity' => $this->quantity,
            'point_amount' => $this->pointAmount,
            'coupon_discount' => $this->couponDiscount,
            'coupon_id' => $this->couponId,
            'supply_id' => $this->supplyId,
            'staff_id' => $this->staffId,
            'status' => $this->statusRaw ?? $this->status?->value,
            'is_paid' => $this->isPaid,
            'is_preparing' => $this->isPreparing,
            'is_shipped' => $this->isShipped,
            'is_completed' => $this->isCompleted,
            'return_type' => $this->returnType,
            'return_status' => $this->returnStatus,
            'paid_at' => $this->paidAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getOrderDetailId(): int
    {
        return $this->orderDetailId;
    }

    public function getOrderNo(): string
    {
        return $this->orderNo;
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

    public function getSupplyPrice(): int
    {
        return $this->supplyPrice;
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

    public function getCouponDiscount(): int
    {
        return $this->couponDiscount;
    }

    public function getCouponId(): ?int
    {
        return $this->couponId;
    }

    public function getSupplyId(): ?int
    {
        return $this->supplyId;
    }

    public function getStaffId(): ?int
    {
        return $this->staffId;
    }

    public function getStatus(): ?OrderAction
    {
        return $this->status;
    }

    public function getStatusRaw(): ?string
    {
        return $this->statusRaw;
    }

    public function isPaid(): bool
    {
        return $this->isPaid;
    }

    public function isPreparing(): bool
    {
        return $this->isPreparing;
    }

    public function isShipped(): bool
    {
        return $this->isShipped;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function getReturnType(): string
    {
        return $this->returnType;
    }

    public function getReturnStatus(): string
    {
        return $this->returnStatus;
    }

    public function getPaidAt(): ?string
    {
        return $this->paidAt;
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
