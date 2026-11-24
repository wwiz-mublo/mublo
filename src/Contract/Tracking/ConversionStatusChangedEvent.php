<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

use Mublo\Core\Event\AbstractEvent;

/**
 * 이미 기록된 전환의 상태만 바꾼다 (예: 접수된 주문이 완료 → success, 취소 → cancelled).
 *
 * ConversionRecordedEvent 를 다시 보내면 캠페인키·발생 시각까지 덮어쓰므로,
 * 상태 변경은 이 이벤트로 따로 보낸다. (domainId, sourceType, sourceId) 는 처음 기록할 때와
 * 같아야 하며, 기록되지 않은 전환이면 아무 일도 하지 않는다.
 *
 * 구독자: VisitorStats\Subscriber\ConversionRecorderSubscriber — 아래 상수와 'failed' 만 받는다.
 */
class ConversionStatusChangedEvent extends AbstractEvent
{
    public const SUCCESS = 'success';
    public const PENDING = 'pending';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $domainId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $status,
    ) {}
}
