<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

use Mublo\Core\Event\AbstractEvent;

/**
 * 전환 발생 이벤트 — 모든 패키지가 공통으로 사용하는 중립 계약.
 *
 * 발행자: 각 소스 서비스 (Rental Order/Consultation, Member 등)
 * 구독자: VisitorStats\Subscriber\ConversionRecorderSubscriber
 *
 * 이 이벤트는 "전환이 일어났다"는 사실만 알린다.
 * VisitorStats 가 설치되지 않은 환경에서는 구독자가 없어 graceful 하게 무시된다.
 */
class ConversionRecordedEvent extends AbstractEvent
{
    public function __construct(
        public readonly int $domainId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly ?string $campaignKey,
        public readonly string $status = 'success',
        public readonly ?int $memberId = null,
        public readonly ?float $valueAmount = null,
        public readonly string $currency = 'KRW',
        public readonly ?string $sourceLabel = null,
        public readonly ?string $occurredAt = null,
    ) {}
}
