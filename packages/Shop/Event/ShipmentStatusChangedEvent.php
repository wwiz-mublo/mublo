<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

class ShipmentStatusChangedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $shipmentId,
        private readonly string $orderNo,
        private readonly string $previousStatus,
        private readonly string $newStatus,
        private readonly array $shipment,
    ) {}

    public function getShipmentId(): int { return $this->shipmentId; }
    public function getOrderNo(): string { return $this->orderNo; }
    public function getPreviousStatus(): string { return $this->previousStatus; }
    public function getNewStatus(): string { return $this->newStatus; }
    public function getShipment(): array { return $this->shipment; }
}
