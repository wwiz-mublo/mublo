<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Entity;

use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Enum\PaymentMethod;

/**
 * Order Entity
 *
 * 주문 엔티티
 *
 * 책임:
 * - shop_orders 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class Order
{
    protected string $orderNo;
    protected int $domainId;
    protected ?string $cartSessionId;
    protected int $memberId;

    // 금액
    protected int $totalPrice;
    protected int $extraPrice;
    protected int $pointUsed;
    protected int $couponDiscount;
    protected ?int $couponId;
    protected ?string $couponBreakdown;
    protected int $shippingFee;
    protected ?string $shippingBreakdown;
    protected int $taxAmount;

    // 주문인
    protected ?string $ordererName;
    protected ?string $ordererPhone;
    protected ?string $ordererEmail;

    // 배송지
    protected ?string $shippingZip;
    protected ?string $shippingAddress1;
    protected ?string $shippingAddress2;
    protected ?string $recipientName;
    protected ?string $recipientPhone;

    // 결제
    protected ?string $paymentGateway;
    protected PaymentMethod $paymentMethod;
    protected ?string $bankAccountInfo; // 무통장입금 선택 계좌 (JSON)
    protected ?OrderAction $orderStatus;
    protected ?string $orderStatusRaw; // DB 원본값 (custom:{label} 포함)

    // 구매후기
    protected string $reviewStatus;
    protected int $reviewPoint;

    // 기타
    protected ?string $orderMemo;
    protected bool $isComplete;
    protected bool $isDirectOrder;

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

        $entity->orderNo = $data['order_no'] ?? '';
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->cartSessionId = $data['cart_session_id'] ?? null;
        $entity->memberId = (int) ($data['member_id'] ?? 0);

        // 금액
        $entity->totalPrice = (int) ($data['total_price'] ?? 0);
        $entity->extraPrice = (int) ($data['extra_price'] ?? 0);
        $entity->pointUsed = (int) ($data['point_used'] ?? 0);
        $entity->couponDiscount = (int) ($data['coupon_discount'] ?? 0);
        $entity->couponId = isset($data['coupon_id']) ? (int) $data['coupon_id'] : null;
        $entity->couponBreakdown = $data['coupon_breakdown'] ?? null;
        $entity->shippingFee = (int) ($data['shipping_fee'] ?? 0);
        $entity->shippingBreakdown = $data['shipping_breakdown'] ?? null;
        $entity->taxAmount = (int) ($data['tax_amount'] ?? 0);

        // 주문인
        $entity->ordererName = $data['orderer_name'] ?? null;
        $entity->ordererPhone = $data['orderer_phone'] ?? null;
        $entity->ordererEmail = $data['orderer_email'] ?? null;

        // 배송지
        $entity->shippingZip = $data['shipping_zip'] ?? null;
        $entity->shippingAddress1 = $data['shipping_address1'] ?? null;
        $entity->shippingAddress2 = $data['shipping_address2'] ?? null;
        $entity->recipientName = $data['recipient_name'] ?? null;
        $entity->recipientPhone = $data['recipient_phone'] ?? null;

        // 결제
        $entity->paymentGateway = $data['payment_gateway'] ?? null;
        $entity->paymentMethod = PaymentMethod::tryFrom($data['payment_method'] ?? 'BANK') ?? PaymentMethod::BANK;
        $entity->bankAccountInfo = $data['bank_account_info'] ?? null;
        $entity->orderStatusRaw = $data['order_status'] ?? null;
        $entity->orderStatus = isset($data['order_status'])
            ? (OrderAction::tryFrom($data['order_status']) ?? null)
            : null;

        // 구매후기
        $entity->reviewStatus = $data['review_status'] ?? 'NONE';
        $entity->reviewPoint = (int) ($data['review_point'] ?? 0);

        // 기타
        $entity->orderMemo = $data['order_memo'] ?? null;
        $entity->isComplete = (bool) ($data['is_complete'] ?? false);
        $entity->isDirectOrder = (bool) ($data['is_direct_order'] ?? false);

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
            'order_no' => $this->orderNo,
            'domain_id' => $this->domainId,
            'cart_session_id' => $this->cartSessionId,
            'member_id' => $this->memberId,
            'orderer_name' => $this->ordererName,
            'orderer_phone' => $this->ordererPhone,
            'orderer_email' => $this->ordererEmail,
            'total_price' => $this->totalPrice,
            'extra_price' => $this->extraPrice,
            'point_used' => $this->pointUsed,
            'coupon_discount' => $this->couponDiscount,
            'coupon_id' => $this->couponId,
            'coupon_breakdown' => $this->couponBreakdown,
            'shipping_fee' => $this->shippingFee,
            'shipping_breakdown' => $this->shippingBreakdown,
            'tax_amount' => $this->taxAmount,
            'shipping_zip' => $this->shippingZip,
            'shipping_address1' => $this->shippingAddress1,
            'shipping_address2' => $this->shippingAddress2,
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'payment_gateway' => $this->paymentGateway,
            'payment_method' => $this->paymentMethod->value,
            'bank_account_info' => $this->bankAccountInfo,
            'order_status' => $this->orderStatusRaw ?? $this->orderStatus?->value,
            'review_status' => $this->reviewStatus,
            'review_point' => $this->reviewPoint,
            'order_memo' => $this->orderMemo,
            'is_complete' => $this->isComplete,
            'is_direct_order' => $this->isDirectOrder,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // === Getters ===

    public function getOrderNo(): string
    {
        return $this->orderNo;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getCartSessionId(): ?string
    {
        return $this->cartSessionId;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getOrdererName(): ?string
    {
        return $this->ordererName;
    }

    public function getOrdererPhone(): ?string
    {
        return $this->ordererPhone;
    }

    public function getOrdererEmail(): ?string
    {
        return $this->ordererEmail;
    }

    public function getTotalPrice(): int
    {
        return $this->totalPrice;
    }

    public function getExtraPrice(): int
    {
        return $this->extraPrice;
    }

    public function getPointUsed(): int
    {
        return $this->pointUsed;
    }

    public function getCouponDiscount(): int
    {
        return $this->couponDiscount;
    }

    public function getCouponId(): ?int
    {
        return $this->couponId;
    }

    public function getCouponBreakdown(): ?string
    {
        return $this->couponBreakdown;
    }

    public function getShippingFee(): int
    {
        return $this->shippingFee;
    }

    public function getShippingBreakdown(): ?string
    {
        return $this->shippingBreakdown;
    }

    public function getTaxAmount(): int
    {
        return $this->taxAmount;
    }

    public function getShippingZip(): ?string
    {
        return $this->shippingZip;
    }

    public function getShippingAddress1(): ?string
    {
        return $this->shippingAddress1;
    }

    public function getShippingAddress2(): ?string
    {
        return $this->shippingAddress2;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function getRecipientPhone(): ?string
    {
        return $this->recipientPhone;
    }

    public function getPaymentGateway(): ?string
    {
        return $this->paymentGateway;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getOrderStatus(): ?OrderAction
    {
        return $this->orderStatus;
    }

    /**
     * DB 원본 상태값 (custom:{label} 형식 포함)
     */
    public function getOrderStatusRaw(): ?string
    {
        return $this->orderStatusRaw;
    }

    public function getReviewStatus(): string
    {
        return $this->reviewStatus;
    }

    public function getReviewPoint(): int
    {
        return $this->reviewPoint;
    }

    public function getOrderMemo(): ?string
    {
        return $this->orderMemo;
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
     * 주문 완료 여부
     */
    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    /**
     * 바로구매 주문 여부
     */
    public function isDirectOrder(): bool
    {
        return $this->isDirectOrder;
    }

    /**
     * 최종 결제 금액 산출
     *
     * total_price + extra_price + shipping_fee + tax_amount - point_used - coupon_discount
     * (PriceCalculator::calculatePaymentAmount()와 동일 공식)
     */
    public function getFinalAmount(): int
    {
        return $this->totalPrice
            + $this->extraPrice
            + $this->shippingFee
            + $this->taxAmount
            - $this->pointUsed
            - $this->couponDiscount;
    }
}
