<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * PaymentMismatchEvent
 *
 * 결제는 완료되었으나 주문 상태 전이가 실패한 경우 발행
 *
 * 구독자 활용 예:
 * - 관리자 긴급 알림 (이메일/슬랙)
 * - 모니터링 대시보드 기록
 * - 자동 복구 시도
 */
class PaymentMismatchEvent extends AbstractEvent
{
    public function __construct(
        private readonly string $orderNo,
        private readonly string $pgKey,
        private readonly string $transactionId,
        private readonly string $failureReason,
    ) {}

    public function getOrderNo(): string
    {
        return $this->orderNo;
    }

    public function getPgKey(): string
    {
        return $this->pgKey;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }
}
