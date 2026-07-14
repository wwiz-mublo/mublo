<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Shop\Enum\OrderAction;

/**
 * 주문상품 단위 상태 변경 이벤트.
 *
 * 주문 헤더 상태가 유지되는 부분 취소·부분 반품도 후처리할 수 있도록
 * OrderStatusChangedEvent와 별도로 발행한다.
 */
class OrderItemStatusChangedEvent extends AbstractEvent
{
    public function __construct(
        private readonly string $orderNo,
        private readonly int $orderDetailId,
        private readonly string $prevStateId,
        private readonly string $newStateId,
        private readonly string $prevLabel,
        private readonly string $newLabel,
        private readonly ?OrderAction $prevAction,
        private readonly ?OrderAction $newAction,
        private readonly array $order,
        private readonly array $item,
    ) {}

    public function getOrderNo(): string { return $this->orderNo; }
    public function getOrderDetailId(): int { return $this->orderDetailId; }
    public function getPrevStateId(): string { return $this->prevStateId; }
    public function getNewStateId(): string { return $this->newStateId; }
    public function getPrevLabel(): string { return $this->prevLabel; }
    public function getNewLabel(): string { return $this->newLabel; }
    public function getPrevAction(): ?OrderAction { return $this->prevAction; }
    public function getNewAction(): ?OrderAction { return $this->newAction; }
    public function getOrder(): array { return $this->order; }
    public function getItem(): array { return $this->item; }
}
