<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Event\PaymentMismatchEvent;
use Mublo\Packages\Shop\Service\OrderMemoService;

/**
 * PaymentMismatchSubscriber
 *
 * 결제는 완료되었으나 주문 상태 전이(→PAID)가 실패한 최악의 상황을 운영자에게 확실히 노출한다.
 * 기존에는 error_log로만 남아 아무도 보지 못했다(구독자 부재). 이제:
 *  - 주문에 INTERNAL 메모를 남겨 관리자 주문 상세에서 즉시 확인·수동 결제완료 처리가 가능하게 한다.
 *  - Logger가 있으면 구조화된 critical 로그로도 남긴다(외부 알림/모니터링 연동 지점).
 *
 * 결제 검증 경로(PaymentService::finalizeVerifiedPayment) 안에서 동기 발행되므로,
 * 이 핸들러의 예외가 결제 검증 응답 자체를 깨지 않도록 방어적으로 처리한다.
 */
class PaymentMismatchSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OrderMemoService $memoService,
        private ?Logger $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            PaymentMismatchEvent::class => 'onPaymentMismatch',
        ];
    }

    public function onPaymentMismatch(PaymentMismatchEvent $event): void
    {
        $orderNo = $event->getOrderNo();
        $message = sprintf(
            "[결제-상태 불일치] PG 결제는 완료되었으나 주문 상태가 '결제완료'로 전이되지 못했습니다.\n"
            . "PG=%s, TXN=%s, 사유=%s\n"
            . '결제 승인 여부를 PG에서 확인한 뒤, 실제 결제된 주문이면 수동으로 결제완료 처리해주세요.',
            $event->getPgKey(),
            $event->getTransactionId(),
            $event->getFailureReason()
        );

        // 관리자 주문 상세에 노출되는 시스템 메모 (staff_id=0 = 시스템)
        try {
            $this->memoService->addMemo($orderNo, $message, 'INTERNAL', 0);
        } catch (\Throwable $e) {
            $this->logger?->error('[Shop] 결제 불일치 메모 기록 실패', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger?->critical('[Shop] 결제-상태 불일치 — 관리자 개입 필요', [
            'order_no' => $orderNo,
            'pg_key' => $event->getPgKey(),
            'transaction_id' => $event->getTransactionId(),
            'reason' => $event->getFailureReason(),
        ]);
    }
}
