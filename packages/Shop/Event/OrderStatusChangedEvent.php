<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Shop\Enum\OrderAction;

/**
 * OrderStatusChangedEvent
 *
 * 주문 상태 변경 시 발행되는 이벤트
 *
 * 구독자가 활용할 수 있는 정보:
 * - prevStateId/newStateId: Config의 상태 id
 * - prevLabel/newLabel: 변경 시점의 라벨 스냅샷
 * - prevAction/newAction: 시스템 액션 Enum (커스텀이면 null)
 * - order: 주문 전체 데이터
 */
class OrderStatusChangedEvent extends AbstractEvent
{
    public function __construct(
        private readonly string $orderNo,
        private readonly string $prevStateId,
        private readonly string $newStateId,
        private readonly string $prevLabel,
        private readonly string $newLabel,
        private readonly ?OrderAction $prevAction,
        private readonly ?OrderAction $newAction,
        private readonly array $order,
        private readonly ?string $eventId = null,
    ) {}

    public function getOrderNo(): string
    {
        return $this->orderNo;
    }

    public function getPrevStateId(): string
    {
        return $this->prevStateId;
    }

    public function getNewStateId(): string
    {
        return $this->newStateId;
    }

    public function getPrevLabel(): string
    {
        return $this->prevLabel;
    }

    public function getNewLabel(): string
    {
        return $this->newLabel;
    }

    public function getPrevAction(): ?OrderAction
    {
        return $this->prevAction;
    }

    public function getNewAction(): ?OrderAction
    {
        return $this->newAction;
    }

    public function getOrder(): array
    {
        return $this->order;
    }

    /** DB 주문 로그를 기반으로 한 상태 전이 발생 식별자. */
    public function getEventId(): ?string
    {
        return $this->eventId;
    }
}
