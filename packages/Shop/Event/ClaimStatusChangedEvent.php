<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

final class ClaimStatusChangedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $claimId,
        private readonly int $domainId,
        private readonly string $orderNo,
        private readonly int $orderDetailId,
        private readonly string $previousStatus,
        private readonly string $newStatus,
        private readonly array $claim,
    ) {}

    public function getClaimId(): int { return $this->claimId; }
    public function getDomainId(): int { return $this->domainId; }
    public function getOrderNo(): string { return $this->orderNo; }
    public function getOrderDetailId(): int { return $this->orderDetailId; }
    public function getPreviousStatus(): string { return $this->previousStatus; }
    public function getNewStatus(): string { return $this->newStatus; }
    public function getClaim(): array { return $this->claim; }
}
