<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

class ShipmentDeletedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $shipmentId,
        private readonly string $orderNo,
        private readonly array $shipment,
    ) {}

    public function getShipmentId(): int { return $this->shipmentId; }
    public function getOrderNo(): string { return $this->orderNo; }
    public function getShipment(): array { return $this->shipment; }
}
