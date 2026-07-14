<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Repository\OrderRepository;

/**
 * Shop 주문에 대한 결제 소비자 계약 구현.
 *
 * PG 플러그인의 콜백이 Shop 서비스를 직접 붙잡지 않도록, 계약이 요구하는 세 동작만
 * 기존 서비스에 위임하는 얇은 어댑터다. 검증·상태 전이 로직은 전부 PaymentService·
 * RefundService 에 그대로 있고 여기서는 인자 모양만 계약에 맞춘다.
 */
class ShopPaymentConsumer implements PaymentConsumerInterface
{
    public function __construct(
        private PaymentService $paymentService,
        private RefundService $refundService,
        private OrderRepository $orderRepository,
        private PriceCalculator $priceCalculator,
    ) {
    }

    public function findPreparedPayment(int $domainId, string $orderNo): ?PreparedPayment
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return null;
        }

        $orderArray = $order->toArray();
        $status = (string) ($orderArray['order_status'] ?? '');

        return new PreparedPayment(
            status: $status,
            gateway: (string) ($orderArray['payment_gateway'] ?? ''),
            // 주문 행에 스냅샷된 값으로 계산한다 — 결제창이 돌려준 금액과 대조하는 기준
            amount: $this->priceCalculator->calculatePaymentAmount($orderArray),
            paid: $status === OrderAction::PAID->value,
        );
    }

    public function verifyPayment(int $domainId, string $orderNo, string $pgKey, string $transactionId): Result
    {
        return $this->paymentService->verifyPaymentByCallback($pgKey, $transactionId, $orderNo, $domainId);
    }

    public function recordExternalRefund(
        int $domainId,
        string $orderNo,
        string $pgKey,
        string $transactionId,
        int $amount,
        string $reason,
        bool $fullCancel,
    ): Result {
        return $this->refundService->recordExternalRefund(
            $orderNo,
            $amount,
            $pgKey,
            $transactionId,
            $reason,
            $domainId,
            $fullCancel,
        );
    }
}
