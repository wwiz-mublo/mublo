<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Api\DTO;

/**
 * Extension API용 readonly 주문 값 객체.
 *
 * 주문자·수령인·주소·연락처 등 개인정보는 의도적으로 포함하지 않는다.
 */
final readonly class OrderSnapshot
{
    public function __construct(
        private string $orderNo,
        private int $domainId,
        private int $memberId,
        private string $status,
        private int $totalPrice,
        private int $finalAmount,
        private string $paymentMethod,
        private bool $complete,
        private string $createdAt
    ) {
    }

    public function getOrderNo(): string
    {
        return $this->orderNo;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTotalPrice(): int
    {
        return $this->totalPrice;
    }

    public function getFinalAmount(): int
    {
        return $this->finalAmount;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
}
