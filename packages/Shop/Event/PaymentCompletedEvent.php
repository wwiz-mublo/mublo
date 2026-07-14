<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * PaymentCompletedEvent
 *
 * 필수 내부 후처리까지 완료된 뒤 발행되는 외부 확장용 이벤트.
 * 내부 장바구니·쿠폰·포인트 처리는 PaymentCompletionService가 단일 소유한다.
 *
 * 구독자 활용 예:
 * - 주문 확인 이메일/알림 발송
 * - 분석·CRM·외부 회계 시스템 동기화
 *
 * 재시도 시 같은 eventId가 전달될 수 있으므로 외부 소비자는 eventId로 중복을 제거해야 한다.
 */
class PaymentCompletedEvent extends AbstractEvent
{
    private readonly string $occurredAt;

    public function __construct(
        private readonly string $orderNo,
        private readonly string $pgKey,
        private readonly string $transactionId,
        private readonly array $verifyData,
        private readonly string $eventId = '',
        private readonly int $domainId = 0,
        string $occurredAt = '',
        private readonly int $contractVersion = 1,
    ) {
        $this->occurredAt = $occurredAt !== '' ? $occurredAt : date('c');
    }

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

    public function getVerifyData(): array
    {
        return $this->verifyData;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    public function getContractVersion(): int
    {
        return $this->contractVersion;
    }
}
