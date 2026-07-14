<?php

namespace Mublo\Packages\Shop\Entity;

/**
 * Coupon Entity
 *
 * 발행된 쿠폰 엔티티
 *
 * 책임:
 * - shop_coupon_issue 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class Coupon
{
    protected int $couponId;
    protected int $couponGroupId;
    protected int $memberId;
    protected ?string $couponNumber;

    // 발행/유효기간
    protected string $issuedAt;
    protected string $validUntil;

    // 사용 정보
    protected bool $isUsed;
    protected ?string $usedAt;
    protected ?string $orderNo;
    protected int $usedAmount;

    // 상태
    protected string $status;
    protected ?int $staffId;

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

        $entity->couponId = (int) ($data['coupon_id'] ?? 0);
        $entity->couponGroupId = (int) ($data['coupon_group_id'] ?? 0);
        $entity->memberId = (int) ($data['member_id'] ?? 0);
        $entity->couponNumber = $data['coupon_number'] ?? null;

        // 발행/유효기간
        $entity->issuedAt = $data['issued_at'] ?? '';
        $entity->validUntil = $data['valid_until'] ?? '';

        // 사용 정보
        $entity->isUsed = (bool) ($data['is_used'] ?? false);
        $entity->usedAt = $data['used_at'] ?? null;
        $entity->orderNo = $data['order_no'] ?? null;
        $entity->usedAmount = (int) ($data['used_amount'] ?? 0);

        // 상태
        $entity->status = $data['status'] ?? 'ISSUED';
        $entity->staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;

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
            'coupon_id' => $this->couponId,
            'coupon_group_id' => $this->couponGroupId,
            'member_id' => $this->memberId,
            'coupon_number' => $this->couponNumber,
            'issued_at' => $this->issuedAt,
            'valid_until' => $this->validUntil,
            'is_used' => $this->isUsed,
            'used_at' => $this->usedAt,
            'order_no' => $this->orderNo,
            'used_amount' => $this->usedAmount,
            'status' => $this->status,
            'staff_id' => $this->staffId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getCouponId(): int
    {
        return $this->couponId;
    }

    public function getCouponGroupId(): int
    {
        return $this->couponGroupId;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getCouponNumber(): ?string
    {
        return $this->couponNumber;
    }

    public function getIssuedAt(): string
    {
        return $this->issuedAt;
    }

    public function getValidUntil(): string
    {
        return $this->validUntil;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    public function getUsedAt(): ?string
    {
        return $this->usedAt;
    }

    public function getOrderNo(): ?string
    {
        return $this->orderNo;
    }

    public function getUsedAmount(): int
    {
        return $this->usedAmount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStaffId(): ?int
    {
        return $this->staffId;
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
     * 유효기간 만료 여부
     */
    public function isExpired(): bool
    {
        if (empty($this->validUntil)) {
            return false;
        }

        return strtotime($this->validUntil) < time();
    }

    /**
     * 사용 가능 여부 (미사용 + 미만료)
     */
    public function isUsable(): bool
    {
        return !$this->isUsed && !$this->isExpired();
    }
}
