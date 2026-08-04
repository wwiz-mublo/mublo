<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Enum\ClaimStatus;

final class ClaimStateMachine
{
    /** @var array<string, string[]> */
    private const TRANSITIONS = [
        'REQUESTED' => ['ACCEPTED', 'REFUSED', 'CANCELLED'],
        'ACCEPTED' => ['COLLECTING', 'CANCELLED'],
        'COLLECTING' => ['COLLECTED'],
        'COLLECTED' => ['INSPECTING'],
        'INSPECTING' => ['READY_TO_SHIP', 'REJECTED'],
        'READY_TO_SHIP' => ['RESHIPPING'],
        'RESHIPPING' => ['COMPLETED'],
        'REJECTED' => ['RETURNING'],
        'RETURNING' => ['CLOSED'],
        'COMPLETED' => [],
        'REFUSED' => [],
        'CANCELLED' => [],
        'CLOSED' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true)
            && ClaimStatus::tryFrom($to) !== null;
    }

    /** @return ClaimStatus[] */
    public function next(string $from): array
    {
        return array_values(array_filter(array_map(
            static fn(string $status): ?ClaimStatus => ClaimStatus::tryFrom($status),
            self::TRANSITIONS[$from] ?? []
        )));
    }
}
